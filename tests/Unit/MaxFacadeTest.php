<?php

declare(strict_types=1);

namespace GeekCo\LaravelMaxClient\Tests\Unit;

use GeekCo\LaravelMaxClient\Facades\Max;
use GeekCo\LaravelMaxClient\Tests\Support\MockHttpClient;
use GeekCo\LaravelMaxClient\Tests\TestCase;
use GeekCo\MaxPhpClient\ApiClient;
use GeekCo\MaxPhpClient\Dto\BotInfo;
use Psr\Http\Client\ClientInterface;

final class MaxFacadeTest extends TestCase
{
    public function testFacadeResolvesTheApiClientSingleton(): void
    {
        $this->app->instance(ClientInterface::class, new MockHttpClient());

        $this->assertSame($this->app->make(ApiClient::class), Max::getFacadeRoot());
    }

    public function testFacadeProxiesApiCalls(): void
    {
        $this->app->instance(ClientInterface::class, new MockHttpClient([$this->botInfoResponse()]));

        $me = Max::getMe();

        $this->assertInstanceOf(BotInfo::class, $me);
        $this->assertSame(1, $me->userId);
    }
}
