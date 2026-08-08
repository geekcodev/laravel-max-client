<?php

declare(strict_types=1);

namespace GeekCo\LaravelMaxClient\Tests\Unit\Http;

use GeekCo\LaravelMaxClient\Http\HttpClientFactory;
use GeekCo\LaravelMaxClient\MaxServiceProvider;
use GeekCo\LaravelMaxClient\Support\Config;
use GeekCo\LaravelMaxClient\Tests\Support\MockHttpClient;
use GeekCo\LaravelMaxClient\Tests\TestCase;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Psr7\HttpFactory;
use Psr\Http\Client\ClientInterface;

final class HttpClientFactoryTest extends TestCase
{
    public function testUsesContainerClientWhenBound(): void
    {
        $mock = new MockHttpClient();
        $this->app->instance(ClientInterface::class, $mock);

        $factory = new HttpClientFactory($this->app, $this->app->make(Config::class));

        $this->assertSame($mock, $factory->createClient());
    }

    public function testCreatesGuzzleClientWithOptionsByDefault(): void
    {
        $this->app['config']->set(MaxServiceProvider::CONFIG_KEY . '.http.options', [
            'timeout' => 30.0,
            'http_errors' => false,
        ]);

        $factory = new HttpClientFactory($this->app, $this->app->make(Config::class));
        $client = $factory->createClient();

        $this->assertInstanceOf(GuzzleClient::class, $client);
        $this->assertSame(30.0, $client->getConfig('timeout'));
        $this->assertFalse($client->getConfig('http_errors'));
    }

    public function testThrowsWhenContainerBindingIsNotPsr18(): void
    {
        $this->app->instance(ClientInterface::class, new \stdClass());

        $factory = new HttpClientFactory($this->app, $this->app->make(Config::class));

        $this->expectException(\RuntimeException::class);
        $factory->createClient();
    }

    public function testCreatesPsr17HttpFactory(): void
    {
        $factory = new HttpClientFactory($this->app, $this->app->make(Config::class));

        $this->assertInstanceOf(HttpFactory::class, $factory->createHttpFactory());
    }
}
