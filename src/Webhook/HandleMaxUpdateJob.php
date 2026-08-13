<?php

declare(strict_types=1);

namespace GeekCo\LaravelMaxClient\Webhook;

use GeekCo\LaravelMaxClient\Support\Logger;
use GeekCo\MaxPhpClient\Dto\Update;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Bus\Queueable;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

final class HandleMaxUpdateJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 3;

    public int $timeout = 30;

    public function __construct(
        public readonly Update $update,
    ) {
    }

    public function shouldQueue(): bool
    {
        return app(Dispatcher::class)->hasListeners(MaxUpdateReceived::class);
    }

    public function handle(Dispatcher $dispatcher): void
    {
        $logger = app(Logger::class);
        $context = [
            'update_type' => $this->update->updateType->value,
            'user_id' => $this->update->user?->userId,
            'chat_id' => $this->update->chatId,
        ];

        $logger->log('info', 'MAX update job started', $context);

        try {
            $dispatcher->dispatch(new MaxUpdateReceived($this->update));
        } catch (\Throwable $exception) {
            $logger->log('error', 'MAX update job failed', [
                ...$context,
                'exception' => $exception::class,
                'message' => $exception->getMessage(),
            ]);

            throw $exception;
        }

        $logger->log('info', 'MAX update job finished', $context);
    }
}
