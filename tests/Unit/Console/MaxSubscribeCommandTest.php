<?php

declare(strict_types=1);

namespace GeekCo\LaravelMaxClient\Tests\Unit\Console;

use GeekCo\LaravelMaxClient\MaxServiceProvider;
use GeekCo\LaravelMaxClient\Tests\Support\MockHttpClient;
use GeekCo\LaravelMaxClient\Tests\TestCase;
use GuzzleHttp\Psr7\Response;
use Psr\Http\Client\ClientInterface;

final class MaxSubscribeCommandTest extends TestCase
{
    private const URL = 'https://example.com/max/webhook';

    public function testCommandIsRegistered(): void
    {
        $this->artisan('max:subscribe', ['--help' => true])->assertSuccessful();
    }

    public function testCreatesSubscriptionWithSecretAndUpdateTypes(): void
    {
        $this->app['config']->set(MaxServiceProvider::CONFIG_KEY . '.webhook.secret', 'top-secret');
        $mock = $this->mockSubscription();

        $this->artisan('max:subscribe', ['url' => self::URL])
            ->expectsOutputToContain('Подписка создана')
            ->assertSuccessful();

        $this->assertSame(1, $mock->callCount);
        $body = $this->requestBody($mock);
        $this->assertSame(self::URL, $body['url']);
        $this->assertSame('top-secret', $body['secret']);
        $this->assertSame([
            'message_created',
            'message_callback',
            'bot_added',
            'bot_started',
            'bot_stopped',
            'bot_removed',
        ], $body['update_types']);
    }

    public function testCreatesSubscriptionWithoutSecretWhenNotConfigured(): void
    {
        $mock = $this->mockSubscription();

        $this->artisan('max:subscribe', ['url' => self::URL])
            ->expectsOutputToContain('без секрета')
            ->assertSuccessful();

        $body = $this->requestBody($mock);
        $this->assertArrayNotHasKey('secret', $body);
    }

    public function testRejectsNonStringUrl(): void
    {
        $mock = new MockHttpClient();
        $this->app->instance(ClientInterface::class, $mock);

        $this->artisan('max:subscribe', ['url' => ['one', 'two']])
            ->expectsOutputToContain('URL должен быть строкой')
            ->assertFailed();

        $this->assertSame(0, $mock->callCount);
    }

    public function testRejectsMalformedUrl(): void
    {
        $mock = new MockHttpClient();
        $this->app->instance(ClientInterface::class, $mock);

        $this->artisan('max:subscribe', ['url' => 'not-a-url'])
            ->expectsOutputToContain('HTTPS')
            ->assertFailed();

        $this->assertSame(0, $mock->callCount);
    }

    public function testRejectsNonHttpsUrl(): void
    {
        $mock = new MockHttpClient();
        $this->app->instance(ClientInterface::class, $mock);

        $this->artisan('max:subscribe', ['url' => 'http://example.com/max/webhook'])
            ->expectsOutputToContain('HTTPS')
            ->assertFailed();

        $this->assertSame(0, $mock->callCount);
    }

    public function testRejectsUrlOutsideAllowedHosts(): void
    {
        $this->app['config']->set(MaxServiceProvider::CONFIG_KEY . '.webhook.allowed_hosts', ['allowed.example.com']);

        $this->artisan('max:subscribe', ['url' => self::URL])
            ->expectsOutputToContain('allowed_hosts')
            ->assertFailed();
    }

    public function testAcceptsUrlFromAllowedHosts(): void
    {
        $this->app['config']->set(MaxServiceProvider::CONFIG_KEY . '.webhook.allowed_hosts', ['example.com']);
        $mock = $this->mockSubscription();

        $this->artisan('max:subscribe', ['url' => self::URL])->assertSuccessful();

        $this->assertSame(1, $mock->callCount);
    }

    public function testFailsOnApiError(): void
    {
        $mock = new MockHttpClient([new Response(400, [], '{}')]);
        $this->app->instance(ClientInterface::class, $mock);

        $this->artisan('max:subscribe', ['url' => self::URL])
            ->expectsOutputToContain('Не удалось создать подписку')
            ->assertFailed();
    }

    private function mockSubscription(): MockHttpClient
    {
        $mock = new MockHttpClient([new Response(200, [], json_encode([
            'success' => true,
        ], JSON_THROW_ON_ERROR))]);
        $this->app->instance(ClientInterface::class, $mock);

        return $mock;
    }

    /**
     * @return array<string, mixed>
     */
    private function requestBody(MockHttpClient $mock): array
    {
        $body = json_decode((string) $mock->lastRequest?->getBody(), true);

        return is_array($body) ? $body : [];
    }
}
