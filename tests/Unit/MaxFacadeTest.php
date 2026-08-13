<?php

declare(strict_types=1);

namespace GeekCo\LaravelMaxClient\Tests\Unit;

use GeekCo\LaravelMaxClient\Facades\Max;
use GeekCo\LaravelMaxClient\Tests\Support\MockHttpClient;
use GeekCo\LaravelMaxClient\Tests\TestCase;
use GeekCo\MaxPhpClient\ApiClient;
use GeekCo\MaxPhpClient\Dto\BotInfo;
use GeekCo\MaxPhpClient\Dto\ChatAdminsResult;
use GeekCo\MaxPhpClient\Dto\Message;
use GuzzleHttp\Psr7\Response;
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

    public function testFacadeProxiesGetPinnedMessage(): void
    {
        $this->app->instance(ClientInterface::class, new MockHttpClient([$this->pinnedMessageResponse()]));

        $message = Max::getPinnedMessage(42);

        $this->assertInstanceOf(Message::class, $message);
        $this->assertSame(42, $message->recipient->chatId);
    }

    public function testFacadeProxiesGetChatAdmins(): void
    {
        $this->app->instance(ClientInterface::class, new MockHttpClient([$this->chatAdminsResponse()]));

        $result = Max::getChatAdmins(42);

        $this->assertInstanceOf(ChatAdminsResult::class, $result);
        $this->assertCount(1, $result->members);
        $this->assertSame(7, $result->members[0]->userId);
    }

    private function pinnedMessageResponse(): Response
    {
        $message = json_decode((string) $this->messageResponse()->getBody(), true, 512, JSON_THROW_ON_ERROR);

        return new Response(200, [], json_encode(['message' => $message], JSON_THROW_ON_ERROR));
    }

    private function chatAdminsResponse(): Response
    {
        return new Response(200, [], json_encode([
            'members' => [[
                'user_id' => 7,
                'first_name' => 'Alice',
                'last_name' => null,
                'username' => null,
                'is_bot' => false,
                'last_activity_time' => 1700000000000,
                'last_access_time' => 1700000000000,
                'is_owner' => false,
                'is_admin' => true,
                'join_time' => 1700000000,
            ]],
        ], JSON_THROW_ON_ERROR));
    }
}
