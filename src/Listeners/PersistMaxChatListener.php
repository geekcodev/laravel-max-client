<?php

declare(strict_types=1);

namespace GeekCo\LaravelMaxClient\Listeners;

use GeekCo\LaravelMaxClient\Enums\MaxChatStatus;
use GeekCo\LaravelMaxClient\Support\Config;
use GeekCo\LaravelMaxClient\Webhook\MaxUpdateReceived;
use GeekCo\MaxPhpClient\ApiClient;
use GeekCo\MaxPhpClient\Enum\ChatType;
use GeekCo\MaxPhpClient\Enum\UpdateType;
use Illuminate\Support\Facades\Log;

/**
 * Реестр чатов: upsert max_chats по апдейтам bot_added/bot_started/
 * bot_stopped/bot_removed (getChats deprecated — chat_id хранить через
 * подписку). При наличии user в апдейте — upsert в max_users.
 * chat_type определяется из Recipient->chatType (message/callback),
 * isChannel (lifecycle), либо getChat() API (fallback для bot_added/bot_started).
 * Message/callback-апдейты статус не меняют и чат не создают — только
 * дозаполняют chat_type у существующего чата, если он ещё не известен.
 * Включается config('laravel-max-client.chats.enabled').
 */
final class PersistMaxChatListener
{
    public function __construct(
        private readonly Config $config,
        private readonly ApiClient $apiClient,
    ) {
    }

    public function handle(MaxUpdateReceived $event): void
    {
        $update = $event->update;

        if ($update->message !== null || $update->callback !== null) {
            $this->fillChatTypeFromRecipient($update);

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
            Log::warning('MAX chat update without user skipped.', [
                'update_type' => $update->updateType->value,
                'chat_id' => $update->chatId,
            ]);

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

        if ($chatType === null && in_array($update->updateType, [UpdateType::BotAdded, UpdateType::BotStarted], true)) {
            $chatType = $this->resolveChatTypeViaApi($update->chatId);
        }

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
    }

    private function upsertUser(?\GeekCo\MaxPhpClient\Dto\User $user, int $userId): void
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

    private function resolveChatType(\GeekCo\MaxPhpClient\Dto\Update $update): ?ChatType
    {
        $chatType = $update->message?->recipient->chatType
            ?? $update->callback?->message?->recipient->chatType;

        if (is_string($chatType)) {
            return ChatType::tryFrom($chatType);
        }

        return $update->isChannel === true
            ? ChatType::Channel
            : null;
    }

    private function fillChatTypeFromRecipient(\GeekCo\MaxPhpClient\Dto\Update $update): void
    {
        $chatId = $update->chatId;

        if ($chatId === null) {
            return;
        }

        $chatType = $this->resolveChatType($update);

        if ($chatType === null) {
            return;
        }

        $chatModel = $this->config->chatsModel();

        $chatModel::query()
            ->where('chat_id', $chatId)
            ->whereNull('chat_type')
            ->update(['chat_type' => $chatType]);
    }

    private function resolveChatTypeViaApi(int $chatId): ?ChatType
    {
        try {
            $chat = $this->apiClient->getChat($chatId);

            return ChatType::tryFrom($chat->type->value);
        } catch (\Throwable $e) {
            Log::warning('MAX getChat fallback failed.', [
                'chat_id' => $chatId,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }
}
