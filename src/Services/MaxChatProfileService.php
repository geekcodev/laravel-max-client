<?php

declare(strict_types=1);

namespace GeekCo\LaravelMaxClient\Services;

use GeekCo\LaravelMaxClient\Enums\MaxChatStatus;
use GeekCo\LaravelMaxClient\Support\Config;
use GeekCo\LaravelMaxClient\Support\Logger;
use GeekCo\MaxPhpClient\ApiClient;
use GeekCo\MaxPhpClient\Dto\Chat;
use GeekCo\MaxPhpClient\Enum\ChatType;

/**
 * Метаданные чата: название, описание, ссылка, иконка.
 *
 * Единственный источник этих данных — GET /chats/{chat_id}: апдейты
 * bot_added/bot_started содержат только chat_id, user и is_channel, а title
 * приходит отдельным событием chat_title_changed. Поэтому название группы или
 * канала заполняется постфактум запросом, а не из апдейта.
 *
 * Реестр хранит одну строку на чат, поэтому ответ getChat пишется ровно в неё:
 * запрос на чат один, а не по одному на каждого участника группы. Непустой
 * ответ не затирает уже известное значение пустым: публичный канал без названия —
 * обычное дело.
 *
 * Класс кэширует ответ в памяти на время запроса (одна работа HandleMaxUpdateJob),
 * чтобы MaxUserProfileService и слушатели не ходили в API за одним и тем же чатом.
 */
final class MaxChatProfileService
{
    /** @var array<int, Chat> */
    private array $chatCache = [];

    public function __construct(
        private readonly ApiClient $api,
        private readonly Config $config,
        private readonly Logger $logger,
    ) {
    }

    /**
     * Записать метаданные чата в строку реестра.
     * chat_type дозаполняется только при неизвестном значении.
     */
    public function sync(int $chatId): bool
    {
        $chat = $this->fetch($chatId);

        if ($chat === null) {
            return false;
        }

        $model = $this->config->chatsModel();

        $model::query()
            ->where('chat_id', $chatId)
            ->whereNull('chat_type')
            ->update(['chat_type' => $chat->type]);

        $data = ['chat_checked_at' => now()];

        $title = $this->trimmedOrNull($chat->title);
        if ($title !== null) {
            $data['title'] = $title;
        }

        $description = $this->trimmedOrNull($chat->description);
        if ($description !== null) {
            $data['description'] = $description;
        }

        $link = $this->trimmedOrNull($chat->link);
        if ($link !== null) {
            $data['link'] = $link;
        }

        $iconUrl = $this->trimmedOrNull($chat->icon?->url);
        if ($iconUrl !== null) {
            $data['icon_url'] = $iconUrl;
        }

        $model::query()
            ->where('chat_id', $chatId)
            ->update($data);

        return true;
    }

    /**
     * Гарантировать, что метаданные чата записаны в реестр.
     *
     * Два состояния на chat_id:
     *   - название неизвестно — запросить getChat (один запрос на чат);
     *   - название известно — не ходить в API.
     *
     * Второе состояние нужно, чтобы не опрашивать getChat по каждому участнику
     * чата: группа может прислать bot_started от пятидесяти участников подряд, и
     * без проверки это пятьдесят одинаковых запросов.
     */
    public function ensureMetadata(int $chatId): bool
    {
        if ($this->hasKnownTitle($chatId)) {
            return true;
        }

        return $this->sync($chatId);
    }

    /**
     * Обновить метаданные указанных чатов или всех активных чатов реестра.
     * Точка входа для расписания и для дозаполнения существующих записей.
     *
     * @param int|list<int>|null $chatId null — все активные чаты реестра
     */
    public function refresh(int|array|null $chatId = null): bool
    {
        $ids = $this->pendingChatIds($chatId);

        if ($ids === []) {
            $this->logger->log('warning', 'MAX chat metadata refresh skipped: no chats to sync.', []);

            return false;
        }

        $updated = false;

        foreach ($ids as $id) {
            if ($this->sync($id)) {
                $updated = true;
            }
        }

        if ($updated) {
            $this->logger->log('info', 'MAX chat metadata refreshed.', [
                'chat_ids' => $ids,
            ]);
        }

        return $updated;
    }

    /**
     * Чаты, метаданные которых пора обновить: из запрошенных исключаются те,
     * у которых chat_checked_at свежее chats.chat_check_interval. Нулевой
     * интервал означает «проверять всегда» — так по умолчанию и работает
     * первичное дозаполнение.
     *
     * @param int|list<int>|null $chatId null — все активные чаты реестра
     *
     * @return list<int>
     */
    public function pendingChatIds(int|array|null $chatId = null): array
    {
        $ids = $this->normalizeChatIds($chatId ?? $this->activeChatIds());

        $interval = $this->config->chatsChatCheckInterval();

        if ($interval <= 0 || $ids === []) {
            return $ids;
        }

        $threshold = now()->subSeconds($interval);

        $freshIds = [];

        foreach ($this->config->chatsModel()::query()
            ->whereIn('chat_id', $ids)
            ->whereNotNull('chat_checked_at')
            ->where('chat_checked_at', '>=', $threshold)
            ->pluck('chat_id') as $value) {
            $id = filter_var($value, FILTER_VALIDATE_INT);

            if ($id !== false && $id !== 0) {
                $freshIds[] = $id;
            }
        }

        if ($freshIds === []) {
            return $ids;
        }

        $pending = array_values(array_diff($ids, $freshIds));
        $skipped = array_values(array_intersect($ids, $freshIds));

        $this->logger->log('info', 'MAX chat metadata refresh skipped recently checked chats.', [
            'chat_ids' => $skipped,
            'interval' => $interval,
        ]);

        return $pending;
    }

    /**
     * Чат из API с кэшем на время работы. При ошибке — предупреждение в лог и null.
     */
    public function fetch(int $chatId): ?Chat
    {
        if (isset($this->chatCache[$chatId])) {
            return $this->chatCache[$chatId];
        }

        try {
            return $this->chatCache[$chatId] = $this->api->getChat($chatId);
        } catch (\Throwable $e) {
            $this->logger->log('warning', 'MAX getChat failed.', [
                'chat_id' => $chatId,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Тип чата: из реестра max_chats.chat_type, при неизвестном — getChat() с
     * записью в реестр (по chat_id, без перетирания известного).
     */
    public function chatTypeFor(int $chatId): ?ChatType
    {
        $chat = $this->config->chatsModel()::query()
            ->where('chat_id', $chatId)
            ->first();

        if ($chat !== null && $chat->chat_type instanceof ChatType) {
            return $chat->chat_type;
        }

        $resolved = $this->fetch($chatId);

        if ($resolved === null) {
            return null;
        }

        $this->config->chatsModel()::query()
            ->where('chat_id', $chatId)
            ->whereNull('chat_type')
            ->update(['chat_type' => $resolved->type]);

        return $resolved->type;
    }

    /**
     * Сбросить кэш ответов getChat. Вызывается между работами, где актуальность
     * важнее одного лишнего запроса (например, из расписания).
     */
    public function forgetChatCache(): void
    {
        $this->chatCache = [];
    }

    /**
     * Известно ли название чата в реестре.
     */
    public function hasKnownTitle(int $chatId): bool
    {
        return $this->config->chatsModel()::query()
            ->where('chat_id', $chatId)
            ->whereNotNull('title')
            ->where('title', '!=', '')
            ->exists();
    }

    /**
     * Активные чаты реестра. Одна строка на чат, поэтому distinct не нужен.
     *
     * @return list<int>
     */
    public function activeChatIds(): array
    {
        $model = $this->config->chatsModel();

        $ids = [];

        foreach ($model::query()
            ->where('status', MaxChatStatus::Active)
            ->pluck('chat_id') as $chatId) {
            $id = filter_var($chatId, FILTER_VALIDATE_INT);

            if ($id !== false && $id !== 0 && !\in_array($id, $ids, true)) {
                $ids[] = $id;
            }
        }

        return $ids;
    }

    /**
     * @param int|list<int> $chatId
     *
     * @return list<int>
     */
    private function normalizeChatIds(int|array $chatId): array
    {
        $ids = \is_array($chatId) ? $chatId : [$chatId];

        return array_values(array_unique(array_filter(
            $ids,
            static fn (int $id): bool => $id !== 0,
        )));
    }

    private function trimmedOrNull(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }
}
