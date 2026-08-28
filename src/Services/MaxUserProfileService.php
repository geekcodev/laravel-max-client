<?php

declare(strict_types=1);

namespace GeekCo\LaravelMaxClient\Services;

use GeekCo\LaravelMaxClient\Enums\MaxChatStatus;
use GeekCo\LaravelMaxClient\Models\MaxUser;
use GeekCo\LaravelMaxClient\Support\Config;
use GeekCo\LaravelMaxClient\Support\Logger;
use GeekCo\MaxPhpClient\ApiClient;
use GeekCo\MaxPhpClient\Dto\ChatMember;

/**
 * Получение и сохранение полного профиля пользователя MAX (включая аватар).
 * Аватар в апдейтах не приходит — источник истины члены чата (getChatMembers).
 * Триггер «когда вызывать» — в приложении; пакет предоставляет возможность.
 */
final class MaxUserProfileService
{
    public function __construct(
        private readonly ApiClient $api,
        private readonly Config $config,
        private readonly Logger $logger,
    ) {
    }

    /**
     * Подгрузить/обновить профили пользователей через getChatMembers. chat_id
     * берём из активных max_chats (bot_added/bot_started), см.
     * config('laravel-max-client.users.profile_from_active_chats').
     *
     * @param int|list<int> $userId
     */
    public function refresh(int|array $userId): bool
    {
        $userIds = $this->normalizeUserIds($userId);

        if ($userIds === []) {
            return false;
        }

        $groups = $this->collectActiveChatGroups($userIds);

        if ($groups === []) {
            $this->logger->log('warning', 'MAX user profile refresh skipped: no active chat found.', [
                'user_ids' => $userIds,
            ]);

            return false;
        }

        $updated = false;

        foreach ($groups as $chatId => $chatUserIds) {
            if ($this->fetchForChat($chatId, $chatUserIds)) {
                $updated = true;
            }
        }

        if ($updated) {
            $this->logger->log('info', 'MAX user profiles refreshed.', [
                'user_ids' => $userIds,
            ]);
        }

        return $updated;
    }

    /**
     * Обновить запись max_users из DTO ChatMember (полный профиль с аватаром).
     */
    public function upsertFromMember(ChatMember $member): MaxUser
    {
        $model = $this->config->usersModel();

        return $model::query()->updateOrCreate(
            ['user_id' => $member->userId],
            [
                'first_name' => $member->firstName,
                'last_name' => $member->lastName,
                'username' => $member->username,
                'is_bot' => $member->isBot,
                'last_activity_time' => $member->lastActivityTime,
                'name' => $member->name,
                'description' => $member->description,
                'avatar_url' => $member->avatarUrl,
                'full_avatar_url' => $member->fullAvatarUrl,
                'profile_checked_at' => now(),
            ],
        );
    }

    /**
     * Заполнить недостающие поля аватара или перепроверить профиль по
     * users.profile_check_interval (0 — только при пустом аватаре). chatId —
     * явное указание чата (без поиска активного max_chats в реестре).
     */
    public function ensureAvatar(MaxUser $user, ?int $chatId = null): bool
    {
        if ($this->hasFullAvatar($user) && !$this->profileIsDue($user)) {
            return false;
        }

        if ($chatId !== null) {
            return $this->fetchForChat($chatId, [$user->user_id]);
        }

        return $this->refresh($user->user_id);
    }

    /**
     * Сгруппировать userIds по активным чатам: для каждого пользователя берём
     * любой активный max_chats (одна запись на пользователя на чат).
     *
     * @param list<int> $userIds
     *
     * @return array<int, list<int>>
     */
    private function collectActiveChatGroups(array $userIds): array
    {
        if (!$this->config->profileFromActiveChats()) {
            return [];
        }

        $chats = $this->config->chatsModel()::query()
            ->whereIn('user_id', $userIds)
            ->where('status', MaxChatStatus::Active)
            ->get();

        $groups = [];

        foreach ($chats as $chat) {
            $chatId = filter_var($chat->chat_id, FILTER_VALIDATE_INT);
            $userId = filter_var($chat->user_id, FILTER_VALIDATE_INT);

            if ($chatId === false || $userId === false || $chatId <= 0) {
                continue;
            }

            $groups[$chatId] ??= [];
            if (!\in_array($userId, $groups[$chatId], true)) {
                $groups[$chatId][] = $userId;
            }
        }

        return $groups;
    }

    /**
     * Запросить members чата батчами по profile_batch_size и сохранить профили.
     *
     * @param list<int> $userIds
     */
    private function fetchForChat(int $chatId, array $userIds): bool
    {
        $updated = false;

        $batchSize = $this->config->profileBatchSize();
        if ($batchSize < 1) {
            $batchSize = 1;
        }

        foreach (array_chunk($userIds, $batchSize) as $chunk) {
            $result = $this->api->getChatMembers($chatId, $chunk);

            foreach ($result->members as $member) {
                $this->upsertFromMember($member);
                $updated = true;
            }
        }

        return $updated;
    }

    /**
     * @param int|list<int> $userId
     *
     * @return list<int>
     */
    private function normalizeUserIds(int|array $userId): array
    {
        $ids = \is_array($userId) ? $userId : [$userId];

        return array_values(array_unique(array_filter(
            $ids,
            static fn (int $id): bool => $id > 0,
        )));
    }

    private function hasFullAvatar(MaxUser $user): bool
    {
        return $user->avatar_url !== null
            && $user->avatar_url !== ''
            && $user->full_avatar_url !== null
            && $user->full_avatar_url !== '';
    }

    private function profileIsDue(MaxUser $user): bool
    {
        $interval = $this->config->profileCheckInterval();

        if ($interval <= 0) {
            return false;
        }

        $checkedAt = $user->profile_checked_at;

        if ($checkedAt === null) {
            return true;
        }

        return $checkedAt->getTimestamp() + $interval <= now()->getTimestamp();
    }
}
