<?php

declare(strict_types=1);

namespace GeekCo\LaravelMaxClient\WebApp;

use Closure;
use GeekCo\LaravelMaxClient\Support\Config;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Верифицирует WebAppData и кладёт user_id/chat_id в сессию.
 *
 * Алиас: max.webapp. При отсутствии/невалидности данных и webapp.strict=true
 * отвечает 403, иначе пропускает запрос без идентификации (демо-режим).
 * Верифицированная идентичность кладётся в атрибут запроса
 * ResolveWebAppIdentity::REQUEST_ATTRIBUTE.
 */
final class ResolveWebAppIdentity
{
    public const REQUEST_ATTRIBUTE = 'max.webapp_identity';

    public function __construct(
        private readonly WebAppContext $webAppContext,
        private readonly Config $config,
    ) {
    }

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $identity = $this->webAppContext->resolve($request);

        if ($identity === null) {
            if ($this->config->webappStrict()) {
                abort(403);
            }

            return $next($request);
        }

        $request->attributes->set(self::REQUEST_ATTRIBUTE, $identity);
        $request->session()->put([
            $this->config->webappSessionUserIdKey() => $identity->userId,
            $this->config->webappSessionChatIdKey() => $identity->chatId,
        ]);

        return $next($request);
    }
}
