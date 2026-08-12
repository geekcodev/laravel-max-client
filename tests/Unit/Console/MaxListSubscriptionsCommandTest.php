<?php

declare(strict_types=1);

namespace GeekCo\LaravelMaxClient\Tests\Unit\Console;

use GeekCo\LaravelMaxClient\Tests\Support\MockHttpClient;
use GeekCo\LaravelMaxClient\Tests\TestCase;
use GuzzleHttp\Psr7\Response;
use Psr\Http\Client\ClientInterface;

final class MaxListSubscriptionsCommandTest extends TestCase
{
    public function testCommandIsRegistered(): void
    {
        $this->artisan('max:subscriptions', ['--help' => true])->assertSuccessful();
    }

    public function testListsSubscriptions(): void
    {
        $mock = new MockHttpClient([new Response(200, [], json_encode([
            'subscriptions' => [
                [
                    'url' => 'https://example.com/max/webhook',
                    'update_types' => ['message_created', 'bot_started'],
                ],
                [
                    'url' => 'https://example.org/webhook',
                ],
            ],
        ], JSON_THROW_ON_ERROR))]);
        $this->app->instance(ClientInterface::class, $mock);

        $this->artisan('max:subscriptions')
            ->expectsOutputToContain('https://example.com/max/webhook')
            ->expectsOutputToContain('https://example.org/webhook')
            ->assertSuccessful();

        $this->assertSame(1, $mock->callCount);
    }

    public function testListsUpdateTypes(): void
    {
        $mock = new MockHttpClient([new Response(200, [], json_encode([
            'subscriptions' => [
                [
                    'url' => 'https://example.com/max/webhook',
                    'update_types' => ['message_created', 'bot_started'],
                ],
            ],
        ], JSON_THROW_ON_ERROR))]);
        $this->app->instance(ClientInterface::class, $mock);

        $this->artisan('max:subscriptions')
            ->expectsOutputToContain('message_created, bot_started')
            ->assertSuccessful();
    }

    public function testShowsEmptyState(): void
    {
        $mock = new MockHttpClient([new Response(200, [], json_encode([
            'subscriptions' => [],
        ], JSON_THROW_ON_ERROR))]);
        $this->app->instance(ClientInterface::class, $mock);

        $this->artisan('max:subscriptions')
            ->expectsOutputToContain('Подписок нет.')
            ->assertSuccessful();
    }

    public function testFailsOnApiError(): void
    {
        $mock = new MockHttpClient([new Response(400, [], '{}')]);
        $this->app->instance(ClientInterface::class, $mock);

        $this->artisan('max:subscriptions')
            ->expectsOutputToContain('Не удалось получить подписки')
            ->assertFailed();
    }
}
