<?php

declare(strict_types=1);

namespace GeekCo\LaravelMaxClient\Tests\Unit\Http;

use GeekCo\LaravelMaxClient\Http\Middleware\SetMaxFrameAncestors;
use GeekCo\LaravelMaxClient\MaxServiceProvider;
use GeekCo\LaravelMaxClient\Tests\TestCase;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class SetMaxFrameAncestorsTest extends TestCase
{
    public function testSetsDefaultFrameAncestors(): void
    {
        $response = $this->handle(fn (): Response => new Response('ok'));

        $this->assertSame(
            "frame-ancestors 'self' https://max.ru https://web.max.ru",
            $response->headers->get('Content-Security-Policy'),
        );
    }

    public function testAppendsToExistingCspHeader(): void
    {
        $response = $this->handle(static function (Request $request): Response {
            $response = new Response('ok');
            $response->headers->set('Content-Security-Policy', "default-src 'self'");

            return $response;
        });

        $this->assertSame(
            "default-src 'self'; frame-ancestors 'self' https://max.ru https://web.max.ru",
            $response->headers->get('Content-Security-Policy'),
        );
    }

    public function testUsesConfiguredHosts(): void
    {
        $this->app['config']->set(
            MaxServiceProvider::CONFIG_KEY . '.webapp.frame_ancestors.hosts',
            ['https://example.com'],
        );

        $response = $this->handle(fn (): Response => new Response('ok'));

        $this->assertSame(
            "frame-ancestors 'self' https://example.com",
            $response->headers->get('Content-Security-Policy'),
        );
    }

    public function testSkippedWhenDisabled(): void
    {
        $this->app['config']->set(
            MaxServiceProvider::CONFIG_KEY . '.webapp.frame_ancestors.enabled',
            false,
        );

        $response = $this->handle(fn (): Response => new Response('ok'));

        $this->assertNull($response->headers->get('Content-Security-Policy'));
    }

    private function handle(callable $next): Response
    {
        $middleware = $this->app->make(SetMaxFrameAncestors::class);

        return $middleware->handle(Request::create('/webapp', 'GET'), $next);
    }
}
