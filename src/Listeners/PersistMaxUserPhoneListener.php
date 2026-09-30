<?php

declare(strict_types=1);

namespace GeekCo\LaravelMaxClient\Listeners;

use GeekCo\LaravelMaxClient\Services\MaxContactService;
use GeekCo\LaravelMaxClient\Support\Config;
use GeekCo\LaravelMaxClient\Support\Logger;
use GeekCo\LaravelMaxClient\Webhook\MaxUpdateReceived;
use GeekCo\MaxPhpClient\Dto\User;
use GeekCo\MaxPhpClient\Enum\UpdateType;

/**
 * Запись телефона в max_users из подтверждённого вложения-контакта.
 *
 * Отдельного разрешения на захват не требуется: контакт приходит только через
 * кнопку request_contact и формируется платформой из номера аккаунта
 * отправителя — регистрация в MAX возможна на один номер, поэтому это номер
 * самого пользователя, а не произвольное значение из пересланной карточки.
 * Пересланный контакт приходит файлом, а не вложением типа contact, и в этот
 * путь не попадает.
 *
 * Расхождение с сохранённым значением означает, что пользователь сменил номер
 * аккаунта: новое значение побеждает, иначе он остался бы навсегда привязан к
 * старому. Сам факт смены фиксируется в логе без значений (OWASP A09).
 *
 * Обрабатываются лишь message_created и message_callback; чат не создаётся и
 * статус не меняется — это не реестр чатов.
 */
final class PersistMaxUserPhoneListener
{
    public function __construct(
        private readonly Config $config,
        private readonly MaxContactService $contacts,
        private readonly Logger $logger,
    ) {
    }

    public function handle(MaxUpdateReceived $event): void
    {
        $update = $event->update;

        if (!\in_array($update->updateType, [UpdateType::MessageCreated, UpdateType::MessageCallback], true)) {
            return;
        }

        $phone = $this->contacts->phoneFromUpdate($update);

        if ($phone === null) {
            return;
        }

        $user = $update->user;
        $userId = $user !== null ? $user->userId : $update->userId;

        if ($userId === null) {
            return;
        }

        $this->persist($user, $userId, $phone, $update->chatId);
    }

    private function persist(?User $user, int $userId, string $phone, ?int $chatId): void
    {
        $model = $this->config->usersModel();

        $record = $model::query()->find($userId);

        if ($record === null) {
            // Пользователя в реестре ещё нет — заводим запись целиком.
            $model::query()->create([
                'user_id' => $userId,
                'first_name' => $user !== null ? $user->firstName : '',
                'last_name' => $user?->lastName,
                'username' => $user?->username,
                'is_bot' => $user !== null ? $user->isBot : false,
                'last_activity_time' => $user?->lastActivityTime,
                'name' => $user?->name,
                'phone' => $phone,
                'phone_verified_at' => now(),
            ]);

            return;
        }

        if ($record->phone !== null && $record->phone !== $phone) {
            $this->logger->log('info', 'MAX contact phone differs from stored value; new value saved.', [
                'chat_id' => $chatId,
                'user_id' => $userId,
            ]);
        }

        $record->forceFill([
            'phone' => $phone,
            'phone_verified_at' => now(),
        ])->save();
    }
}
