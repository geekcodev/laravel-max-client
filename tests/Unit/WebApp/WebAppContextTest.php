<?php

declare(strict_types=1);

namespace GeekCo\LaravelMaxClient\Tests\Unit\WebApp;

use GeekCo\LaravelMaxClient\MaxServiceProvider;
use GeekCo\LaravelMaxClient\Tests\TestCase;
use GeekCo\LaravelMaxClient\WebApp\WebAppContext;
use GeekCo\MaxPhpClient\Dto\WebAppIdentity;
use Illuminate\Http\Request;

final class WebAppContextTest extends TestCase
{
    private const TOKEN = 'test-token';

    public function testResolvesIdentityFromValidWebAppData(): void
    {
        $context = $this->app->make(WebAppContext::class);
        $request = $this->requestWith($this->validWebAppData(time()));

        $identity = $context->resolve($request);

        $this->assertInstanceOf(WebAppIdentity::class, $identity);
        $this->assertSame(67890, $identity?->userId);
        $this->assertSame(12345, $identity?->chatId);
    }

    public function testVerifiesValidWebAppData(): void
    {
        $context = $this->app->make(WebAppContext::class);

        $this->assertTrue($context->verify($this->requestWith($this->validWebAppData(time()))));
    }

    public function testRejectsMissingWebAppData(): void
    {
        $context = $this->app->make(WebAppContext::class);

        $this->assertNull($context->resolve(new Request()));
        $this->assertFalse($context->verify(new Request()));
    }

    public function testRejectsNonStringWebAppData(): void
    {
        $context = $this->app->make(WebAppContext::class);

        $this->assertNull($context->resolve(new Request(['WebAppData' => ['a', 'b']])));
        $this->assertFalse($context->verify(new Request(['WebAppData' => ['a', 'b']])));
    }

    public function testRejectsInvalidSignature(): void
    {
        $context = $this->app->make(WebAppContext::class);
        $tampered = str_replace('ip=192.168.0.1', 'ip=10.0.0.1', $this->validWebAppData());

        $this->assertNull($context->resolve($this->requestWith($tampered)));
        $this->assertFalse($context->verify($this->requestWith($tampered)));
    }

    public function testRejectsStaleAuthDate(): void
    {
        $this->app['config']->set(MaxServiceProvider::CONFIG_KEY . '.webapp.max_age', 60);
        $context = $this->app->make(WebAppContext::class);

        $this->assertNull($context->resolve($this->requestWith($this->validWebAppData(time() - 3600))));
    }

    public function testAcceptsFreshAuthDate(): void
    {
        $this->app['config']->set(MaxServiceProvider::CONFIG_KEY . '.webapp.max_age', 60);
        $context = $this->app->make(WebAppContext::class);

        $identity = $context->resolve($this->requestWith($this->validWebAppData(time())));

        $this->assertSame(67890, $identity?->userId);
    }

    public function testUsesConfiguredMaxAge(): void
    {
        $this->app['config']->set(MaxServiceProvider::CONFIG_KEY . '.webapp.max_age', 0);
        $context = $this->app->make(WebAppContext::class);

        $identity = $context->resolve($this->requestWith($this->validWebAppData(time() - 3600)));

        $this->assertSame(67890, $identity?->userId);
    }

    private function requestWith(string $webAppData): Request
    {
        return new Request(['WebAppData' => $webAppData]);
    }

    private function validWebAppData(?int $authDate = null): string
    {
        $authDate ??= 1771409719;

        $launchParams = "auth_date={$authDate}\n"
            . "chat={\"id\":12345,\"type\":\"DIALOG\"}\n"
            . "ip=192.168.0.1\n"
            . "query_id=4c0ab423-342b-4e45-aea4-2747dbc500cd\n"
            . "user={\"id\":67890,\"first_name\":\"Max\",\"last_name\":\"User\",\"username\":null,\"language_code\":\"ru\",\"photo_url\":null}";

        $secretKey = hash_hmac('sha256', self::TOKEN, 'WebAppData', binary: true);
        $hash = hash_hmac('sha256', $launchParams, $secretKey);

        return implode('&', [
            'user=' . rawurlencode('{"id":67890,"first_name":"Max","last_name":"User","username":null,"language_code":"ru","photo_url":null}'),
            'ip=192.168.0.1',
            'chat=' . rawurlencode('{"id":12345,"type":"DIALOG"}'),
            "auth_date={$authDate}",
            'query_id=4c0ab423-342b-4e45-aea4-2747dbc500cd',
            'hash=' . $hash,
        ]);
    }
}
