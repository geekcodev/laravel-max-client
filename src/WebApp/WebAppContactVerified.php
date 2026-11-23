<?php

declare(strict_types=1);

namespace GeekCo\LaravelMaxClient\WebApp;

use GeekCo\MaxPhpClient\Dto\WebAppContact;

/**
 * Контакт из моста requestContact подтверждён подписью.
 *
 * Событие несёт только проверенные поля контакта и необязательное имя из
 * профиля мини-приложения (в ответе requestContact имени нет).
 */
final readonly class WebAppContactVerified
{
    public function __construct(
        public WebAppContact $contact,
        public ?string $name = null,
    ) {
    }
}
