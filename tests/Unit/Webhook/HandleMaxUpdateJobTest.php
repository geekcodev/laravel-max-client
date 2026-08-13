<?php

declare(strict_types=1);

namespace GeekCo\LaravelMaxClient\Tests\Unit\Webhook;

use GeekCo\LaravelMaxClient\MaxServiceProvider;
use GeekCo\LaravelMaxClient\Tests\TestCase;
use GeekCo\LaravelMaxClient\Webhook\HandleMaxUpdateJob;
use GeekCo\LaravelMaxClient\Webhook\MaxUpdateReceived;
use GeekCo\MaxPhpClient\Dto\Update;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use RuntimeException;

final class HandleMaxUpdateJobTest extends TestCase
{
    public function testJobImplementsShouldQueue(): void
    {
        $job = new HandleMaxUpdateJob(Update::fromArray($this->updatePayload()));

        $this->assertInstanceOf(ShouldQueue::class, $job);
    }

    public function testJobDefaults(): void
    {
        $job = new HandleMaxUpdateJob(Update::fromArray($this->updatePayload()));

        $this->assertSame(3, $job->tries);
        $this->assertSame(30, $job->timeout);
    }

    public function testHandleDispatchesMaxUpdateReceived(): void
    {
        $update = Update::fromArray($this->updatePayload());
        Event::fake([MaxUpdateReceived::class]);

        $job = new HandleMaxUpdateJob($update);
        $job->handle($this->app->make(Dispatcher::class));

        Event::assertDispatched(MaxUpdateReceived::class, static fn (MaxUpdateReceived $event): bool => $event->update === $update);
    }

    public function testShouldQueueIsTrueWhenListenerIsRegistered(): void
    {
        $this->app->make(Dispatcher::class)->listen(MaxUpdateReceived::class, static fn (): null => null);

        $job = new HandleMaxUpdateJob(Update::fromArray($this->updatePayload()));

        $this->assertTrue($job->shouldQueue());
    }

    public function testShouldQueueIsFalseWithoutListeners(): void
    {
        $job = new HandleMaxUpdateJob(Update::fromArray($this->updatePayload()));

        $this->assertFalse($job->shouldQueue());
    }

    public function testHandleLogsStartAndFinishWhenLoggingEnabled(): void
    {
        $this->app['config']->set(MaxServiceProvider::CONFIG_KEY . '.logging.enabled', true);
        $logged = [];
        Log::listen(static function (MessageLogged $event) use (&$logged): void {
            $logged[] = $event->message;
        });

        $job = new HandleMaxUpdateJob(Update::fromArray($this->updatePayload()));
        $job->handle($this->app->make(Dispatcher::class));

        $this->assertContains('MAX update job started', $logged);
        $this->assertContains('MAX update job finished', $logged);
    }

    public function testHandleLogsFailureAndRethrowsWhenLoggingEnabled(): void
    {
        $this->app['config']->set(MaxServiceProvider::CONFIG_KEY . '.logging.enabled', true);
        $logged = [];
        Log::listen(static function (MessageLogged $event) use (&$logged): void {
            $logged[] = $event->message;
        });

        $this->app->make(Dispatcher::class)->listen(
            MaxUpdateReceived::class,
            static function (): never {
                throw new RuntimeException('boom');
            },
        );

        $job = new HandleMaxUpdateJob(Update::fromArray($this->updatePayload()));

        try {
            $job->handle($this->app->make(Dispatcher::class));
            $this->fail('Expected RuntimeException was not thrown.');
        } catch (RuntimeException) {
        }

        $this->assertContains('MAX update job failed', $logged);
    }
}
