<?php

declare(strict_types=1);

namespace GeekCo\LaravelMaxClient\Tests\Unit\Console;

use GeekCo\LaravelMaxClient\MaxServiceProvider;
use GeekCo\LaravelMaxClient\Tests\Support\MockHttpClient;
use GeekCo\LaravelMaxClient\Tests\TestCase;
use GeekCo\LaravelMaxClient\Webhook\HandleMaxUpdateJob;
use GeekCo\LaravelMaxClient\Webhook\MaxUpdateReceived;
use GeekCo\MaxPhpClient\ApiClient;
use GeekCo\MaxPhpClient\Dto\Update;
use GeekCo\MaxPhpClient\LongPolling\LongPollingRunner;
use GuzzleHttp\Psr7\Response;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\Facades\Queue;
use Psr\Http\Client\ClientInterface;

final class MaxListenCommandTest extends TestCase
{
    public function testCommandIsRegistered(): void
    {
        $this->artisan('max:listen', ['--help' => true])->assertSuccessful();
    }

    public function testDispatchesJobsInOnceMode(): void
    {
        Queue::fake();
        $this->listenToUpdates();
        $mock = $this->mockUpdates([$this->updatePayload()]);

        $this->artisan('max:listen', ['--once' => true])->assertSuccessful();

        Queue::assertPushed(HandleMaxUpdateJob::class, 1);
        $query = $this->queryOf($mock);
        $this->assertSame('100', $query['limit'] ?? null);
        $this->assertSame('30', $query['timeout'] ?? null);
    }

    public function testDispatchesJobPerUpdate(): void
    {
        Queue::fake();
        $this->listenToUpdates();
        $this->mockUpdates([
            $this->updatePayload(),
            $this->updatePayload(['chat_id' => 7]),
        ]);

        $this->artisan('max:listen', ['--once' => true])->assertSuccessful();

        Queue::assertPushed(HandleMaxUpdateJob::class, 2);
    }

    public function testForwardsMarkerToRequest(): void
    {
        Queue::fake();
        $this->listenToUpdates();
        $mock = $this->mockUpdates([$this->updatePayload()]);

        $this->artisan('max:listen', [
            '--once' => true,
            '--marker' => '42',
        ])->assertSuccessful();

        $query = $this->queryOf($mock);
        $this->assertSame('42', $query['marker'] ?? null);
    }

    public function testUsesConfiguredPollingDefaults(): void
    {
        $this->app['config']->set(MaxServiceProvider::CONFIG_KEY . '.long_polling.limit', 50);
        $this->app['config']->set(MaxServiceProvider::CONFIG_KEY . '.long_polling.timeout', 10);

        Queue::fake();
        $this->listenToUpdates();
        $mock = $this->mockUpdates([$this->updatePayload()]);

        $this->artisan('max:listen', ['--once' => true])->assertSuccessful();

        $query = $this->queryOf($mock);
        $this->assertSame('50', $query['limit'] ?? null);
        $this->assertSame('10', $query['timeout'] ?? null);
    }

    public function testDoesNotDispatchWithoutListener(): void
    {
        Queue::fake();
        $mock = $this->mockUpdates([$this->updatePayload()]);

        $this->artisan('max:listen', ['--once' => true])
            ->expectsOutputToContain('Нет слушателей')
            ->assertSuccessful();

        Queue::assertPushed(HandleMaxUpdateJob::class, 0);
        $this->assertSame(0, $mock->callCount);
    }

    public function testWarnsWhenWebhookIsEnabled(): void
    {
        $this->app['config']->set(MaxServiceProvider::CONFIG_KEY . '.webhook.enabled', true);

        Queue::fake();
        $this->listenToUpdates();
        $this->mockUpdates([$this->updatePayload()]);

        $this->artisan('max:listen', ['--once' => true])
            ->expectsOutputToContain('отключает Long Polling')
            ->assertSuccessful();
    }

    public function testRunsLoopUntilRunnerStops(): void
    {
        Queue::fake();
        $this->listenToUpdates();
        $mock = $this->mockUpdates([$this->updatePayload()]);

        $this->app->instance(LongPollingRunner::class, new LongPollingRunner(
            api: $this->app->make(ApiClient::class),
            handler: static function (Update $update): bool {
                HandleMaxUpdateJob::dispatch($update)->onQueue('default');

                return false;
            },
        ));

        $this->artisan('max:listen')
            ->expectsOutputToContain('Long polling остановлен')
            ->assertSuccessful();

        Queue::assertPushed(HandleMaxUpdateJob::class, 1);
        $this->assertSame(1, $mock->callCount);
    }

    /**
     * @param list<array<string, mixed>> $payloads
     */
    private function mockUpdates(array $payloads): MockHttpClient
    {
        $mock = new MockHttpClient([new Response(200, [], json_encode([
            'updates' => $payloads,
        ], JSON_THROW_ON_ERROR))]);
        $this->app->instance(ClientInterface::class, $mock);

        return $mock;
    }

    /**
     * @return array<string, string>
     */
    private function queryOf(MockHttpClient $mock): array
    {
        $query = parse_url((string) $mock->lastRequest?->getUri(), PHP_URL_QUERY);

        $values = [];
        parse_str((string) $query, $values);

        return $values;
    }

    private function listenToUpdates(): void
    {
        $this->app->make(Dispatcher::class)->listen(MaxUpdateReceived::class, static fn (MaxUpdateReceived $event): null => null);
    }
}
