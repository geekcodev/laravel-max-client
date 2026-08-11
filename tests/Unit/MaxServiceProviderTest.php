<?php

declare(strict_types=1);

namespace GeekCo\LaravelMaxClient\Tests\Unit;

use GeekCo\LaravelMaxClient\Http\HttpClientFactory;
use GeekCo\LaravelMaxClient\MaxServiceProvider;
use GeekCo\LaravelMaxClient\Support\Config;
use GeekCo\LaravelMaxClient\Tests\Support\MockHttpClient;
use GeekCo\LaravelMaxClient\Tests\TestCase;
use GeekCo\LaravelMaxClient\WebApp\WebAppContext;
use GeekCo\LaravelMaxClient\Webhook\HandleMaxUpdateJob;
use GeekCo\LaravelMaxClient\Webhook\MaxUpdateReceived;
use GeekCo\MaxPhpClient\ApiClient;
use GeekCo\MaxPhpClient\Dto\NewMessageBody;
use GeekCo\MaxPhpClient\Dto\Recipient;
use GeekCo\MaxPhpClient\Dto\Update;
use GeekCo\MaxPhpClient\Exception\ApiException;
use GeekCo\MaxPhpClient\Exception\RateLimitException;
use GeekCo\MaxPhpClient\LongPolling\LongPollingRunner;
use GeekCo\MaxPhpClient\Security\WebAppDataValidator;
use GuzzleHttp\Psr7\Response;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\ServiceProvider;
use Psr\Http\Client\ClientInterface;

final class MaxServiceProviderTest extends TestCase
{
    public function testApiClientIsResolvedAsSingleton(): void
    {
        $this->app->instance(ClientInterface::class, new MockHttpClient());

        $client = $this->app->make(ApiClient::class);

        $this->assertInstanceOf(ApiClient::class, $client);
        $this->assertSame($client, $this->app->make(ApiClient::class));
    }

    public function testConfigIsMergedWithDefaults(): void
    {
        $this->assertSame('https://platform-api2.max.ru', config(MaxServiceProvider::CONFIG_KEY . '.base_uri'));
    }

    public function testApiClientUsesTokenAndBaseUriFromConfig(): void
    {
        $this->app['config']->set(MaxServiceProvider::CONFIG_KEY . '.base_uri', 'https://custom.max.test');
        $this->app['config']->set(MaxServiceProvider::CONFIG_KEY . '.api_token', 'my-token');

        $mock = new MockHttpClient([$this->botInfoResponse()]);
        $this->app->instance(ClientInterface::class, $mock);

        $client = $this->app->make(ApiClient::class);
        $me = $client->getMe();

        $this->assertSame(1, $me->userId);
        $this->assertSame('my-token', $mock->lastRequest?->getHeaderLine('Authorization'));
        $this->assertStringStartsWith('https://custom.max.test/me', (string) $mock->lastRequest?->getUri());
    }

    public function testRetryConfigDisablesRetries(): void
    {
        $this->app['config']->set(MaxServiceProvider::CONFIG_KEY . '.retry.max_attempts', 1);

        $mock = new MockHttpClient([new Response(503, [], '{}')]);
        $this->app->instance(ClientInterface::class, $mock);

        $client = $this->app->make(ApiClient::class);

        $this->expectException(ApiException::class);
        $client->getMe();
        $this->assertSame(1, $mock->callCount);
    }

    public function testRetryConfigEnablesRetries(): void
    {
        $this->app['config']->set(MaxServiceProvider::CONFIG_KEY . '.retry.max_attempts', 2);

        $mock = new MockHttpClient([new Response(503, [], '{}'), $this->botInfoResponse()]);
        $this->app->instance(ClientInterface::class, $mock);

        $client = $this->app->make(ApiClient::class);
        $me = $client->getMe();

        $this->assertSame(2, $mock->callCount);
        $this->assertSame(1, $me->userId);
    }

    public function testRateLimitConfigIsApplied(): void
    {
        $this->app['config']->set(MaxServiceProvider::CONFIG_KEY . '.rate_limit.max_tokens', 1.0);
        $this->app['config']->set(MaxServiceProvider::CONFIG_KEY . '.rate_limit.tokens_per_second', 0.01);

        $mock = new MockHttpClient([$this->messageResponse()]);
        $this->app->instance(ClientInterface::class, $mock);

        $client = $this->app->make(ApiClient::class);
        $recipient = new Recipient(chatId: 42);

        $client->sendMessage($recipient, new NewMessageBody(text: 'first'));

        $this->expectException(RateLimitException::class);
        $client->sendMessage($recipient, new NewMessageBody(text: 'second'));
    }

    public function testConfigIsPublishable(): void
    {
        $this->app->boot();

        $paths = ServiceProvider::pathsToPublish(MaxServiceProvider::class, 'laravel-max-client-config');

        $this->assertCount(1, $paths);
        $this->assertSame($this->app->configPath('laravel-max-client.php'), reset($paths));
    }

    public function testConfigAccessorIsBound(): void
    {
        $this->assertInstanceOf(Config::class, $this->app->make(Config::class));
        $this->assertSame('test-token', $this->app->make(Config::class)->apiToken());
    }

    public function testHttpClientFactoryIsBound(): void
    {
        $this->assertInstanceOf(HttpClientFactory::class, $this->app->make(HttpClientFactory::class));
    }

    public function testWebAppDataValidatorIsBoundAsSingleton(): void
    {
        $this->app['config']->set(MaxServiceProvider::CONFIG_KEY . '.webapp.max_age', 3600);

        $validator = $this->app->make(WebAppDataValidator::class);

        $this->assertInstanceOf(WebAppDataValidator::class, $validator);
        $this->assertSame($validator, $this->app->make(WebAppDataValidator::class));
    }

    public function testWebAppContextIsBoundAsSingleton(): void
    {
        $context = $this->app->make(WebAppContext::class);

        $this->assertInstanceOf(WebAppContext::class, $context);
        $this->assertSame($context, $this->app->make(WebAppContext::class));
    }

    public function testLongPollingRunnerIsBoundAsSingleton(): void
    {
        $this->app->instance(ClientInterface::class, new MockHttpClient());

        $runner = $this->app->make(LongPollingRunner::class);

        $this->assertInstanceOf(LongPollingRunner::class, $runner);
        $this->assertSame($runner, $this->app->make(LongPollingRunner::class));
    }

    public function testLongPollingRunnerHandlerDispatchesJob(): void
    {
        Queue::fake();
        $this->app->instance(ClientInterface::class, new MockHttpClient());
        $this->app->make(Dispatcher::class)->listen(
            MaxUpdateReceived::class,
            static fn (MaxUpdateReceived $event): null => null,
        );

        $runner = $this->app->make(LongPollingRunner::class);
        $handler = (new \ReflectionProperty($runner, 'handler'))->getValue($runner);

        $handler(Update::fromArray($this->updatePayload()));

        Queue::assertPushed(HandleMaxUpdateJob::class, 1);
    }
}
