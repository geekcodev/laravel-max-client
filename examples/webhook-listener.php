<?php

declare(strict_types=1);

// Обработчик вебхук-апдейтов.
//
// Пакет поставляет механизм доставки (роут POST /max/webhook + очередь),
// бизнес-обработку апдейтов реализует приложение через подписку на событие
// MaxUpdateReceived. Зарегистрируйте слушателя в EventServiceProvider:
//
//   protected $listen = [
//       \GeekCo\LaravelMaxClient\Webhook\MaxUpdateReceived::class => [
//           \App\Listeners\MaxWebhookListener::class,
//       ],
//   ];
//
// Тот же слушатель обрабатывает апдейты из Long Polling локальной разработки
// (php artisan max:listen), когда публичного домена для вебхука нет.

namespace App\Listeners;

use GeekCo\LaravelMaxClient\Facades\Max;
use GeekCo\LaravelMaxClient\Webhook\MaxUpdateReceived;
use GeekCo\MaxPhpClient\Dto\NewMessageBody;
use GeekCo\MaxPhpClient\Dto\Recipient;
use GeekCo\MaxPhpClient\Enum\UpdateType;

final class MaxWebhookListener
{
    public function handle(MaxUpdateReceived $event): void
    {
        $update = $event->update;

        switch ($update->updateType) {
            case UpdateType::BotAdded:
            case UpdateType::BotStarted:
                // Сохранить chat_id — это источник истины для будущей рассылки
                // (GET /chats deprecated). chat_id может быть null — только для
                // личных диалогов доступен user_id из $update->user.
                if ($update->chatId !== null) {
                    \App\Models\MaxChat::firstOrCreate(['chat_id' => $update->chatId]);
                }

                break;

            case UpdateType::MessageCreated:
                $this->handleMessageCreated($update);

                break;

            case UpdateType::MessageCallback:
                $this->handleCallback($update);

                break;
        }
    }

    private function handleMessageCreated(GeekCo\MaxPhpClient\Dto\Update $update): void
    {
        $message = $update->message;

        if ($message === null || $message->body === null) {
            return;
        }

        // Игнорировать собственные сообщения бота (нет sender или это сам бот).
        if ($message->sender === null) {
            return;
        }

        $text = $message->body->text;

        if ($text === null) {
            return;
        }

        // Ответ в тот же диалог: для лички хватает chatId, иначе userId.
        $recipient = new Recipient(
            chatId: $update->chatId,
            userId: $message->sender->userId,
        );

        Max::sendMessage(
            $recipient,
            new NewMessageBody(text: 'Вы написали: ' . $text),
        );
    }

    private function handleCallback(GeekCo\MaxPhpClient\Dto\Update $update): void
    {
        $callback = $update->callback;

        if ($callback === null) {
            return;
        }

        // payload задаётся при отправке сообщения с кнопками (NewMessageLink).
        $payload = $callback->payload;

        if ($payload === null) {
            return;
        }

        // Макс. 2 ответа/сек на диалог — ядро применяет rate limit само.
        if ($update->chatId !== null) {
            Max::sendMessage(
                new Recipient(chatId: $update->chatId),
                new NewMessageBody(text: 'Получен callback: ' . $payload),
            );
        }
    }
}
