<?php

declare(strict_types=1);

namespace GeekCo\LaravelMaxClient\Listeners;

use GeekCo\LaravelMaxClient\Enums\BotChatStatus;
use GeekCo\LaravelMaxClient\Support\Config;
use GeekCo\LaravelMaxClient\Webhook\MaxUpdateReceived;
use GeekCo\MaxPhpClient\Enum\UpdateType;
use Illuminate\Support\Facades\Log;

/**
 * Реестр чатов: upsert bot_chats по апдейтам bot_added/bot_started/
 * bot_stopped/bot_removed (getChats deprecated — chat_id хранить через
 * подписку). Включается config('laravel-max-client.chats.enabled').
 */
final class PersistBotChatListener
{
    public function __construct(
        private readonly Config $config,
    ) {
    }

    public function handle(MaxUpdateReceived $event): void
    {
        $update = $event->update;

        $status = match ($update->updateType) {
            UpdateType::BotAdded, UpdateType::BotStarted => BotChatStatus::Active,
            UpdateType::BotStopped => BotChatStatus::Stopped,
            UpdateType::BotRemoved => BotChatStatus::Removed,
            default => null,
        };

        if ($status === null) {
            return;
        }

        if ($update->chatId === null) {
            Log::warning('MAX chat update without chat_id skipped.', [
                'update_type' => $update->updateType->value,
                'user_id' => $update->user->userId,
            ]);

            return;
        }

        $model = $this->config->chatsModel();

        $model::query()->updateOrCreate(
            [
                'user_id' => $update->user->userId,
                'chat_id' => $update->chatId,
            ],
            ['status' => $status],
        );
    }
}
