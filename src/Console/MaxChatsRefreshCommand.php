<?php

declare(strict_types=1);

namespace GeekCo\LaravelMaxClient\Console;

use GeekCo\LaravelMaxClient\Services\MaxChatProfileService;
use Illuminate\Console\Command;

/**
 * Дозаполнение метаданных чатов (название, описание, ссылка, иконка).
 *
 * Название группы или канала приходит только из getChat(), поэтому у чатов,
 * зарегистрированных до появления записи об этом, оно остаётся пустым. Команда
 * нужна один раз после обновления и дальше по расписанию, когда у чата сменилось
 * название, а событие chat_title_changed не дошло.
 *
 * Работа идёт по одному запросу getChat на chat_id независимо от числа
 * участников в реестре.
 */
final class MaxChatsRefreshCommand extends Command
{
    protected $signature = 'max:chats:refresh
                            {--all : Обновить метаданные всех активных чатов (по умолчанию)}
                            {--chat=* : chat_id через запятую или повторным флагом}';

    protected $description = 'Обновить метаданные чатов MAX (название, описание, ссылка, иконка)';

    public function handle(MaxChatProfileService $chatProfile): int
    {
        $chatIds = $this->chatIdsFromOption();

        if ($chatIds === null) {
            return self::INVALID;
        }

        $chatProfile->forgetChatCache();

        // null — все активные чаты реестра.
        $requested = $chatIds === [] ? null : $chatIds;
        $total = $requested === null ? \count($chatProfile->activeChatIds()) : \count($requested);
        $pending = $chatProfile->pendingChatIds($requested);

        $this->reportScope($total, $pending);

        $updated = $chatProfile->refresh($requested);

        $this->info($updated
            ? 'Метаданные чатов обновлены.'
            : 'Обновлений нет: запросы не дали данных.');

        return self::SUCCESS;
    }

    /**
     * Сколько чатов к проверке и сколько пропущено как проверенные недавно —
     * иначе из расписания не видно, был ли запрос вообще.
     *
     * @param list<int> $pending
     */
    private function reportScope(int $total, array $pending): void
    {
        $this->line(sprintf(
            'К проверке: %d, пропущено как проверенные недавно: %d.',
            \count($pending),
            max(0, $total - \count($pending)),
        ));
    }

    /**
     * @return list<int>|null null — некорректный chat_id
     */
    private function chatIdsFromOption(): ?array
    {
        /** @var list<mixed> $option */
        $option = $this->option('chat');
        $raw = [];

        foreach ($option as $value) {
            if (!\is_scalar($value)) {
                continue;
            }

            foreach (explode(',', (string) $value) as $part) {
                $part = trim($part);

                if ($part !== '') {
                    $raw[] = $part;
                }
            }
        }

        $ids = [];

        foreach ($raw as $part) {
            $id = filter_var($part, FILTER_VALIDATE_INT);

            if ($id === false || $id <= 0) {
                $this->error("Некорректный chat_id: {$part}");

                return null;
            }

            $ids[] = $id;
        }

        return array_values(array_unique($ids));
    }
}
