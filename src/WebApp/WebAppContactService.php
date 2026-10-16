<?php

declare(strict_types=1);

namespace GeekCo\LaravelMaxClient\WebApp;

use GeekCo\LaravelMaxClient\Support\Config;
use GeekCo\LaravelMaxClient\Support\Logger;
use GeekCo\MaxPhpClient\Dto\WebAppContact;
use GeekCo\MaxPhpClient\Security\WebAppContactVerifier;

/**
 * Подтверждение контакта, полученного мини-приложением через
 * window.WebApp.requestContact() (мост requestContact).
 *
 * Тонкая обёртка над ядром WebAppContactVerifier (v1.1.10): подпись
 * HMAC-SHA256 по трём полям payload (authDate, phone, userId), сравнение
 * константное, свежесть authDate — по webapp.max_age. Сам алгоритм намеренно
 * не дублируется в адаптере.
 *
 * Это другой механизм, чем контакт из кнопки request_contact в чате
 * (MaxContactService, vcf_info + HMAC access_token) — поля и схема подписи
 * разные.
 *
 * Телефон и hash — персональные данные: при отказе в лог уходит только факт
 * и user_id, сам номер и payload не логируются (OWASP A09).
 */
final class WebAppContactService
{
    private readonly WebAppContactVerifier $verifier;

    public function __construct(
        Config $config,
        private readonly Logger $logger,
    ) {
        $this->verifier = new WebAppContactVerifier($config->apiToken(), $config->webappMaxAge());
    }

    /**
     * Подтверждённый контакт или null: подпись не сошлась, authDate просрочен
     * либо поля пустые.
     */
    public function resolve(string $authDate, string $phone, string $userId, string $hash): ?WebAppContact
    {
        $contact = $this->verifier->resolve($authDate, $phone, $userId, $hash);

        if ($contact === null) {
            $this->logger->log('warning', 'MAX webapp contact verification failed; contact ignored.', [
                'user_id' => is_numeric($userId) ? (int) $userId : null,
            ]);

            return null;
        }

        return $contact;
    }

    /**
     * Только проверка подписи, без возврата контакта.
     */
    public function verify(string $authDate, string $phone, string $userId, string $hash): bool
    {
        return $this->resolve($authDate, $phone, $userId, $hash) !== null;
    }
}
