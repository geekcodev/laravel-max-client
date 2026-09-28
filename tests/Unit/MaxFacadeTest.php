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

    public function testFacadeDocumentsEveryPublicApiClientMethodWithItsReturnType(): void
    {
        $actual = [];
        foreach ((new \ReflectionClass(ApiClient::class))->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
            if ($method->isStatic() || $method->getName() === '__construct') {
                continue;
            }

            $actual[$method->getName()] = $this->reflectionType($method->getReturnType());
        }

        ksort($actual);

        $this->assertSame($actual, $this->documentedMethods());
    }

    /**
     * @return array<string, string> имя метода → нормализованный тип возврата из PHPDoc фасада
     */
    private function documentedMethods(): array
    {
        $docComment = (new \ReflectionClass(Max::class))->getDocComment();

        $this->assertIsString($docComment);

        preg_match_all('/@method\s+static\s+(.+?)\s+(\w+)\(/', $docComment, $matches, \PREG_SET_ORDER);

        $methods = [];
        foreach ($matches as [, $returnType, $name]) {
            $methods[$name] = $this->normalizeType($returnType);
        }

        ksort($methods);

        return $methods;
    }

    private function reflectionType(?\ReflectionType $type): string
    {
        if ($type instanceof \ReflectionUnionType) {
            $parts = array_map(
                static fn (\ReflectionType $item): string => $item->getName(),
                $type->getTypes(),
            );
            sort($parts);
        } elseif ($type instanceof \ReflectionNamedType) {
            $parts = [$type->getName()];
        } else {
            return 'mixed';
        }

        $normalized = $this->normalizeType(implode('|', $parts));

        return $type->allowsNull() && !str_starts_with($normalized, '?') ? '?' . $normalized : $normalized;
    }

    private function normalizeType(string $type): string
    {
        if (str_starts_with(ltrim($type, '?\\'), 'array')) {
            return 'array';
        }

        $nullable = false;
        $parts = [];

        foreach (explode('|', $type) as $part) {
            $part = ltrim($part, '?\\');

            if ($part === 'null') {
                $nullable = true;

                continue;
            }

            $parts[] = $part;
        }

        sort($parts);

        $normalized = implode('|', $parts);

        return $nullable ? '?' . $normalized : $normalized;
    }

    private function pinnedMessageResponse(): Response
    {
        return $this->messageResponse();
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
