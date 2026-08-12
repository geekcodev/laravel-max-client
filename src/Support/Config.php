<?php

declare(strict_types=1);

namespace GeekCo\LaravelMaxClient\Support;

use GeekCo\LaravelMaxClient\Models\BotChat;

final readonly class Config
{
    private const KEY = 'laravel-max-client';

    public function apiToken(): string
    {
        return $this->string('api_token', '');
    }

    public function baseUri(): string
    {
        return $this->string('base_uri', 'https://platform-api2.max.ru');
    }

    /**
     * @return array<mixed>
     */
    public function httpOptions(): array
    {
        $options = config(self::KEY . '.http.options', []);

        return \is_array($options) ? $options : [];
    }

    public function retryMaxAttempts(): int
    {
        return $this->int('retry.max_attempts', 3);
    }

    public function retryBaseDelaySeconds(): float
    {
        return $this->float('retry.base_delay_seconds', 1.0);
    }

    public function retryMaxDelaySeconds(): float
    {
        return $this->float('retry.max_delay_seconds', 30.0);
    }

    public function retryFactor(): float
    {
        return $this->float('retry.factor', 2.0);
    }

    public function retryOnNonIdempotent(): bool
    {
        return $this->bool('retry.retry_on_non_idempotent', false);
    }

    public function rateLimitTokensPerSecond(): float
    {
        return $this->float('rate_limit.tokens_per_second', 2.0);
    }

    public function rateLimitMaxTokens(): float
    {
        return $this->float('rate_limit.max_tokens', 2.0);
    }

    public function webhookEnabled(): bool
    {
        return $this->bool('webhook.enabled', false);
    }

    public function webhookSecret(): ?string
    {
        $secret = $this->string('webhook.secret', '');

        return $secret === '' ? null : $secret;
    }

    public function webhookQueue(): string
    {
        return $this->string('webhook.queue', 'default');
    }

    public function webhookPath(): string
    {
        return $this->string('webhook.path', '/max/webhook');
    }

    /**
     * @return list<string>
     */
    public function webhookMiddleware(): array
    {
        return $this->listOfStrings('webhook.middleware', []);
    }

    /**
     * @return list<string>
     */
    public function webhookAllowedHosts(): array
    {
        return $this->listOfStrings('webhook.allowed_hosts', []);
    }

    public function pollingLimit(): int
    {
        return $this->int('long_polling.limit', 100);
    }

    public function pollingTimeout(): int
    {
        return $this->int('long_polling.timeout', 30);
    }

    public function pollingBreakOnFailure(): bool
    {
        return $this->bool('long_polling.break_on_failure', true);
    }

    public function webappMaxAge(): int
    {
        return $this->int('webapp.max_age', 86400);
    }

    public function webappStrict(): bool
    {
        return $this->bool('webapp.strict', false);
    }

    public function webappSessionUserIdKey(): string
    {
        return $this->string('webapp.session.user_id', 'user_id');
    }

    public function webappSessionChatIdKey(): string
    {
        return $this->string('webapp.session.chat_id', 'chat_id');
    }

    public function webappCspEnabled(): bool
    {
        return $this->bool('webapp.frame_ancestors.enabled', true);
    }

    /**
     * @return list<string>
     */
    public function webappFrameAncestors(): array
    {
        return $this->listOfStrings('webapp.frame_ancestors.hosts', ['https://max.ru', 'https://web.max.ru']);
    }

    public function chatsEnabled(): bool
    {
        return $this->bool('chats.enabled', false);
    }

    /**
     * @return class-string<BotChat>
     */
    public function chatsModel(): string
    {
        $model = $this->string('chats.model', BotChat::class);

        if (!is_a($model, BotChat::class, true)) {
            return BotChat::class;
        }

        return $model;
    }

    private function string(string $key, string $default): string
    {
        $value = config(self::KEY . '.' . $key, $default);

        return \is_string($value) ? $value : $default;
    }

    private function int(string $key, int $default): int
    {
        $value = config(self::KEY . '.' . $key, $default);

        return \is_int($value) ? $value : $default;
    }

    private function float(string $key, float $default): float
    {
        $value = config(self::KEY . '.' . $key, $default);

        return \is_int($value) || \is_float($value) ? (float) $value : $default;
    }

    private function bool(string $key, bool $default): bool
    {
        $value = config(self::KEY . '.' . $key, $default);

        return \is_bool($value) ? $value : $default;
    }

    /**
     * @param list<string> $default
     *
     * @return list<string>
     */
    private function listOfStrings(string $key, array $default): array
    {
        $value = config(self::KEY . '.' . $key, $default);
        if (!\is_array($value)) {
            return $default;
        }

        $list = [];
        foreach ($value as $item) {
            if (\is_string($item)) {
                $list[] = $item;
            }
        }

        return $list;
    }
}
