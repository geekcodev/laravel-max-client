<?php

declare(strict_types=1);

namespace GeekCo\LaravelMaxClient\Tests\Unit\WebApp;

use GeekCo\LaravelMaxClient\MaxServiceProvider;
use GeekCo\LaravelMaxClient\Tests\TestCase;
use GeekCo\LaravelMaxClient\WebApp\WebAppSession;
use Illuminate\Http\Request;
use Illuminate\Session\Store;

final class WebAppSessionTest extends TestCase
{
    public function testReadsUserIdAndChatIdFromSession(): void
    {
        $session = $this->sessionStore();
        $session->put('user_id', 67890);
        $session->put('chat_id', 12345);
        $request = $this->requestWith($session);

        $webAppSession = $this->app->make(WebAppSession::class);

        $this->assertSame(67890, $webAppSession->userId($request));
        $this->assertSame(12345, $webAppSession->chatId($request));
    }

    public function testReturnsNullWhenIdentityIsMissing(): void
    {
        $request = $this->requestWith($this->sessionStore());

        $webAppSession = $this->app->make(WebAppSession::class);

        $this->assertNull($webAppSession->userId($request));
        $this->assertNull($webAppSession->chatId($request));
    }

    public function testNonNumericSessionValueYieldsNull(): void
    {
        $session = $this->sessionStore();
        $session->put('user_id', 'not-a-number');
        $request = $this->requestWith($session);

        $this->assertNull($this->app->make(WebAppSession::class)->userId($request));
    }

    public function testUsesConfiguredSessionKeys(): void
    {
        $this->app['config']->set(MaxServiceProvider::CONFIG_KEY . '.webapp.session.user_id', 'max_user_id');
        $this->app['config']->set(MaxServiceProvider::CONFIG_KEY . '.webapp.session.chat_id', 'max_chat_id');
        $session = $this->sessionStore();
        $session->put('max_user_id', 67890);
        $session->put('max_chat_id', 12345);
        $request = $this->requestWith($session);

        $webAppSession = $this->app->make(WebAppSession::class);

        $this->assertSame(67890, $webAppSession->userId($request));
        $this->assertSame(12345, $webAppSession->chatId($request));
    }

    public function testConfirmRegeneratesSessionIdAndSetsFlag(): void
    {
        $session = $this->sessionStore();
        $request = $this->requestWith($session);
        $previousId = $session->getId();

        $this->app->make(WebAppSession::class)->confirm($request, 67890);

        $this->assertNotSame($previousId, $session->getId());
        $this->assertSame(67890, $session->get('contact_confirmed'));
    }

    public function testContactConfirmedMatchesStoredUserOnly(): void
    {
        $session = $this->sessionStore();
        $request = $this->requestWith($session);
        $webAppSession = $this->app->make(WebAppSession::class);

        $webAppSession->confirm($request, 67890);

        $this->assertTrue($webAppSession->contactConfirmed($request, 67890));
        $this->assertFalse($webAppSession->contactConfirmed($request, 11111));
    }

    public function testContactConfirmedIsFalseBeforeConfirm(): void
    {
        $request = $this->requestWith($this->sessionStore());

        $this->assertFalse($this->app->make(WebAppSession::class)->contactConfirmed($request, 67890));
    }

    public function testForgetRemovesFlag(): void
    {
        $session = $this->sessionStore();
        $request = $this->requestWith($session);
        $webAppSession = $this->app->make(WebAppSession::class);

        $webAppSession->confirm($request, 67890);
        $webAppSession->forget($request);

        $this->assertFalse($webAppSession->contactConfirmed($request, 67890));
        $this->assertNull($session->get('contact_confirmed'));
    }

    public function testUsesConfiguredContactConfirmedKey(): void
    {
        $this->app['config']->set(MaxServiceProvider::CONFIG_KEY . '.webapp.session.contact_confirmed', 'max_contact');
        $session = $this->sessionStore();
        $request = $this->requestWith($session);
        $webAppSession = $this->app->make(WebAppSession::class);

        $webAppSession->confirm($request, 67890);

        $this->assertSame(67890, $session->get('max_contact'));
        $this->assertNull($session->get('contact_confirmed'));
        $this->assertTrue($webAppSession->contactConfirmed($request, 67890));
    }

    private function sessionStore(): Store
    {
        return $this->app->make('session')->driver();
    }

    private function requestWith(Store $session): Request
    {
        $request = Request::create('/webapp', 'GET');
        $request->setLaravelSession($session);

        return $request;
    }
}
