<?php

declare(strict_types=1);

namespace GeekCo\LaravelMaxClient\Listeners;

use GeekCo\LaravelMaxClient\Enums\MaxChatStatus;
use GeekCo\LaravelMaxClient\Services\MaxChatProfileService;
use GeekCo\LaravelMaxClient\Support\Config;
use GeekCo\LaravelMaxClient\Webhook\MaxUpdateReceived;
use GeekCo\MaxPhpClient\Dto\Update;
use GeekCo\MaxPhpClient\Dto\User;
use GeekCo\MaxPhpClient\Enum\ChatType;
use GeekCo\MaxPhpClient\Enum\UpdateType;
use Illuminate\Support\Facades\Log;

/**
 * Реестр чатов: upsert max_chats по апдейтам bot_added/bot_started/
 * bot_stopped/bot_removed (getChats deprecated — chat_id хранить через
 * подписку). При наличии user в апдейте — upsert в max_users.
 * chat_type определяется из Recipient->chatType (message/comment/callback),
 * isChannel (lifecycle), либо getChat() API (fallback для bot_added/bot_started).
 * Message/comment/callback-апдейты статус не меняют и чат не создают — только
 * дозаполняют chat_type у существующего чата, если он ещё не известен, и
 * двигают last_activity_at.
 *
 * Название группы или канала апдейтами bot_added/bot_started не приходит, поэтому
 * для них метаданные запрашиваются через MaxChatProfileService (одним запросом
 * на все записи чата). Событие chat_title_changed приходит уже с готовым
 * title — для него запрос не нужен.
 *
 * Включается config('laravel-max-client.chats.enabled').
 *
 * Внутренний класс: собирается контейнером, вручную не инстанцировать. Конструктор принимает
 * MaxChatProfileService, а не ApiClient, как было в v1.1.3 — изменение задокументировано в
 * .agents/release/RELEASE_NOTES_v1.1.4.md. Слушатели положено переопределять привязкой
 * MaxUpdateReceived::class, а не подменой класса.
 */
final class PersistMaxChatListener
{
    public function __construct(
        private readonly Config $config,
        private readonly MaxChatProfileService $chatProfile,
    ) {
    }

    public function handle(MaxUpdateReceived $event): void
    {
        $update = $event->update;

        if ($update->message !== null || $update->comment !== null || $update->callback !== null) {
            $this->touchExistingChat($update);

            return;
        }

        if ($update->updateType === UpdateType::ChatTitleChanged) {
            $this->applyTitleFromUpdate($update);

            return;
        }

        $status = match ($update->updateType) {
            UpdateType::BotAdded, UpdateType::BotStarted => MaxChatStatus::Active,
            UpdateType::BotStopped => MaxChatStatus::Stopped,
            UpdateType::BotRemoved => MaxChatStatus::Removed,
            default => null,
        };

        if ($status === null) {
            return;
        }

        $user = $update->user;
        $userId = $user !== null ? $user->userId : $update->userId;

        if ($userId === null) {
            $this->applyStatusToKnownRows($update, $status);

            return;
        }

        if ($update->chatId === null) {
            Log::warning('MAX chat update without chat_id skipped.', [
                'update_type' => $update->updateType->value,
                'user_id' => $userId,
            ]);

            return;
        }

        $this->upsertUser($user, $userId);

        $chatModel = $this->config->chatsModel();

        $data = [
            'status' => $status,
            'last_activity_at' => now(),
        ];

        $chatType = $this->resolveChatType($update);

        if ($chatType !== null) {
            $data['chat_type'] = $chatType;
        }

        $chatModel::query()->updateOrCreate(
            [
                'user_id' => $userId,
                'chat_id' => $update->chatId,
            ],
            $data,
        );

        $this->syncMetadataIfNeeded($update);
    }

    /**
     * bot_added/bot_started/bot_stopped/bot_removed — события о боте в чате, а не
     * о пользователе, поэтому user в них может не прийти. Реестр хранит по
     * строке на участника, и без user новую строку не завести, зато состояние
     * чата известно: обновляем все записи с этим chat_id. Иначе bot_removed без
     * user терялся, и чат навсегда оставался active.
     *
     * Записей нет — ничего не делаем: о таком чате мы не знали и узнавать его
     * отсюда нечем.
     */
    private function applyStatusToKnownRows(Update $update, MaxChatStatus $status): void
    {
        $chatId = $update->chatId;

        if ($chatId === null) {
            Log::warning('MAX chat update without chat_id skipped.', [
                'update_type' => $update->updateType->value,
            ]);

            return;
        }

        $chatModel = $this->config->chatsModel();

        $data = [
            'status' => $status,
            'last_activity_at' => now(),
        ];

        $chatType = $this->resolveChatType($update);

        if ($chatType !== null) {
            $data['chat_type'] = $chatType;
        }

        $chatModel::query()
            ->where('chat_id', $chatId)
            ->update($data);
    }

    private function upsertUser(?User $user, int $userId): void
    {
        if ($user === null) {
            return;
        }

        $userModel = $this->config->usersModel();

        $userModel::query()->updateOrCreate(
            ['user_id' => $userId],
            [
                'first_name' => $user->firstName,
                'last_name' => $user->lastName,
                'username' => $user->username,
                'is_bot' => $user->isBot,
                'last_activity_time' => $user->lastActivityTime,
                'name' => $user->name,
                'profile_checked_at' => now(),
            ],
        );
    }

    private function resolveChatType(Update $update): ?ChatType
    {
        $chatType = $update->message?->recipient->chatType
            ?? $update->comment?->recipient->chatType
            ?? $update->callback?->message?->recipient->chatType;

        if (\is_string($chatType)) {
            return ChatType::tryFrom($chatType);
        }

        return $update->isChannel === true
            ? ChatType::Channel
            : null;
    }

    /**
     * Апдейт активности: дозаполняет chat_type у существующего чата и двигает
     * last_activity_at. Чат не создаёт и статус не меняет.
     */
    private function touchExistingChat(Update $update): void
    {
        $chatId = $update->chatId;

        if ($chatId === null) {
            return;
        }

        $chatModel = $this->config->chatsModel();
        $query = $chatModel::query()->where('chat_id', $chatId);

        $chatType = $this->resolveChatType($update);

        if ($chatType !== null) {
            $query->whereNull('chat_type')->update(['chat_type' => $chatType]);
        }

        $chatModel::query()
            ->where('chat_id', $chatId)
            ->update(['last_activity_at' => now()]);
    }

    /**
     * Название из события chat_title_changed: запрос к API не нужен, значение
     * пишется во все записи чата (в группе их по одной на участника). Пустой
     * title игнорируется, чтобы не затереть уже известное название.
     */
    private function applyTitleFromUpdate(Update $update): void
    {
        $chatId = $update->chatId;
        $title = $update->title === null ? '' : trim($update->title);

        if ($chatId === null || $title === '') {
            return;
        }

        $chatModel = $this->config->chatsModel();

        $chatModel::query()
            ->where('chat_id', $chatId)
            ->update([
                'title' => $title,
                'title_checked_at' => now(),
            ]);
    }

    /**
     * Запросить метаданные чата, если название ещё неизвестно и посторонние
     * запросы разрешены (chats.fetch_metadata). Название есть только у группы и
     * канала, поэтому для диалога запрос не делается.
     *
     * Правила выбора записи и передачи уже известных значений живут в
     * MaxChatProfileService::ensureMetadata(): здесь только условия «когда».
     */
    private function syncMetadataIfNeeded(Update $update): void
    {
        if (!$this->config->chatsFetchMetadata()) {
            return;
        }

        if (!\in_array($update->updateType, [UpdateType::BotAdded, UpdateType::BotStarted], true)) {
            return;
        }

        $chatId = $update->chatId;

        if ($chatId === null) {
            return;
        }

        $this->chatProfile->ensureMetadata($chatId);
    }
}
