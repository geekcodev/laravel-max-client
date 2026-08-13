<?php

declare(strict_types=1);

// Базовое использование фасада Max.
//
// Пример рассчитан на Laravel-приложение, в котором установлен пакет
// geekcodev/laravel-max-client и задан MAX_API_TOKEN в .env.

use GeekCo\LaravelMaxClient\Facades\Max;
use GeekCo\MaxPhpClient\Dto\NewMessageBody;
use GeekCo\MaxPhpClient\Dto\Recipient;
use GeekCo\MaxPhpClient\Enum\SenderAction;
use GeekCo\MaxPhpClient\Enum\TextFormat;

// Информация о боте (GET /self).
$me = Max::getMe();
echo 'Бот: @' . $me->username . PHP_EOL;

// chat_id / user_id — int64. Для диалога/чата/канала брать из Update
// (см. examples/webhook-listener.php), а не из GET /chats (deprecated).
$chatId = 1234567890;

// Простое сообщение в диалог/чат/канал.
Max::sendMessage(
    new Recipient(chatId: $chatId),
    new NewMessageBody(text: 'Привет!'),
);

// Личное сообщение пользователю.
Max::sendMessage(
    new Recipient(userId: 9876543210),
    new NewMessageBody(text: 'Личное сообщение.'),
);

// Сообщение с разметкой Markdown и уведомлением (2/сек rate limit на диалог).
Max::sendMessage(
    new Recipient(chatId: $chatId),
    new NewMessageBody(
        text: '**Важно**: акция до конца недели.',
        notify: true,
        format: TextFormat::Markdown,
    ),
);

// Фасад делегирует все методы ядра (PHPDoc @method в Facades\Max) —
// примеры API v1.0.3: typing-статус и список админов чата.
Max::sendBotAction($chatId, SenderAction::TypingOn);
$admins = Max::getChatAdmins($chatId); // ChatAdminsResult::$members
foreach ($admins->members as $admin) {
    echo 'Админ: @' . ($admin->username ?? (string) $admin->userId) . PHP_EOL;
}
