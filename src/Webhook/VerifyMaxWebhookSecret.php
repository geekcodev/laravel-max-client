<?php

declare(strict_types=1);

namespace GeekCo\LaravelMaxClient\Webhook;

use Closure;
use GeekCo\LaravelMaxClient\Support\Config;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class VerifyMaxWebhookSecret
{
    private const SECRET_HEADER = 'X-Max-Bot-Api-Secret';

    public function __construct(
        private readonly Config $config,
    ) {
    }

    /**
     * @param Closure(Request): Response $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $secret = $this->config->webhookSecret();
        $provided = $request->headers->get(self::SECRET_HEADER, '') ?? '';

        if ($secret === null || !hash_equals($secret, $provided)) {
            abort(401);
        }

        return $next($request);
    }
}
