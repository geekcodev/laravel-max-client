<?php

declare(strict_types=1);

namespace GeekCo\LaravelMaxClient\Tests\Unit\Webhook;

use GeekCo\LaravelMaxClient\MaxServiceProvider;
use GeekCo\LaravelMaxClient\Tests\TestCase;
use GeekCo\LaravelMaxClient\Webhook\HandleMaxUpdateJob;
use GeekCo\LaravelMaxClient\Webhook\MaxUpdateReceived;
use GeekCo\LaravelMaxClient\Webhook\MaxWebhookController;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Queue;
use Orchestra\Testbench\Attributes\DefineEnvironment;

#[DefineEnvironment('enableWebhook')]
final class MaxWebhookControllerTest extends TestCase
{
    private const SECRET_HEADER = 'X-Max-Bot-Api-Secret';

    protected function enableWebhook($app): void
    {
        $app['config']->set(MaxServiceProvider::CONFIG_KEY . '.webhook.enabled', true);
        $app['config']->set(MaxServiceProvider::CONFIG_KEY . '.webhook.secret', 'test-secret');
    }

    public function testResponds200AndQueuesJobForSingleUpdate(): void
    {
        Queue::fake();
        $this->listenToUpdates();

        $response = $this->postJson('/max/webhook', $this->updatePayload(), [self::SECRET_HEADER => 'test-secret']);

        $response->assertStatus(200)->assertJson(['ok' => true]);
        Queue::assertPushed(HandleMaxUpdateJob::class, static function (HandleMaxUpdateJob $job): bool {
            return $job->update->chatId === 42 && $job->update->updateType->value === 'message_created';
        });
    }

    public function testResponds200AndQueuesJobPerUpdateForListPayload(): void
    {
        Queue::fake();
        $this->listenToUpdates();

        $payload = [
            $this->updatePayload(),
            $this->updatePayload(['chat_id' => 7]),
        ];

        $response = $this->postJson('/max/webhook', $payload, [self::SECRET_HEADER => 'test-secret']);

        $response->assertStatus(200)->assertJson(['ok' => true]);
        Queue::assertPushed(HandleMaxUpdateJob::class, 2);
    }

    public function testResponds400ForInvalidJson(): void
    {
        Queue::fake();
        $this->listenToUpdates();

        $response = $this->call(
            'POST',
            '/max/webhook',
            server: ['HTTP_' . strtoupper(str_replace('-', '_', self::SECRET_HEADER)) => 'test-secret'],
            content: '{invalid json',
        );

        $response->assertStatus(400);
        Queue::assertPushed(HandleMaxUpdateJob::class, 0);
    }

    public function testResponds400ForInvalidUpdateStructure(): void
    {
        Queue::fake();
        $this->listenToUpdates();

        $response = $this->postJson('/max/webhook', ['timestamp' => 1700000000000], [self::SECRET_HEADER => 'test-secret']);

        $response->assertStatus(400);
        Queue::assertPushed(HandleMaxUpdateJob::class, 0);
    }

    public function testResponds401ForInvalidSecret(): void
    {
        Queue::fake();
        $this->listenToUpdates();

        $response = $this->postJson('/max/webhook', $this->updatePayload(), [self::SECRET_HEADER => 'wrong-secret']);

        $response->assertStatus(401);
        Queue::assertPushed(HandleMaxUpdateJob::class, 0);
    }

    public function testResponds200WithoutQueuingWhenNoListenerRegistered(): void
    {
        Queue::fake();

        $response = $this->postJson('/max/webhook', $this->updatePayload(), [self::SECRET_HEADER => 'test-secret']);

        $response->assertStatus(200)->assertJson(['ok' => true]);
        Queue::assertPushed(HandleMaxUpdateJob::class, 0);
    }

    public function testControllerVerifiesSecretOnItsOwn(): void
    {
        Queue::fake();
        $this->listenToUpdates();

        $request = Request::create('/max/webhook', 'POST', content: json_encode($this->updatePayload(), JSON_THROW_ON_ERROR));
        $request->headers->set(self::SECRET_HEADER, 'wrong-secret');

        $response = $this->app->make(MaxWebhookController::class)($request);

        $this->assertSame(401, $response->getStatusCode());
        Queue::assertPushed(HandleMaxUpdateJob::class, 0);
    }

    private function listenToUpdates(): void
    {
        $this->app->make(Dispatcher::class)->listen(MaxUpdateReceived::class, static fn (MaxUpdateReceived $event): null => null);
    }
}
