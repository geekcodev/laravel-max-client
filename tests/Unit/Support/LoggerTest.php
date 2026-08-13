<?php

declare(strict_types=1);

namespace GeekCo\LaravelMaxClient\Tests\Unit\Support;

use GeekCo\LaravelMaxClient\MaxServiceProvider;
use GeekCo\LaravelMaxClient\Support\Logger;
use GeekCo\LaravelMaxClient\Tests\TestCase;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Log;

final class LoggerTest extends TestCase
{
    public function testIsDisabledByDefault(): void
    {
        $this->assertFalse($this->logger()->isEnabled());
    }

    public function testIsEnabledWhenConfigured(): void
    {
        $this->enableLogging();

        $this->assertTrue($this->logger()->isEnabled());
    }

    public function testLoggerReturnsNullWhenDisabled(): void
    {
        $this->assertNull($this->logger()->logger());
    }

    public function testLoggerResolvesConfiguredChannel(): void
    {
        $this->enableLogging(['channel' => 'single']);

        $this->assertSame(Log::channel('single'), $this->logger()->logger());
    }

    public function testLoggerFallsBackToConfiguredFallbackChannelWhenChannelIsMissing(): void
    {
        $this->enableLogging([
            'channel' => 'missing-channel',
            'fallback_channel' => 'single',
        ]);

        $this->assertSame(Log::channel('single'), $this->logger()->logger());
    }

    public function testLoggerFallsBackToStackWhenChannelsAreMissing(): void
    {
        $this->enableLogging([
            'channel' => 'missing-channel',
            'fallback_channel' => 'another-missing-channel',
        ]);

        $this->assertSame(Log::channel('stack'), $this->logger()->logger());
    }

    public function testLogIsNoopWhenDisabled(): void
    {
        $logged = [];
        Log::listen(static function (MessageLogged $event) use (&$logged): void {
            $logged[] = $event->message;
        });

        $this->logger()->log('info', 'should not appear');

        $this->assertSame([], $logged);
    }

    public function testLogWritesToConfiguredChannelWhenEnabled(): void
    {
        $logged = [];
        Log::listen(static function (MessageLogged $event) use (&$logged): void {
            $logged[] = $event->message;
        });
        $this->enableLogging();

        $this->logger()->log('info', 'hello from max');

        $this->assertContains('hello from max', $logged);
    }

    private function logger(): Logger
    {
        return $this->app->make(Logger::class);
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function enableLogging(array $overrides = []): void
    {
        $this->app['config']->set(MaxServiceProvider::CONFIG_KEY . '.logging.enabled', true);

        foreach ($overrides as $key => $value) {
            $this->app['config']->set(MaxServiceProvider::CONFIG_KEY . '.logging.' . $key, $value);
        }
    }
}
