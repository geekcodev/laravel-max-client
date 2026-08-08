<?php

declare(strict_types=1);

namespace GeekCo\LaravelMaxClient\Tests\Unit\Webhook;

use GeekCo\LaravelMaxClient\Tests\TestCase;
use GeekCo\LaravelMaxClient\Webhook\HandleMaxUpdateJob;
use GeekCo\LaravelMaxClient\Webhook\MaxUpdateReceived;
use GeekCo\MaxPhpClient\Dto\Update;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Event;

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
}
