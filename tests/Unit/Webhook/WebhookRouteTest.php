<?php

declare(strict_types=1);

namespace GeekCo\LaravelMaxClient\Tests\Unit\Webhook;

use GeekCo\LaravelMaxClient\MaxServiceProvider;
use GeekCo\LaravelMaxClient\Tests\TestCase;
use GeekCo\LaravelMaxClient\Webhook\MaxWebhookController;
use GeekCo\LaravelMaxClient\Webhook\VerifyMaxWebhookSecret;
use Illuminate\Routing\Route;
use Orchestra\Testbench\Attributes\DefineEnvironment;

final class WebhookRouteTest extends TestCase
{
    public function testRouteIsNotRegisteredByDefault(): void
    {
        $this->assertFalse($this->app->router->has('max.webhook'));
    }

    #[DefineEnvironment('enableWebhook')]
    public function testRouteIsRegisteredWhenEnabledWithSecret(): void
    {
        $this->assertTrue($this->app->router->has('max.webhook'));

        $route = $this->app->router->getRoutes()->getByName('max.webhook');
        $this->assertInstanceOf(Route::class, $route);
        $this->assertSame(['POST'], $route->methods());
        $this->assertSame('max/webhook', $route->uri());
        $this->assertSame(MaxWebhookController::class, $route->getActionName());
        $this->assertContains(VerifyMaxWebhookSecret::class, $route->middleware());
    }

    #[DefineEnvironment('enableWebhookWithoutSecret')]
    public function testRouteIsNotRegisteredWhenSecretIsMissing(): void
    {
        $this->assertFalse($this->app->router->has('max.webhook'));
    }

    #[DefineEnvironment('enableWebhookWithoutFlag')]
    public function testRouteIsNotRegisteredWhenDisabled(): void
    {
        $this->assertFalse($this->app->router->has('max.webhook'));
    }

    #[DefineEnvironment('enableWebhookWithCustomPath')]
    public function testRouteUsesConfiguredPath(): void
    {
        $route = $this->app->router->getRoutes()->getByName('max.webhook');

        $this->assertInstanceOf(Route::class, $route);
        $this->assertSame('custom/webhook', $route->uri());
    }

    protected function enableWebhook($app): void
    {
        $app['config']->set(MaxServiceProvider::CONFIG_KEY . '.webhook.enabled', true);
        $app['config']->set(MaxServiceProvider::CONFIG_KEY . '.webhook.secret', 'test-secret');
    }

    protected function enableWebhookWithoutSecret($app): void
    {
        $app['config']->set(MaxServiceProvider::CONFIG_KEY . '.webhook.enabled', true);
        $app['config']->set(MaxServiceProvider::CONFIG_KEY . '.webhook.secret', '');
    }

    protected function enableWebhookWithoutFlag($app): void
    {
        $app['config']->set(MaxServiceProvider::CONFIG_KEY . '.webhook.enabled', false);
        $app['config']->set(MaxServiceProvider::CONFIG_KEY . '.webhook.secret', 'test-secret');
    }

    protected function enableWebhookWithCustomPath($app): void
    {
        $app['config']->set(MaxServiceProvider::CONFIG_KEY . '.webhook.enabled', true);
        $app['config']->set(MaxServiceProvider::CONFIG_KEY . '.webhook.secret', 'test-secret');
        $app['config']->set(MaxServiceProvider::CONFIG_KEY . '.webhook.path', '/custom/webhook');
    }
}
