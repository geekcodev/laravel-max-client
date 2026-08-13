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
        $this->logger()?->log($level, $message, $context);
    }

    public function logger(): ?LoggerInterface
    {
        if (! $this->isEnabled()) {
            return null;
        }

        return Log::channel($this->resolveChannel());
    }

    private function resolveChannel(): string
    {
        if ($this->hasChannel($this->config->loggingChannel())) {
            return $this->config->loggingChannel();
        }

        if ($this->hasChannel($this->config->loggingFallbackChannel())) {
            return $this->config->loggingFallbackChannel();
        }

        return 'stack';
    }

    private function hasChannel(string $name): bool
    {
        return is_array(config('logging.channels.' . $name));
    }
}
