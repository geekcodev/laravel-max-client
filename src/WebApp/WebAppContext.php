<?php

declare(strict_types=1);

namespace GeekCo\LaravelMaxClient\WebApp;

use GeekCo\MaxPhpClient\Dto\WebAppIdentity;
use GeekCo\MaxPhpClient\Security\WebAppDataValidator;
use Illuminate\Http\Request;

/**
 * Верификация и извлечение идентификации из стартовых данных мини-приложения MAX.
 *
 * Тонкая Laravel-обёртка над ядром WebAppDataValidator: принимает Illuminate Request,
 * все криптографические проверки делегирует ядру.
 */
final readonly class WebAppContext
{
    public function __construct(
        private WebAppDataValidator $validator,
    ) {
    }

    /**
     * Верифицированы ли данные открытия мини-приложения.
     */
    public function verify(Request $request): bool
    {
        $webAppData = $request->query('WebAppData');

        return is_string($webAppData) && $this->validator->verify($webAppData);
    }

    /**
     * Верифицированные user_id/chat_id или null (нет данных / подпись неверна / устарел auth_date).
     */
    public function resolve(Request $request): ?WebAppIdentity
    {
        $webAppData = $request->query('WebAppData');

        return is_string($webAppData) ? $this->validator->resolve($webAppData) : null;
    }
}
