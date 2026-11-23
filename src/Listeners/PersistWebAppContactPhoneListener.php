<?php

declare(strict_types=1);

namespace GeekCo\LaravelMaxClient\Listeners;

use GeekCo\LaravelMaxClient\Support\Config;
use GeekCo\LaravelMaxClient\Support\Logger;
use GeekCo\LaravelMaxClient\WebApp\WebAppContactVerified;
use Illuminate\Support\Str;

/**
 * Запись телефона в max_users из подтверждённого контакта моста requestContact.
 *
 * Это тот же реестр пользователей, что и у PersistMaxUserPhoneListener для
 * кнопки request_contact в чате: подтверждённый номер сохраняется в
 * max_users.phone, отметка phone_verified_at двигается вперёд. Отдельной
 * настройки нет — контакт уже проверен подписью WebAppContactVerifier.
 *
 * Имя в ответе requestContact не приходит: необязательное имя из
 * initDataUnsafe.user передаёт приложение в событии. При создании записи оно
 * разбивается на first_name/last_name; существующее имя не перетирается.
 *
 * Телефон — персональные данные: в лог уходит только факт смены номера и
 * user_id, без значений (OWASP A09).
 */
final class PersistWebAppContactPhoneListener
{
    public function __construct(
        private readonly Config $config,
        private readonly Logger $logger,
    ) {
    }

    public function handle(WebAppContactVerified $event): void
    {
        $userId = $event->contact->userId;
        $phone = $event->contact->phone;

        if ($userId === null || $phone === null) {
            return;
        }

        $model = $this->config->usersModel();
        $record = $model::query()->find($userId);
        $name = $this->normalizeName($event->name);

        if ($record === null) {
            [$firstName, $lastName] = $this->splitName($name);

            $model::query()->create([
                'user_id' => $userId,
                'first_name' => $firstName,
                'last_name' => $lastName,
                'name' => $name !== '' ? Str::limit($name, 256, '') : null,
                'phone' => $phone,
                'phone_verified_at' => now(),
                'is_bot' => false,
            ]);

            return;
        }

        if ($record->phone !== null && $record->phone !== $phone) {
            $this->logger->log('info', 'MAX webapp contact phone differs from stored value; new value saved.', [
                'user_id' => $userId,
            ]);
        }

        $record->forceFill([
            'phone' => $phone,
            'phone_verified_at' => now(),
        ])->save();
    }

    private function normalizeName(?string $name): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', (string) $name));
    }

    /**
     * @return array{0: string, 1: string|null}
     */
    private function splitName(string $name): array
    {
        $parts = preg_split('/\s+/u', $name, 2) ?: [];
        $firstName = trim($parts[0] ?? '');

        return [
            Str::limit($firstName, 128, ''),
            isset($parts[1]) ? Str::limit($parts[1], 128, '') : null,
        ];
    }
}
