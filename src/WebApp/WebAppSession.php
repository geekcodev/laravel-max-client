<?php

declare(strict_types=1);

namespace GeekCo\LaravelMaxClient\WebApp;

use GeekCo\LaravelMaxClient\Support\Config;
use Illuminate\Http\Request;

/**
 * Read-сторона WebApp-сессии: те же ключи, которые пишет middleware
 * max.webapp (ResolveWebAppIdentity).
 *
 * contact_confirmed — флаг подтверждённого контакта: его значение — это
 * user_id, которому контакт подтверждён (см. WebAppContactService). confirm()
 * регенерирует session id — защита от session fixation при переходе
 * от анонимного (демо-)открытия к подтверждённой идентичности.
 */
final readonly class WebAppSession
{
    public function __construct(
        private Config $config,
    ) {
    }

    /**
     * user_id из сессии или null (нет сессии/идентичности).
     */
    public function userId(Request $request): ?int
    {
        return $this->sessionInt($request, $this->config->webappSessionUserIdKey());
    }

    /**
     * chat_id из сессии или null.
     */
    public function chatId(Request $request): ?int
    {
        return $this->sessionInt($request, $this->config->webappSessionChatIdKey());
    }

    /**
     * Подтверждён ли контакт именно для переданного user_id.
     */
    public function contactConfirmed(Request $request, int $userId): bool
    {
        return $request->session()->get($this->config->webappSessionContactConfirmedKey()) === $userId;
    }

    /**
     * Ставит флаг подтверждения, регенерируя session id (anti-fixation).
     */
    public function confirm(Request $request, int $userId): void
    {
        $request->session()->regenerate();

        $request->session()->put($this->config->webappSessionContactConfirmedKey(), $userId);
    }

    /**
     * Снимает флаг подтверждения (выход / смена аккаунта).
     */
    public function forget(Request $request): void
    {
        $request->session()->forget($this->config->webappSessionContactConfirmedKey());
    }

    private function sessionInt(Request $request, string $key): ?int
    {
        $value = $request->session()->get($key);

        return is_numeric($value) ? (int) $value : null;
    }
}
