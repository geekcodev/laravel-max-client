<?php

declare(strict_types=1);

namespace GeekCo\LaravelMaxClient\Support;

use Illuminate\Support\Facades\Log;
use Psr\Log\LoggerInterface;

final readonly class Logger
{
    public function __construct(
        private readonly Config $config,
    ) {
    }

    public function isEnabled(): bool
    {
        return $this->config->loggingEnabled();
    }

    /**
     * @param array<string, mixed> $context
     */
    public function log(string $level, string $message, array $context = []): void
    {
        $logger = $this->logger();
        if ($logger === null) {
            return;
        }

        $logger->log($level, $message, $context);
    }

    public function logger(): ?LoggerInterface
    {
        if (! $this->isEnabled()) {
            return null;
        }

        try {
            return Log::channel($this->config->loggingChannel());
        } catch (\InvalidArgumentException) {
        }

        try {
            return Log::channel($this->config->loggingFallbackChannel());
        } catch (\InvalidArgumentException) {
        }

        return Log::channel('stack');
    }
}
