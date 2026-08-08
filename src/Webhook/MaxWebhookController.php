<?php

declare(strict_types=1);

namespace GeekCo\LaravelMaxClient\Webhook;

use GeekCo\LaravelMaxClient\Support\Config;
use GeekCo\MaxPhpClient\Dto\Update;
use GeekCo\MaxPhpClient\Exception\InvalidResponseException;
use GeekCo\MaxPhpClient\Webhook\WebhookHandler;
use GuzzleHttp\Psr7\Request as Psr7Request;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

final class MaxWebhookController
{
    public function __construct(
        private readonly Config $config,
        private readonly Dispatcher $dispatcher,
    ) {
    }

    public function __invoke(Request $request): JsonResponse
    {
        $headers = array_map(
            static fn (array $values): array => array_values(array_filter($values, 'is_string')),
            $request->headers->all(),
        );

        $psrRequest = new Psr7Request(
            $request->method(),
            $request->fullUrl(),
            $headers,
            (string) $request->getContent(),
        );

        $handler = new WebhookHandler($this->config->webhookSecret());

        if (!$handler->verify($psrRequest)) {
            return response()->json(['error' => 'invalid_secret'], 401);
        }

        try {
            $decoded = $handler->decode($psrRequest);
        } catch (InvalidResponseException $exception) {
            Log::warning('MAX webhook: invalid payload rejected.', [
                'exception' => $exception::class,
                'code' => $exception->getCode(),
            ]);

            return response()->json(['error' => 'invalid_payload'], 400);
        }

        if (!$this->dispatcher->hasListeners(MaxUpdateReceived::class)) {
            return response()->json(['ok' => true]);
        }

        $updates = $decoded instanceof Update ? [$decoded] : $decoded;
        foreach ($updates as $update) {
            HandleMaxUpdateJob::dispatch($update)->onQueue($this->config->webhookQueue());
        }

        return response()->json(['ok' => true]);
    }
}
