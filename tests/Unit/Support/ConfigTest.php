<?php

declare(strict_types=1);

namespace GeekCo\LaravelMaxClient\Tests\Unit\Support;

use GeekCo\LaravelMaxClient\MaxServiceProvider;
use GeekCo\LaravelMaxClient\Models\BotChat;
use GeekCo\LaravelMaxClient\Support\Config;
use GeekCo\LaravelMaxClient\Tests\Support\CustomBotChat;
use GeekCo\LaravelMaxClient\Tests\TestCase;

final class ConfigTest extends TestCase
{
    public function testApiToken(): void
    {
        $this->assertSame('test-token', $this->config()->apiToken());
    }

    public function testBaseUriDefaultsToPlatformApi2(): void
    {
        $this->assertSame('https://platform-api2.max.ru', $this->config()->baseUri());
    }

    public function testRetryDefaults(): void
    {
        $config = $this->config();

        $this->assertSame(3, $config->retryMaxAttempts());
        $this->assertSame(0.0, $config->retryBaseDelaySeconds());
        $this->assertSame(30.0, $config->retryMaxDelaySeconds());
        $this->assertSame(2.0, $config->retryFactor());
        $this->assertFalse($config->retryOnNonIdempotent());
    }

    public function testRateLimitDefaults(): void
    {
        $config = $this->config();

        $this->assertSame(2.0, $config->rateLimitTokensPerSecond());
        $this->assertSame(2.0, $config->rateLimitMaxTokens());
    }

    public function testWebhookDefaults(): void
    {
        $config = $this->config();

        $this->assertFalse($config->webhookEnabled());
        $this->assertNull($config->webhookSecret());
        $this->assertSame('default', $config->webhookQueue());
        $this->assertSame('/max/webhook', $config->webhookPath());
        $this->assertSame(['throttle:60,1'], $config->webhookMiddleware());
        $this->assertSame([], $config->webhookAllowedHosts());
    }

    public function testLongPollingDefaults(): void
    {
        $config = $this->config();

        $this->assertSame(100, $config->pollingLimit());
        $this->assertSame(30, $config->pollingTimeout());
        $this->assertTrue($config->pollingBreakOnFailure());
    }

    public function testCustomValuesAreReturned(): void
    {
        $this->app['config']->set(MaxServiceProvider::CONFIG_KEY . '.base_uri', 'https://custom.max.test');
        $this->app['config']->set(MaxServiceProvider::CONFIG_KEY . '.retry.max_attempts', 5);
        $this->app['config']->set(MaxServiceProvider::CONFIG_KEY . '.retry.base_delay_seconds', 0.5);
        $this->app['config']->set(MaxServiceProvider::CONFIG_KEY . '.retry.retry_on_non_idempotent', true);
        $this->app['config']->set(MaxServiceProvider::CONFIG_KEY . '.rate_limit.max_tokens', 1.0);
        $this->app['config']->set(MaxServiceProvider::CONFIG_KEY . '.webhook.enabled', true);
        $this->app['config']->set(MaxServiceProvider::CONFIG_KEY . '.webhook.secret', 's3cr3t');
        $this->app['config']->set(MaxServiceProvider::CONFIG_KEY . '.webhook.queue', 'webhooks');
        $this->app['config']->set(MaxServiceProvider::CONFIG_KEY . '.webhook.path', '/custom/max');
        $this->app['config']->set(MaxServiceProvider::CONFIG_KEY . '.webhook.middleware', ['throttle:10,1', 'signed']);
        $this->app['config']->set(MaxServiceProvider::CONFIG_KEY . '.webhook.allowed_hosts', ['https://fu.oneme.ru']);
        $this->app['config']->set(MaxServiceProvider::CONFIG_KEY . '.long_polling.limit', 50);
        $this->app['config']->set(MaxServiceProvider::CONFIG_KEY . '.long_polling.timeout', 10);
        $this->app['config']->set(MaxServiceProvider::CONFIG_KEY . '.long_polling.break_on_failure', false);

        $config = $this->config();

        $this->assertSame('https://custom.max.test', $config->baseUri());
        $this->assertSame(5, $config->retryMaxAttempts());
        $this->assertSame(0.5, $config->retryBaseDelaySeconds());
        $this->assertTrue($config->retryOnNonIdempotent());
        $this->assertSame(1.0, $config->rateLimitMaxTokens());
        $this->assertTrue($config->webhookEnabled());
        $this->assertSame('s3cr3t', $config->webhookSecret());
        $this->assertSame('webhooks', $config->webhookQueue());
        $this->assertSame('/custom/max', $config->webhookPath());
        $this->assertSame(['throttle:10,1', 'signed'], $config->webhookMiddleware());
        $this->assertSame(['https://fu.oneme.ru'], $config->webhookAllowedHosts());
        $this->assertSame(50, $config->pollingLimit());
        $this->assertSame(10, $config->pollingTimeout());
        $this->assertFalse($config->pollingBreakOnFailure());
    }

    public function testWebhookSecretIsNullWhenEmpty(): void
    {
        $this->app['config']->set(MaxServiceProvider::CONFIG_KEY . '.webhook.secret', '');

        $this->assertNull($this->config()->webhookSecret());
    }

    public function testHttpOptionsReturnsConfiguredArray(): void
    {
        $this->app['config']->set(MaxServiceProvider::CONFIG_KEY . '.http.options', ['timeout' => 5.0]);

        $this->assertSame(['timeout' => 5.0], $this->config()->httpOptions());
    }

    public function testHttpOptionsFallsBackToEmptyArray(): void
    {
        $this->app['config']->set(MaxServiceProvider::CONFIG_KEY . '.http.options', 'not-an-array');

        $this->assertSame([], $this->config()->httpOptions());
    }

    public function testListAccessorsDropNonStringItems(): void
    {
        $this->app['config']->set(
            MaxServiceProvider::CONFIG_KEY . '.webhook.middleware',
            ['throttle:60,1', 123, ['nested']],
        );
        $this->app['config']->set(MaxServiceProvider::CONFIG_KEY . '.webhook.allowed_hosts', 'not-a-list');

        $config = $this->config();

        $this->assertSame(['throttle:60,1'], $config->webhookMiddleware());
        $this->assertSame([], $config->webhookAllowedHosts());
    }

    public function testChatsModelDefaultsToBotChat(): void
    {
        $this->assertSame(BotChat::class, $this->config()->chatsModel());
    }

    public function testChatsModelReturnsCustomSubclass(): void
    {
        $this->app['config']->set(MaxServiceProvider::CONFIG_KEY . '.chats.model', CustomBotChat::class);

        $this->assertSame(CustomBotChat::class, $this->config()->chatsModel());
    }

    public function testChatsModelFallsBackWhenNotBotChatSubclass(): void
    {
        $this->app['config']->set(MaxServiceProvider::CONFIG_KEY . '.chats.model', \stdClass::class);

        $this->assertSame(BotChat::class, $this->config()->chatsModel());
    }

    private function config(): Config
    {
        return $this->app->make(Config::class);
    }
}
