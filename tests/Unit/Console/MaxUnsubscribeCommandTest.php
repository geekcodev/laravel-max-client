<?php

declare(strict_types=1);

namespace GeekCo\LaravelMaxClient\Tests\Unit\Console;

use GeekCo\LaravelMaxClient\Tests\Support\MockHttpClient;
use GeekCo\LaravelMaxClient\Tests\TestCase;
use GuzzleHttp\Psr7\Response;
use Psr\Http\Client\ClientInterface;

final class MaxUnsubscribeCommandTest extends TestCase
{
    private const URL = 'https://example.com/max/webhook';

    public function testCommandIsRegistered(): void
    {
        $this->artisan('max:unsubscribe', ['--help' => true])->assertSuccessful();
    }

    public function testDeletesSubscription(): void
    {
        $mock = new MockHttpClient([new Response(200, [], json_encode(['success' => true], JSON_THROW_ON_ERROR))]);
        $this->app->instance(ClientInterface::class, $mock);

        $this->artisan('max:unsubscribe', ['url' => self::URL])
            ->expectsOutputToContain('Подписка удалена')
            ->assertSuccessful();

        $this->assertSame(1, $mock->callCount);
        $this->assertStringContainsString('url=' . urlencode(self::URL), (string) $mock->lastRequest?->getUri());
    }

    public function testFailsOnApiError(): void
    {
        $mock = new MockHttpClient([new Response(400, [], '{}')]);
        $this->app->instance(ClientInterface::class, $mock);

        $this->artisan('max:unsubscribe', ['url' => self::URL])
            ->expectsOutputToContain('Не удалось удалить подписку')
            ->assertFailed();
    }
}
