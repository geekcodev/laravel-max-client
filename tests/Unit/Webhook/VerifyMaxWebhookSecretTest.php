<?php

declare(strict_types=1);

namespace GeekCo\LaravelMaxClient\Tests\Unit\Webhook;

use GeekCo\LaravelMaxClient\MaxServiceProvider;
use GeekCo\LaravelMaxClient\Tests\TestCase;
use GeekCo\LaravelMaxClient\Webhook\VerifyMaxWebhookSecret;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;

final class VerifyMaxWebhookSecretTest extends TestCase
{
    public function testPassesThroughWithValidSecret(): void
    {
        $this->app['config']->set(MaxServiceProvider::CONFIG_KEY . '.webhook.secret', 'test-secret');

        $middleware = $this->app->make(VerifyMaxWebhookSecret::class);
        $request = Request::create('/max/webhook', 'POST', content: '{}');
        $request->headers->set('X-Max-Bot-Api-Secret', 'test-secret');

        $response = $middleware->handle($request, fn (): JsonResponse => new JsonResponse(['ok' => true]));

        $this->assertSame(200, $response->getStatusCode());
    }

    public function testRejectsInvalidSecret(): void
    {
        $this->app['config']->set(MaxServiceProvider::CONFIG_KEY . '.webhook.secret', 'test-secret');

        $middleware = $this->app->make(VerifyMaxWebhookSecret::class);
        $request = Request::create('/max/webhook', 'POST', content: '{}');
        $request->headers->set('X-Max-Bot-Api-Secret', 'wrong-secret');

        try {
            $middleware->handle($request, fn (): JsonResponse => new JsonResponse(['ok' => true]));
            $this->fail('Expected HttpException with status 401.');
        } catch (HttpException $exception) {
            $this->assertSame(401, $exception->getStatusCode());
        }
    }

    public function testRejectsMissingSecretHeader(): void
    {
        $this->app['config']->set(MaxServiceProvider::CONFIG_KEY . '.webhook.secret', 'test-secret');

        $middleware = $this->app->make(VerifyMaxWebhookSecret::class);
        $request = Request::create('/max/webhook', 'POST', content: '{}');

        try {
            $middleware->handle($request, fn (): JsonResponse => new JsonResponse(['ok' => true]));
            $this->fail('Expected HttpException with status 401.');
        } catch (HttpException $exception) {
            $this->assertSame(401, $exception->getStatusCode());
        }
    }

    public function testRejectsRequestsWhenConfigSecretIsEmpty(): void
    {
        $this->app['config']->set(MaxServiceProvider::CONFIG_KEY . '.webhook.secret', '');

        $middleware = $this->app->make(VerifyMaxWebhookSecret::class);
        $request = Request::create('/max/webhook', 'POST', content: '{}');
        $request->headers->set('X-Max-Bot-Api-Secret', 'test-secret');

        try {
            $middleware->handle($request, fn (): JsonResponse => new JsonResponse(['ok' => true]));
            $this->fail('Expected HttpException with status 401.');
        } catch (HttpException $exception) {
            $this->assertSame(401, $exception->getStatusCode());
        }
    }
}
