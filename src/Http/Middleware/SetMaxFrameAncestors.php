<?php

declare(strict_types=1);

namespace GeekCo\LaravelMaxClient\Http\Middleware;

use Closure;
use GeekCo\LaravelMaxClient\Support\Config;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Добавляет в Content-Security-Policy директиву frame-ancestors, разрешающую
 * встраивание мини-приложения в MAX (по умолчанию max.ru / web.max.ru).
 *
 * Алиас: max.csp. Если CSP-заголовок уже задан (например, приложением) —
 * директива дописывается к нему, иначе заголовок создаётся. Флаг отключения —
 * webapp.frame_ancestors.enabled (env MAX_WEBAPP_CSP_ENABLED).
 */
final class SetMaxFrameAncestors
{
    public function __construct(
        private readonly Config $config,
    ) {
    }

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if (!$this->config->webappCspEnabled()) {
            return $response;
        }

        $hosts = implode(' ', $this->config->webappFrameAncestors());
        $directive = "frame-ancestors 'self' {$hosts}";

        $existing = $response->headers->get('Content-Security-Policy', '') ?? '';

        $response->headers->set(
            'Content-Security-Policy',
            $existing === '' ? $directive : $existing.'; '.$directive,
        );

        return $response;
    }
}
