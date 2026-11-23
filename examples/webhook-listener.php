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
//
// С ядра v2.0.0 Update — типизированная иерархия: ветвление идёт по классу
// события, а не по строке update_type.

namespace App\Listeners;

use GeekCo\LaravelMaxClient\Facades\Max;
use GeekCo\LaravelMaxClient\Webhook\MaxUpdateReceived;
use GeekCo\MaxPhpClient\Dto\NewMessageBody;
use GeekCo\MaxPhpClient\Dto\Recipient;
use GeekCo\MaxPhpClient\Dto\Update\BotAddedToChatUpdate;
use GeekCo\MaxPhpClient\Dto\Update\BotStartedUpdate;
use GeekCo\MaxPhpClient\Dto\Update\MessageCallbackUpdate;
use GeekCo\MaxPhpClient\Dto\Update\MessageCreatedUpdate;

final class MaxWebhookListener
{
    public function handle(MaxUpdateReceived $event): void
    {
        $update = $event->update;

        switch (true) {
            case $update instanceof BotAddedToChatUpdate:
            case $update instanceof BotStartedUpdate:
                // Реестр чатов max_chats ведёт пакет (PersistMaxChatListener,
                // MAX_CHATS_ENABLED=true) — GET /chats удалён в v2.0.0, chat_id
                // хранится через подписку на эти апдейты. Здесь — только
                // бизнес-обработка события.

                break;

            case $update instanceof MessageCreatedUpdate:
                $this->handleMessageCreated($update);

                break;

            case $update instanceof MessageCallbackUpdate:
                $this->handleCallback($update);

                break;
        }
    }

    private function handleMessageCreated(MessageCreatedUpdate $update): void
    {
        $message = $update->message;
        $body = $message->body;

        if ($body === null) {
            return;
        }

        // Игнорировать собственные сообщения бота (нет sender или это сам бот).
        if ($message->sender === null) {
            return;
        }

        $text = $body->text;

        if ($text === null) {
            return;
        }

        // Ответ в тот же диалог: для лички хватает chatId, иначе userId.
        $recipient = new Recipient(
            chatId: $message->recipient->chatId,
            userId: $message->sender->userId,
        );

        Max::sendMessage(
            $recipient,
            new NewMessageBody(text: 'Вы написали: ' . $text),
        );
    }

    private function handleCallback(MessageCallbackUpdate $update): void
    {
        $callback = $update->callback;

        // payload задаётся при отправке сообщения с кнопками.
        $payload = $callback->payload;

        if ($payload === null) {
            return;
        }

        // Макс. 2 ответа/сек на диалог — ядро применяет rate limit само.
        Max::answerCallback(
            callbackId: $callback->callbackId,
            notification: 'Получен callback: ' . $payload,
        );
    }
}
