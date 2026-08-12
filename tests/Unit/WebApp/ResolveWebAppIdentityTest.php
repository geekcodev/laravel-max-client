<?php

declare(strict_types=1);

namespace GeekCo\LaravelMaxClient\Tests\Unit\WebApp;

use GeekCo\LaravelMaxClient\MaxServiceProvider;
use GeekCo\LaravelMaxClient\Tests\TestCase;
use GeekCo\LaravelMaxClient\WebApp\ResolveWebAppIdentity;
use GeekCo\MaxPhpClient\Dto\WebAppIdentity;
use Illuminate\Http\Request;
use Illuminate\Session\Store;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;

final class ResolveWebAppIdentityTest extends TestCase
{
    private const TOKEN = 'test-token';

    public function testStoresIdentityInSessionAndRequestAttribute(): void
    {
        $session = $this->sessionStore();
        $request = $this->requestWith($this->validWebAppData());
        $request->setLaravelSession($session);

        $response = $this->app->make(ResolveWebAppIdentity::class)->handle(
            $request,
            static fn (Request $r): Response => new Response('ok'),
        );

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(67890, $session->get('user_id'));
        $this->assertSame(12345, $session->get('chat_id'));

        $identity = $request->attributes->get(ResolveWebAppIdentity::REQUEST_ATTRIBUTE);
        $this->assertInstanceOf(WebAppIdentity::class, $identity);
        $this->assertSame(67890, $identity->userId);
    }

    public function testPassesThroughWithoutIdentityInDemoMode(): void
    {
        $session = $this->sessionStore();
        $request = Request::create('/webapp', 'GET');
        $request->setLaravelSession($session);

        $response = $this->app->make(ResolveWebAppIdentity::class)->handle(
            $request,
            static fn (Request $r): Response => new Response('ok'),
        );

        $this->assertSame(200, $response->getStatusCode());
        $this->assertNull($session->get('user_id'));
        $this->assertNull($session->get('chat_id'));
    }

    public function testRejectsMissingIdentityInStrictMode(): void
    {
        $this->app['config']->set(MaxServiceProvider::CONFIG_KEY.'.webapp.strict', true);
        $request = Request::create('/webapp', 'GET');
        $request->setLaravelSession($this->sessionStore());

        try {
            $this->app->make(ResolveWebAppIdentity::class)->handle(
                $request,
                static fn (Request $r): Response => new Response('ok'),
            );
            $this->fail('Expected HttpException with status 403.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }
    }

    public function testRejectsInvalidSignatureInStrictMode(): void
    {
        $this->app['config']->set(MaxServiceProvider::CONFIG_KEY.'.webapp.strict', true);
        $request = $this->requestWith($this->validWebAppData().'tampered');
        $request->setLaravelSession($this->sessionStore());

        try {
            $this->app->make(ResolveWebAppIdentity::class)->handle(
                $request,
                static fn (Request $r): Response => new Response('ok'),
            );
            $this->fail('Expected HttpException with status 403.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }
    }

    public function testUsesConfiguredSessionKeys(): void
    {
        $this->app['config']->set(MaxServiceProvider::CONFIG_KEY.'.webapp.session.user_id', 'max_user_id');
        $this->app['config']->set(MaxServiceProvider::CONFIG_KEY.'.webapp.session.chat_id', 'max_chat_id');
        $session = $this->sessionStore();
        $request = $this->requestWith($this->validWebAppData());
        $request->setLaravelSession($session);

        $this->app->make(ResolveWebAppIdentity::class)->handle(
            $request,
            static fn (Request $r): Response => new Response('ok'),
        );

        $this->assertSame(67890, $session->get('max_user_id'));
        $this->assertSame(12345, $session->get('max_chat_id'));
        $this->assertNull($session->get('user_id'));
    }

    private function sessionStore(): Store
    {
        return $this->app->make('session')->driver();
    }

    private function requestWith(string $webAppData): Request
    {
        return Request::create('/webapp', 'GET', ['WebAppData' => $webAppData]);
    }

    private function validWebAppData(): string
    {
        $authDate = time();

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
