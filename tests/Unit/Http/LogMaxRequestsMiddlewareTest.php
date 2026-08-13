<?php

declare(strict_types=1);

namespace GeekCo\LaravelMaxClient\Tests\Unit\Http;

use GeekCo\LaravelMaxClient\Http\Middleware\LogMaxRequestsMiddleware;
use GeekCo\LaravelMaxClient\MaxServiceProvider;
use GeekCo\LaravelMaxClient\Tests\TestCase;
use Illuminate\Http\Request;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

final class LogMaxRequestsMiddlewareTest extends TestCase
{
    /**
     * @var list<array{0: string, 1: string, 2: array<string, mixed>}>
     */
    private array $logged = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->logged = [];
        Log::listen(function (MessageLogged $event): void {
            $this->logged[] = [$event->level, $event->message, $event->context];
        });
    }

    public function testDoesNotLogWhenDisabled(): void
    {
        $response = $this->handle(fn (): Response => new Response('ok'));

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame([], $this->logged);
    }

    public function testLogsRequestAndResponseWhenEnabled(): void
    {
        $this->enableLogging();

        $response = $this->handle(fn (): Response => new Response('ok'));

        $this->assertSame(200, $response->getStatusCode());
        $this->assertCount(2, $this->logged);

        [$requestLevel, $requestMessage, $requestContext] = $this->logged[0];
        $this->assertSame('info', $requestLevel);
        $this->assertSame('Incoming MAX request', $requestMessage);
        $this->assertSame('POST', $requestContext['method']);
        $this->assertArrayHasKey('url', $requestContext);
        $this->assertArrayNotHasKey('body', $requestContext);

        [, , $responseContext] = $this->logged[1];
        $this->assertSame('MAX response', $this->logged[1][1]);
        $this->assertSame(200, $responseContext['status']);
        $this->assertArrayHasKey('duration_ms', $responseContext);
    }

    public function testLogsBodyAndMasksSensitiveDataWhenConfigured(): void
    {
        $this->enableLogging([
            'log_request_body' => true,
            'log_response_body' => true,
        ]);

        $request = Request::create(
            '/max/webhook',
            'POST',
            [],
            [],
            [],
            [],
            json_encode([
                'update_type' => 'message_created',
                'user' => ['token' => 'secret-token', 'name' => 'Ivan'],
            ], JSON_THROW_ON_ERROR),
        );

        $response = $this->handle(
            static fn (): Response => new Response(
                json_encode(['api_key' => 'sk-123'], JSON_THROW_ON_ERROR),
                200,
                ['Content-Type' => 'application/json'],
            ),
            $request,
        );

        $this->assertSame(200, $response->getStatusCode());
        $this->assertCount(2, $this->logged);

        $requestBody = $this->logged[0][2]['body'];
        $this->assertIsArray($requestBody);
        $this->assertSame('***', $requestBody['user']['token']);
        $this->assertSame('Ivan', $requestBody['user']['name']);

        $responseBody = $this->logged[1][2]['body'];
        $this->assertIsArray($responseBody);
        $this->assertSame('***', $responseBody['api_key']);
    }

    public function testBodyIsNotLoggedByDefault(): void
    {
        $this->enableLogging();

        $request = Request::create(
            '/max/webhook',
            'POST',
            [],
            [],
            [],
            [],
            json_encode(['update_type' => 'message_created'], JSON_THROW_ON_ERROR),
        );

        $this->handle(static fn (): Response => new Response('ok'), $request);

        $this->assertCount(2, $this->logged);
        $this->assertArrayNotHasKey('body', $this->logged[0][2]);
        $this->assertArrayNotHasKey('body', $this->logged[1][2]);
    }

    public function testExcludedPathSkipsLogging(): void
    {
        $this->enableLogging(['exclude_paths' => ['max/webhook']]);

        $request = Request::create('/max/webhook', 'POST');

        $this->handle(static fn (): Response => new Response('ok'), $request);

        $this->assertSame([], $this->logged);
    }

    public function testResponseLevelReflectsStatus(): void
    {
        $this->enableLogging();

        $this->handle(static fn (): Response => new Response('error', 422));
        $this->assertSame('warning', $this->logged[1][0]);

        $this->logged = [];
        $this->handle(static fn (): Response => new Response('error', 503));
        $this->assertSame('error', $this->logged[1][0]);
    }

    public function testProxiesXRequestId(): void
    {
        $request = Request::create('/max/webhook', 'POST');
        $request->headers->set('X-Request-ID', 'req-123');

        $response = $this->handle(static fn (): Response => new Response('ok'), $request);

        $this->assertSame('req-123', $response->headers->get('X-Request-ID'));
    }

    public function testFallsBackToStackWhenChannelsAreMissing(): void
    {
        $this->enableLogging([
            'channel' => 'missing-channel',
            'fallback_channel' => 'another-missing-channel',
        ]);

        $this->handle(static fn (): Response => new Response('ok'));

        $messages = array_column($this->logged, 1);
        $this->assertContains('Incoming MAX request', $messages);
        $this->assertContains('MAX response', $messages);
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function enableLogging(array $overrides = []): void
    {
        $this->app['config']->set(
            MaxServiceProvider::CONFIG_KEY . '.logging',
            array_replace([
                'enabled' => true,
                'channel' => 'stack',
                'fallback_channel' => 'laravel-max-client',
                'log_request_body' => false,
                'log_response_body' => false,
                'log_response_body_max_length' => 1000,
                'exclude_paths' => [],
                'exclude_request_body_paths' => [],
                'exclude_response_body_paths' => [],
            ], $overrides),
        );
    }

    private function handle(callable $next, ?Request $request = null): Response
    {
        $middleware = $this->app->make(LogMaxRequestsMiddleware::class);

        return $middleware->handle($request ?? Request::create('/max/webhook', 'POST'), $next);
    }
}
