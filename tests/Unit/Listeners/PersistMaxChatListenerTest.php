<?php

declare(strict_types=1);

namespace GeekCo\LaravelMaxClient\Tests\Unit\Listeners;

use GeekCo\LaravelMaxClient\Enums\MaxChatStatus;
use GeekCo\LaravelMaxClient\MaxServiceProvider;
use GeekCo\LaravelMaxClient\Models\MaxChat;
use GeekCo\LaravelMaxClient\Models\MaxUser;
use GeekCo\LaravelMaxClient\Tests\Support\MockHttpClient;
use GeekCo\LaravelMaxClient\Tests\TestCase;
use GeekCo\LaravelMaxClient\Webhook\MaxUpdateReceived;
use GeekCo\MaxPhpClient\ApiClient;
use GeekCo\MaxPhpClient\Dto\Callback;
use GeekCo\MaxPhpClient\Dto\Message;
use GeekCo\MaxPhpClient\Dto\Recipient;
use GeekCo\MaxPhpClient\Dto\Update;
use GeekCo\MaxPhpClient\Dto\User;
use GeekCo\MaxPhpClient\Enum\ChatType;
use GeekCo\MaxPhpClient\Enum\UpdateType;
use GuzzleHttp\Psr7\HttpFactory;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;

final class PersistMaxChatListenerTest extends TestCase
{
    use RefreshDatabase;

    private MockHttpClient $httpClient;

    protected function setUp(): void
    {
        parent::setUp();

        $this->httpClient = new MockHttpClient();
        $this->app->instance(ApiClient::class, ApiClient::create(
            $this->httpClient,
            new HttpFactory(),
            new HttpFactory(),
            new HttpFactory(),
            'test-token',
        ));
    }

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $app['config']->set(MaxServiceProvider::CONFIG_KEY . '.chats.enabled', true);
    }

    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(__DIR__ . '/../../../database/migrations');
    }

    public function testBotAddedCreatesActiveChat(): void
    {
        $this->dispatch(UpdateType::BotAdded, isChannel: true);

        $chat = MaxChat::query()->sole();

        $this->assertSame(111, $chat->user_id);
        $this->assertSame(222, $chat->chat_id);
        $this->assertSame(MaxChatStatus::Active, $chat->status);
    }

    public function testBotStartedCreatesActiveChat(): void
    {
        $this->dispatch(UpdateType::BotStarted, isChannel: true);

        $this->assertSame(MaxChatStatus::Active, MaxChat::query()->sole()->status);
    }

    public function testBotStartedReactivatesExistingChat(): void
    {
        MaxChat::create([
            'user_id' => 111,
            'chat_id' => 222,
            'status' => MaxChatStatus::Stopped,
        ]);

        $this->dispatch(UpdateType::BotStarted, isChannel: true);

        $this->assertSame(1, MaxChat::query()->count());
        $this->assertSame(MaxChatStatus::Active, MaxChat::query()->sole()->status);
    }

    public function testBotStoppedMarksChatStopped(): void
    {
        $this->dispatch(UpdateType::BotStopped, isChannel: true);

        $this->assertSame(MaxChatStatus::Stopped, MaxChat::query()->sole()->status);
    }

    public function testBotRemovedMarksChatRemoved(): void
    {
        $this->dispatch(UpdateType::BotRemoved, isChannel: true);

        $this->assertSame(MaxChatStatus::Removed, MaxChat::query()->sole()->status);
    }

    public function testChatUpdateWithoutChatIdIsSkipped(): void
    {
        $this->dispatch(UpdateType::BotStarted, chatId: null, isChannel: true);

        $this->assertSame(0, MaxChat::query()->count());
    }

    public function testChatUpdateWithoutUserAndUserIdIsSkipped(): void
    {
        $this->dispatch(UpdateType::BotStarted, withUser: false, isChannel: true);

        $this->assertSame(0, MaxChat::query()->count());
    }

    public function testChatUpdateWithTopLevelUserIdIsPersisted(): void
    {
        $this->dispatch(UpdateType::BotStarted, withUser: false, userId: 111, isChannel: true);

        $chat = MaxChat::query()->sole();

        $this->assertSame(111, $chat->user_id);
        $this->assertSame(222, $chat->chat_id);
        $this->assertSame(MaxChatStatus::Active, $chat->status);
    }

    public function testNonChatUpdateTypeIsIgnored(): void
    {
        $this->dispatch(UpdateType::MessageCreated);

        $this->assertSame(0, MaxChat::query()->count());
    }

    public function testBotAddedUpsertsUser(): void
    {
        $this->dispatch(UpdateType::BotAdded, isChannel: true);

        $user = MaxUser::query()->sole();

        $this->assertSame(111, $user->user_id);
        $this->assertSame('Иван', $user->first_name);
        $this->assertNull($user->last_name);
        $this->assertNull($user->username);
        $this->assertFalse($user->is_bot);
        $this->assertNotNull($user->profile_checked_at);
    }

    public function testBotAddedSetsLastActivityAt(): void
    {
        $this->dispatch(UpdateType::BotAdded, isChannel: true);

        $chat = MaxChat::query()->sole();

        $this->assertNotNull($chat->last_activity_at);
        $this->assertEqualsWithDelta(time(), $chat->last_activity_at->timestamp, 2);
    }

    public function testBotStartedUpdatesLastActivityAt(): void
    {
        $old = now()->subHour();
        MaxChat::create([
            'user_id' => 111,
            'chat_id' => 222,
            'status' => MaxChatStatus::Active,
            'last_activity_at' => $old,
        ]);

        $this->dispatch(UpdateType::BotStarted, isChannel: true);

        $chat = MaxChat::query()->sole();

        $this->assertTrue($chat->last_activity_at->greaterThan($old));
    }

    public function testBotAddedLinksChatToUser(): void
    {
        $this->dispatch(UpdateType::BotAdded, isChannel: true);

        $chat = MaxChat::query()->sole();

        $this->assertNotNull($chat->maxUser);
        $this->assertSame(111, $chat->maxUser->user_id);
    }

    public function testBotStartedUpdatesExistingUser(): void
    {
        MaxUser::create([
            'user_id' => 111,
            'first_name' => 'Old',
            'is_bot' => false,
        ]);

        $this->dispatch(UpdateType::BotStarted, isChannel: true);

        $user = MaxUser::query()->sole();

        $this->assertSame('Иван', $user->first_name);
    }

    public function testBotStoppedPersistsUserWhenPresent(): void
    {
        $this->dispatch(UpdateType::BotStopped, isChannel: true);

        $user = MaxUser::query()->sole();
        $chat = MaxChat::query()->sole();

        $this->assertSame(111, $user->user_id);
        $this->assertSame(MaxChatStatus::Stopped, $chat->status);
        $this->assertNotNull($chat->maxUser);
    }

    public function testChatUpdateResolvesExistingUserViaRelation(): void
    {
        MaxUser::create([
            'user_id' => 111,
            'first_name' => 'Иван',
            'is_bot' => false,
        ]);

        $this->dispatch(UpdateType::BotStarted, withUser: false, userId: 111, isChannel: true);

        $chat = MaxChat::query()->sole();

        $this->assertNotNull($chat->maxUser);
        $this->assertSame(111, $chat->maxUser->user_id);
    }

    public function testChatUpdateReturnsNullUserWhenNotInMaxUsers(): void
    {
        $this->dispatch(UpdateType::BotStarted, withUser: false, userId: 999, isChannel: true);

        $chat = MaxChat::query()->sole();

        $this->assertNull($chat->maxUser);
    }

    public function testChatUpdateWithUserPersistsAllFields(): void
    {
        $this->dispatch(UpdateType::BotAdded, user: new User(
            userId: 111,
            firstName: 'Иван',
            lastName: 'Петров',
            username: 'ivan',
            isBot: false,
            lastActivityTime: 1700000000000,
            name: 'Иван Петров',
        ), isChannel: true);

        $user = MaxUser::query()->sole();

        $this->assertSame(111, $user->user_id);
        $this->assertSame('Иван', $user->first_name);
        $this->assertSame('Петров', $user->last_name);
        $this->assertSame('ivan', $user->username);
        $this->assertFalse($user->is_bot);
        $this->assertSame(1700000000000, $user->last_activity_time);
        $this->assertSame('Иван Петров', $user->name);
        $this->assertNull($user->description);
        $this->assertNull($user->avatar_url);
        $this->assertNull($user->full_avatar_url);
    }

    public function testBotAddedWithIsChannelSetsChannelType(): void
    {
        $this->dispatch(UpdateType::BotAdded, isChannel: true);

        $chat = MaxChat::query()->sole();

        $this->assertSame(ChatType::Channel, $chat->chat_type);
    }

    public function testBotAddedWithIsChannelNullCallsGetChat(): void
    {
        $this->queueGetChatResponse('dialog');

        $this->dispatch(UpdateType::BotAdded, isChannel: null);

        $chat = MaxChat::query()->sole();

        $this->assertSame(ChatType::Dialog, $chat->chat_type);
    }

    public function testBotStartedWithIsChannelNullCallsGetChat(): void
    {
        $this->queueGetChatResponse('chat');

        $this->dispatch(UpdateType::BotStarted, isChannel: null);

        $chat = MaxChat::query()->sole();

        $this->assertSame(ChatType::Chat, $chat->chat_type);
    }

    public function testBotAddedWithIsChannelFalseCallsGetChat(): void
    {
        $this->queueGetChatResponse('dialog');

        $this->dispatch(UpdateType::BotAdded, isChannel: false);

        $chat = MaxChat::query()->sole();

        $this->assertSame(ChatType::Dialog, $chat->chat_type);
    }

    public function testBotAddedSkipsGetChatWhenIsChannelTrue(): void
    {
        $this->dispatch(UpdateType::BotAdded, isChannel: true);

        $this->assertSame(0, $this->httpClient->callCount);
        $this->assertSame(ChatType::Channel, MaxChat::query()->sole()->chat_type);
    }

    public function testBotStoppedDoesNotCallGetChat(): void
    {
        MaxChat::create([
            'user_id' => 111,
            'chat_id' => 222,
            'status' => MaxChatStatus::Active,
            'chat_type' => ChatType::Channel,
        ]);

        $this->dispatch(UpdateType::BotStopped, isChannel: null);

        $this->assertSame(0, $this->httpClient->callCount);

        $chat = MaxChat::query()->sole();

        $this->assertSame(MaxChatStatus::Stopped, $chat->status);
        $this->assertSame(ChatType::Channel, $chat->chat_type);
    }

    public function testBotRemovedDoesNotCallGetChat(): void
    {
        MaxChat::create([
            'user_id' => 111,
            'chat_id' => 222,
            'status' => MaxChatStatus::Active,
            'chat_type' => ChatType::Chat,
        ]);

        $this->dispatch(UpdateType::BotRemoved, isChannel: null);

        $this->assertSame(0, $this->httpClient->callCount);

        $chat = MaxChat::query()->sole();

        $this->assertSame(MaxChatStatus::Removed, $chat->status);
        $this->assertSame(ChatType::Chat, $chat->chat_type);
    }

    public function testGetChatExceptionLeavesChatTypeNull(): void
    {
        $this->httpClient->queue(new \GuzzleHttp\Psr7\Response(404, [], json_encode([
            'error_code' => 'CHAT_NOT_FOUND',
            'description' => 'Chat not found',
        ], JSON_THROW_ON_ERROR)));

        $this->dispatch(UpdateType::BotAdded, isChannel: null);

        $chat = MaxChat::query()->sole();

        $this->assertSame(MaxChatStatus::Active, $chat->status);
        $this->assertNull($chat->chat_type);
    }

    public function testBotStoppedPreservesExistingChatType(): void
    {
        MaxChat::create([
            'user_id' => 111,
            'chat_id' => 222,
            'status' => MaxChatStatus::Active,
            'chat_type' => ChatType::Channel,
        ]);

        $this->dispatch(UpdateType::BotStopped, isChannel: true);

        $chat = MaxChat::query()->sole();

        $this->assertSame(MaxChatStatus::Stopped, $chat->status);
        $this->assertSame(ChatType::Channel, $chat->chat_type);
    }

    public function testMessageUpdateFillsExistingChatType(): void
    {
        MaxChat::create([
            'user_id' => 111,
            'chat_id' => 222,
            'status' => MaxChatStatus::Active,
        ]);

        $this->dispatchMessage('dialog', chatTypeInRecipient: true);

        $chat = MaxChat::query()->sole();

        $this->assertSame(MaxChatStatus::Active, $chat->status);
        $this->assertSame(ChatType::Dialog, $chat->chat_type);
    }

    public function testMessageUpdateFillsExistingChatTypeFromRecipient(): void
    {
        MaxChat::create([
            'user_id' => 111,
            'chat_id' => 222,
            'status' => MaxChatStatus::Active,
        ]);

        $this->dispatchMessage('chat');

        $chat = MaxChat::query()->sole();

        $this->assertSame(MaxChatStatus::Active, $chat->status);
        $this->assertSame(ChatType::Chat, $chat->chat_type);
    }

    public function testMessageUpdateDoesNotCreateChat(): void
    {
        $this->dispatchMessage('dialog');

        $this->assertSame(0, MaxChat::query()->count());
    }

    public function testMessageUpdateFillsChannelTimeout(): void
    {
        MaxChat::create([
            'user_id' => 111,
            'chat_id' => 222,
            'status' => MaxChatStatus::Active,
        ]);

        $this->dispatchMessage('dialog', isChannel: true);

        $chat = MaxChat::query()->sole();

        $this->assertSame(ChatType::Dialog, $chat->chat_type);
        $this->assertSame(0, $this->httpClient->callCount);
    }

    public function testMessageUpdateDoesNotOverwriteExistingChatType(): void
    {
        MaxChat::create([
            'user_id' => 111,
            'chat_id' => 222,
            'status' => MaxChatStatus::Active,
            'chat_type' => ChatType::Channel,
        ]);

        $this->dispatchMessage('dialog');

        $chat = MaxChat::query()->sole();

        $this->assertSame(ChatType::Channel, $chat->chat_type);
    }

    public function testCallbackUpdateFillsExistingChatType(): void
    {
        MaxChat::create([
            'user_id' => 111,
            'chat_id' => 222,
            'status' => MaxChatStatus::Active,
        ]);

        $this->dispatchCallback('dialog');

        $chat = MaxChat::query()->sole();

        $this->assertSame(MaxChatStatus::Active, $chat->status);
        $this->assertSame(ChatType::Dialog, $chat->chat_type);
    }

    public function testCallbackUpdateDoesNotCreateChat(): void
    {
        $this->dispatchCallback('dialog');

        $this->assertSame(0, MaxChat::query()->count());
    }

    public function testMessageUpdateWithoutRecipientChatTypeDoesNothing(): void
    {
        MaxChat::create([
            'user_id' => 111,
            'chat_id' => 222,
            'status' => MaxChatStatus::Active,
        ]);

        $this->dispatchMessage(null);

        $chat = MaxChat::query()->sole();

        $this->assertNull($chat->chat_type);
    }

    private function queueGetChatResponse(string $chatType): void
    {
        $this->httpClient->queue(new \GuzzleHttp\Psr7\Response(200, [], json_encode([
            'chat_id' => 222,
            'type' => $chatType,
            'status' => 'active',
            'last_event_time' => 1000,
            'participants_count' => 1,
            'is_public' => false,
        ], JSON_THROW_ON_ERROR)));
    }

    private function dispatch(
        UpdateType $type,
        ?int $chatId = 222,
        bool $withUser = true,
        ?int $userId = null,
        ?User $user = null,
        ?bool $isChannel = null,
    ): void {
        $update = new Update(
            updateType: $type,
            timestamp: 1000,
            user: $user ?? ($withUser ? new User(
                userId: 111,
                firstName: 'Иван',
                lastName: null,
                username: null,
                isBot: false,
                lastActivityTime: 1000,
            ) : null),
            chatId: $chatId,
            userId: $userId,
            isChannel: $isChannel,
        );

        $this->app->make(Dispatcher::class)->dispatch(new MaxUpdateReceived($update));
    }

    private function dispatchMessage(
        ?string $chatType,
        ?bool $isChannel = null,
        bool $chatTypeInRecipient = false,
    ): void {
        $update = new Update(
            updateType: UpdateType::MessageCreated,
            timestamp: 1000,
            message: new Message(
                sender: new User(
                    userId: 111,
                    firstName: 'Иван',
                    lastName: null,
                    username: null,
                    isBot: false,
                    lastActivityTime: 1000,
                ),
                recipient: new Recipient(
                    chatId: 222,
                    userId: 111,
                    chatType: $chatTypeInRecipient ? $chatType : $chatType,
                ),
                timestamp: 1000,
            ),
            chatId: 222,
            isChannel: $isChannel,
        );

        $this->app->make(Dispatcher::class)->dispatch(new MaxUpdateReceived($update));
    }

    private function dispatchCallback(?string $chatType): void
    {
        $update = new Update(
            updateType: UpdateType::MessageCallback,
            timestamp: 1000,
            callback: new Callback(
                callbackId: 'cb-1',
                message: new Message(
                    sender: new User(
                        userId: 111,
                        firstName: 'Иван',
                        lastName: null,
                        username: null,
                        isBot: false,
                        lastActivityTime: 1000,
                    ),
                    recipient: new Recipient(
                        chatId: 222,
                        userId: 111,
                        chatType: $chatType,
                    ),
                    timestamp: 1000,
                ),
            ),
            chatId: 222,
        );

        $this->app->make(Dispatcher::class)->dispatch(new MaxUpdateReceived($update));
    }
}
