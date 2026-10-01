<?php

declare(strict_types=1);

namespace GeekCo\LaravelMaxClient\Services;

use GeekCo\LaravelMaxClient\Models\MaxUser;
use GeekCo\LaravelMaxClient\Support\Config;
use GeekCo\LaravelMaxClient\Support\Logger;
use GeekCo\MaxPhpClient\ApiClient;
use GeekCo\MaxPhpClient\Dto\ChatMember;
use GeekCo\MaxPhpClient\Dto\UserWithPhoto;
use GeekCo\MaxPhpClient\Enum\ChatType;

/**
 * Получение и сохранение полного профиля пользователя MAX (включая аватар).
 * Аватар в апдейтах не приходит — источник истины getChatMembers (группы/
 * каналы) и dialog_with_user (диалоги, где getChatMembers недоступен). Тип
 * чата берём из реестра max_chats.chat_type, при неизвестном — запрашиваем
 * getChat() через MaxChatProfileService и запоминаем. Триггер «когда вызывать» —
 * в приложении.
 *
 * Внутренний класс: собирается контейнером, вручную не инстанцировать. При
 * переопределении привязки передавайте все четыре зависимости (см.
 * MaxServiceProvider::register()).
 */
final class MaxUserProfileService
{
    public function __construct(
        private readonly ApiClient $api,
        private readonly Config $config,
        private readonly Logger $logger,
        private readonly MaxChatProfileService $chatProfile,
    ) {
    }

    /**
     * Подгрузить/обновить профили пользователей через getChatMembers
     * (группы/каналы) или dialog_with_user (диалоги). chat_id берём из
     * активных max_chats (bot_added/bot_started), см.
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
     * Сгруппировать userIds по активным чатам.
     *
     * chat_id берётся из активных строк max_chats (одна строка на чат), а
     * user_id — из max_chat_users: связь чата с пользователями живёт в отдельной
     * таблице, поэтому одних строк чата недостаточно.
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

        $activeChatIds = $this->chatProfile->activeChatIds();

        if ($activeChatIds === []) {
            return [];
        }

        $links = $this->config->chatUsersModel()::query()
            ->whereIn('user_id', $userIds)
            ->whereIn('chat_id', $activeChatIds)
            ->orderBy('chat_id')
            ->orderBy('user_id')
            ->get(['chat_id', 'user_id']);

        $groups = [];

        foreach ($links as $link) {
            $chatId = filter_var($link->chat_id, FILTER_VALIDATE_INT);
            $userId = filter_var($link->user_id, FILTER_VALIDATE_INT);

            if ($chatId === false || $userId === false || $chatId === 0) {
                continue;
            }

            $groups[$chatId] ??= [];
            $groups[$chatId][] = $userId;
        }

        return $groups;
    }

    /**
     * Запросить профили пользователей чата: getChatMembers (группы/каналы)
     * батчами по profile_batch_size либо dialog_with_user (диалоги, где
     * getChatMembers недоступен — «Method is not available for dialogs»).
     *
     * @param list<int> $userIds
     */
    private function fetchForChat(int $chatId, array $userIds): bool
    {
        if ($this->chatProfile->chatTypeFor($chatId) === ChatType::Dialog) {
            return $this->fetchForDialog($chatId, $userIds);
        }

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
     * Профиль собеседника в диалоге: getChat() -> dialog_with_user. Для
     * диалога это единственный источник полного профиля с аватаром.
     *
     * @param list<int> $userIds
     */
    private function fetchForDialog(int $chatId, array $userIds): bool
    {
        $chat = $this->chatProfile->fetch($chatId);

        if ($chat === null || $chat->dialogWithUser === null) {
            return false;
        }

        $partner = $chat->dialogWithUser;

        if (!\in_array($partner->userId, $userIds, true)) {
            return false;
        }

        $this->upsertFromMember($this->toChatMember($partner));

        return true;
    }

    private function toChatMember(UserWithPhoto $user): ChatMember
    {
        return new ChatMember(
            userId: $user->userId,
            firstName: $user->firstName,
            lastName: $user->lastName,
            username: $user->username,
            isBot: $user->isBot,
            lastActivityTime: $user->lastActivityTime,
            name: $user->name,
            description: $user->description,
            avatarUrl: $user->avatarUrl,
            fullAvatarUrl: $user->fullAvatarUrl,
        );
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
            static fn (int $id): bool => $id !== 0,
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
