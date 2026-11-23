<?php

declare(strict_types=1);

namespace GeekCo\LaravelMaxClient\Tests\Unit\Listeners;

use GeekCo\LaravelMaxClient\Enums\MaxChatStatus;
use GeekCo\LaravelMaxClient\MaxServiceProvider;
use GeekCo\LaravelMaxClient\Models\MaxChat;
use GeekCo\LaravelMaxClient\Models\MaxChatUser;
use GeekCo\LaravelMaxClient\Models\MaxUser;
use GeekCo\LaravelMaxClient\Tests\Support\MockHttpClient;
use GeekCo\LaravelMaxClient\Tests\TestCase;
use GeekCo\LaravelMaxClient\Webhook\MaxUpdateReceived;
use GeekCo\MaxPhpClient\ApiClient;
use GeekCo\MaxPhpClient\Dto\Callback;
use GeekCo\MaxPhpClient\Dto\CommentMessage;
use GeekCo\MaxPhpClient\Dto\CommentMessageBody;
use GeekCo\MaxPhpClient\Dto\Message;
use GeekCo\MaxPhpClient\Dto\Recipient;
use GeekCo\MaxPhpClient\Dto\Update;
use GeekCo\MaxPhpClient\Dto\Update\BotAddedToChatUpdate;
use GeekCo\MaxPhpClient\Dto\Update\BotRemovedFromChatUpdate;
use GeekCo\MaxPhpClient\Dto\Update\BotStartedUpdate;
use GeekCo\MaxPhpClient\Dto\Update\BotStoppedUpdate;
use GeekCo\MaxPhpClient\Dto\Update\ChatTitleChangedUpdate;
use GeekCo\MaxPhpClient\Dto\Update\CommentCreatedUpdate;
use GeekCo\MaxPhpClient\Dto\Update\MessageCallbackUpdate;
use GeekCo\MaxPhpClient\Dto\Update\MessageCreatedUpdate;
use GeekCo\MaxPhpClient\Dto\User;
use GeekCo\MaxPhpClient\Enum\ChatType;
use GeekCo\MaxPhpClient\Exception\InvalidResponseException;
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
        $this->dispatch('bot_added', isChannel: true);

        $chat = MaxChat::query()->sole();

        $this->assertSame(222, $chat->chat_id);
        $this->assertSame(MaxChatStatus::Active, $chat->status);
    }

    public function testBotStartedCreatesActiveChat(): void
    {
        $this->dispatch('bot_started', isChannel: true);

        $this->assertSame(MaxChatStatus::Active, MaxChat::query()->sole()->status);
    }

    public function testBotStartedReactivatesExistingChat(): void
    {
        MaxChat::create([
            'chat_id' => 222,
            'status' => MaxChatStatus::Stopped,
        ]);

        $this->dispatch('bot_started', isChannel: true);

        $this->assertSame(1, MaxChat::query()->count());
        $this->assertSame(MaxChatStatus::Active, MaxChat::query()->sole()->status);
    }

    public function testBotStoppedMarksChatStopped(): void
    {
        $this->dispatch('bot_stopped', isChannel: true);

        $this->assertSame(MaxChatStatus::Stopped, MaxChat::query()->sole()->status);
    }

    public function testBotRemovedMarksChatRemoved(): void
    {
        $this->dispatch('bot_removed', isChannel: true);

        $this->assertSame(MaxChatStatus::Removed, MaxChat::query()->sole()->status);
    }

    /**
     * В типизированной модели chat_id обязателен для lifecycle-событий: payload
     * без него отклоняется разбором (400 на вебхуке), а не доходит до слушателя.
     */
    public function testBotStartedPayloadWithoutChatIdIsRejected(): void
    {
        $this->expectException(InvalidResponseException::class);

        Update::fromArray([
            'update_type' => 'bot_started',
            'timestamp' => 1000,
            'user' => ['user_id' => 111, 'first_name' => 'Иван', 'is_bot' => false],
        ]);
    }

    public function testChatWideStatusKeepsActivityTimestampFresh(): void
    {
        $this->givenChatRows();

        $this->dispatch('bot_removed');

        $this->assertTrue(
            MaxChat::query()->where('chat_id', 222)->get()
                ->every(fn (MaxChat $chat): bool => $chat->last_activity_at->greaterThan(now()->subMinute())),
        );
    }

    public function testBotRemovedWithUserCreatesRowForUnknownChat(): void
    {
        $this->dispatch('bot_removed');

        $chat = MaxChat::query()->sole();

        $this->assertSame(MaxChatStatus::Removed, $chat->status);
        $this->assertSame([111], $chat->maxUsers()->pluck('max_users.user_id')->all());
    }

    public function testChatWideStatusDoesNotTouchOtherChats(): void
    {
        $this->givenChatRows();
        MaxChat::query()->create([
            'chat_id' => 999,
            'status' => MaxChatStatus::Active,
        ]);

        $this->dispatch('bot_removed');

        $this->assertSame([MaxChatStatus::Active], $this->statusesOfChat(999));
    }

    public function testChatWideStatusFillsChatTypeWhenUpdateCarriesIt(): void
    {
        $this->givenChatRows();

        $this->dispatch('bot_removed', isChannel: true);

        $this->assertSame([ChatType::Channel], $this->chatTypesOfChat(222));
    }

    public function testNonChatUpdateTypeIsIgnored(): void
    {
        $this->dispatchMessage('dialog');

        $this->assertSame(0, MaxChat::query()->count());
    }

    public function testBotAddedUpsertsUser(): void
    {
        $this->dispatch('bot_added', isChannel: true);

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
        $this->dispatch('bot_added', isChannel: true);

        $chat = MaxChat::query()->sole();

        $this->assertNotNull($chat->last_activity_at);
        $this->assertEqualsWithDelta(time(), $chat->last_activity_at->timestamp, 2);
    }

    public function testBotStartedUpdatesLastActivityAt(): void
    {
        $old = now()->subHour();
        MaxChat::create([
            'chat_id' => 222,
            'status' => MaxChatStatus::Active,
            'last_activity_at' => $old,
        ]);

        $this->dispatch('bot_started', isChannel: true);

        $chat = MaxChat::query()->sole();

        $this->assertTrue($chat->last_activity_at->greaterThan($old));
    }

    public function testBotAddedLinksChatToUser(): void
    {
        $this->dispatch('bot_added', isChannel: true);

        $chat = MaxChat::query()->sole();

        $this->assertSame([111], $chat->maxUsers()->pluck('max_users.user_id')->all());
    }

    public function testBotStartedUpdatesExistingUser(): void
    {
        MaxUser::create([
            'user_id' => 111,
            'first_name' => 'Old',
            'is_bot' => false,
        ]);

        $this->dispatch('bot_started', isChannel: true);

        $user = MaxUser::query()->sole();

        $this->assertSame('Иван', $user->first_name);
    }

    public function testBotStoppedPersistsUserWhenPresent(): void
    {
        $this->dispatch('bot_stopped', isChannel: true);

        $user = MaxUser::query()->sole();
        $chat = MaxChat::query()->sole();

        $this->assertSame(111, $user->user_id);
        $this->assertSame(MaxChatStatus::Stopped, $chat->status);
        $this->assertNotNull($chat->maxUsers()->first());
    }

    public function testChatUpdateWithUserPersistsAllFields(): void
    {
        $this->dispatch('bot_added', user: new User(
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
        $this->dispatch('bot_added', isChannel: true);

        $chat = MaxChat::query()->sole();

        $this->assertSame(ChatType::Channel, $chat->chat_type);
    }

    public function testBotAddedWithIsChannelNullCallsGetChat(): void
    {
        $this->queueGetChatResponse('dialog');

        $this->dispatch('bot_added', isChannel: null);

        $chat = MaxChat::query()->sole();

        $this->assertSame(ChatType::Dialog, $chat->chat_type);
    }

    public function testBotStartedWithIsChannelNullCallsGetChat(): void
    {
        $this->queueGetChatResponse('chat');

        $this->dispatch('bot_started', isChannel: null);

        $chat = MaxChat::query()->sole();

        $this->assertSame(ChatType::Chat, $chat->chat_type);
    }

    public function testBotAddedWithIsChannelFalseCallsGetChat(): void
    {
        $this->queueGetChatResponse('dialog');

        $this->dispatch('bot_added', isChannel: false);

        $chat = MaxChat::query()->sole();

        $this->assertSame(ChatType::Dialog, $chat->chat_type);
    }

    public function testBotAddedWithIsChannelTrueFetchesMetadata(): void
    {
        $this->queueGetChatResponse('channel', title: 'Канал MAX');

        $this->dispatch('bot_added', isChannel: true);

        $chat = MaxChat::query()->sole();

        $this->assertSame(1, $this->httpClient->callCount);
        $this->assertSame(ChatType::Channel, $chat->chat_type);
        $this->assertSame('Канал MAX', $chat->title);
    }

    /**
     * Один ответ getChat достаётся всему чату: второй участник, нажавший «старт»
     * позже, не должен ждать собственного запроса в API — метаданные уже лежат
     * в реестре. Заодно проверяется, что в max_chats осталась одна строка, а
     * оба участника уехали в max_chat_users.
     */
    public function testSecondMemberOfSameChatInheritsMetadataWithoutSecondRequest(): void
    {
        $this->queueGetChatResponse('channel', title: 'Канал MAX');

        $this->dispatch('bot_started', isChannel: true);
        $this->dispatch('bot_started', user: new User(
            userId: 222,
            firstName: 'Мария',
            lastName: null,
            username: null,
            isBot: false,
            lastActivityTime: 1000,
        ), isChannel: true);

        $this->assertSame(1, MaxChat::query()->count(), 'Чат должен остаться одной строкой');

        $chat = MaxChat::query()->sole();

        $this->assertSame(1, $this->httpClient->callCount, 'Метаданные уже известны — второй запрос не нужен');
        $this->assertSame('Канал MAX', $chat->title);
        $this->assertSame(ChatType::Channel, $chat->chat_type);
        $this->assertNotNull($chat->chat_checked_at);

        $this->assertSame(
            [111, 222],
            MaxChatUser::query()->orderBy('user_id')->pluck('user_id')->all(),
            'Оба участника должны попасть в связь',
        );
    }

    public function testBotStoppedDoesNotCallGetChat(): void
    {
        MaxChat::create([
            'chat_id' => 222,
            'status' => MaxChatStatus::Active,
            'chat_type' => ChatType::Channel,
        ]);

        $this->dispatch('bot_stopped', isChannel: null);

        $this->assertSame(0, $this->httpClient->callCount);

        $chat = MaxChat::query()->sole();

        $this->assertSame(MaxChatStatus::Stopped, $chat->status);
        $this->assertSame(ChatType::Channel, $chat->chat_type);
    }

    public function testBotRemovedDoesNotCallGetChat(): void
    {
        MaxChat::create([
            'chat_id' => 222,
            'status' => MaxChatStatus::Active,
            'chat_type' => ChatType::Chat,
        ]);

        $this->dispatch('bot_removed', isChannel: null);

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

        $this->dispatch('bot_added', isChannel: null);

        $chat = MaxChat::query()->sole();

        $this->assertSame(MaxChatStatus::Active, $chat->status);
        $this->assertNull($chat->chat_type);
    }

    public function testBotStoppedPreservesExistingChatType(): void
    {
        MaxChat::create([
            'chat_id' => 222,
            'status' => MaxChatStatus::Active,
            'chat_type' => ChatType::Channel,
        ]);

        $this->dispatch('bot_stopped', isChannel: true);

        $chat = MaxChat::query()->sole();

        $this->assertSame(MaxChatStatus::Stopped, $chat->status);
        $this->assertSame(ChatType::Channel, $chat->chat_type);
    }

    public function testMessageUpdateFillsExistingChatType(): void
    {
        MaxChat::create([
            'chat_id' => 222,
            'status' => MaxChatStatus::Active,
        ]);

        $this->dispatchMessage('dialog');

        $chat = MaxChat::query()->sole();

        $this->assertSame(MaxChatStatus::Active, $chat->status);
        $this->assertSame(ChatType::Dialog, $chat->chat_type);
    }

    public function testMessageUpdateFillsExistingChatTypeFromRecipient(): void
    {
        MaxChat::create([
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
            'chat_id' => 222,
            'status' => MaxChatStatus::Active,
        ]);

        $this->dispatchMessage('dialog');

        $chat = MaxChat::query()->sole();

        $this->assertSame(ChatType::Dialog, $chat->chat_type);
        $this->assertSame(0, $this->httpClient->callCount);
    }

    public function testMessageUpdateDoesNotOverwriteExistingChatType(): void
    {
        MaxChat::create([
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
            'chat_id' => 222,
            'status' => MaxChatStatus::Active,
        ]);

        $this->dispatchMessage(null);

        $chat = MaxChat::query()->sole();

        $this->assertNull($chat->chat_type);
    }

    public function testCommentUpdateFillsExistingChatType(): void
    {
        MaxChat::create([
            'chat_id' => 222,
            'status' => MaxChatStatus::Active,
        ]);

        $this->dispatchComment('channel');

        $chat = MaxChat::query()->sole();

        $this->assertSame(MaxChatStatus::Active, $chat->status);
        $this->assertSame(ChatType::Channel, $chat->chat_type);
    }

    public function testCommentUpdateDoesNotCreateChat(): void
    {
        $this->dispatchComment('channel');

        $this->assertSame(0, MaxChat::query()->count());
    }

    public function testCommentUpdateDoesNotOverwriteExistingChatType(): void
    {
        MaxChat::create([
            'chat_id' => 222,
            'status' => MaxChatStatus::Active,
            'chat_type' => ChatType::Chat,
        ]);

        $this->dispatchComment('channel');

        $this->assertSame(ChatType::Chat, MaxChat::query()->sole()->chat_type);
    }

    public function testBotAddedStoresTitleFromChatForGroupWithIsChannelTrue(): void
    {
        $this->queueGetChatResponse('channel', title: 'Рабочая группа');

        $this->dispatch('bot_added', isChannel: true);

        $chat = MaxChat::query()->sole();

        $this->assertSame('Рабочая группа', $chat->title);
        $this->assertSame(ChatType::Channel, $chat->chat_type);
        $this->assertNotNull($chat->chat_checked_at);
    }

    public function testBotAddedDoesNotCallGetChatWhenTitleAlreadyKnown(): void
    {
        MaxChat::create([
            'chat_id' => 222,
            'status' => MaxChatStatus::Active,
            'chat_type' => ChatType::Channel,
            'title' => 'Уже известно',
        ]);

        $this->dispatch('bot_added', isChannel: true);

        $this->assertSame(0, $this->httpClient->callCount);
        $this->assertSame('Уже известно', MaxChat::query()->sole()->title);
    }

    public function testBotAddedDoesNotCallGetChatWhenFetchMetadataDisabled(): void
    {
        config()->set(MaxServiceProvider::CONFIG_KEY . '.chats.fetch_metadata', false);

        $this->dispatch('bot_added', isChannel: true);

        $this->assertSame(0, $this->httpClient->callCount);
        $this->assertSame(ChatType::Channel, MaxChat::query()->sole()->chat_type);
    }

    public function testChatTitleChangedUpdatesTheChatWithoutApiCall(): void
    {
        $this->givenChatRows();

        $this->dispatch('chat_title_changed', title: 'Новое название');

        $this->assertSame(0, $this->httpClient->callCount);

        $chat = MaxChat::query()->sole();

        $this->assertSame('Новое название', $chat->title);
        $this->assertNotNull($chat->chat_checked_at);
    }

    public function testChatTitleChangedIgnoresEmptyTitle(): void
    {
        MaxChat::create([
            'chat_id' => 222,
            'status' => MaxChatStatus::Active,
            'chat_type' => ChatType::Channel,
            'title' => 'Известное',
        ]);

        $this->dispatch('chat_title_changed', title: '   ');
        $this->dispatch('chat_title_changed', title: null);

        $this->assertSame('Известное', MaxChat::query()->sole()->title);
        $this->assertNull(MaxChat::query()->sole()->chat_checked_at);
    }

    public function testChatTitleChangedDoesNotCreateChat(): void
    {
        $this->dispatch('chat_title_changed', title: 'Несуществующий', chatId: 999);

        $this->assertSame(0, MaxChat::query()->count());
    }

    public function testMessageCreatedMovesLastActivityWithoutCreatingChat(): void
    {
        MaxChat::create([
            'chat_id' => 222,
            'status' => MaxChatStatus::Active,
            'chat_type' => ChatType::Chat,
            'last_activity_at' => now()->subDay(),
        ]);

        $this->dispatchMessage('chat');

        $this->assertSame(0, $this->httpClient->callCount);

        $chat = MaxChat::query()->sole();

        $this->assertTrue($chat->last_activity_at->greaterThan(now()->subMinute()));
        $this->assertSame(1, MaxChat::query()->count());
    }

    public function testMessageCallbackMovesLastActivity(): void
    {
        MaxChat::create([
            'chat_id' => 222,
            'status' => MaxChatStatus::Active,
            'chat_type' => ChatType::Chat,
            'last_activity_at' => now()->subDay(),
        ]);

        $this->dispatchCallback('chat');

        $this->assertTrue(MaxChat::query()->sole()->last_activity_at->greaterThan(now()->subMinute()));
    }

    private function givenChatRows(MaxChatStatus $status = MaxChatStatus::Active): void
    {
        MaxChat::query()->create([
            'chat_id' => 222,
            'status' => $status,
        ]);
    }

    /**
     * @return list<MaxChatStatus>
     */
    private function statusesOfChat(int $chatId): array
    {
        return MaxChat::query()
            ->where('chat_id', $chatId)
            ->pluck('status')
            ->all();
    }

    /**
     * @return list<string>
     */
    private function chatTypesOfChat(int $chatId): array
    {
        return MaxChat::query()
            ->where('chat_id', $chatId)
            ->orderBy('user_id')
            ->pluck('chat_type')
            ->all();
    }

    private function queueGetChatResponse(string $chatType, ?string $title = null): void
    {
        $this->httpClient->queue(new \GuzzleHttp\Psr7\Response(200, [], json_encode([
            'chat_id' => 222,
            'type' => $chatType,
            'status' => 'active',
            'last_event_time' => 1000,
            'participants_count' => 1,
            'is_public' => false,
            'title' => $title,
        ], JSON_THROW_ON_ERROR)));
    }

    private function dispatch(
        string $type,
        ?int $chatId = 222,
        ?User $user = null,
        ?bool $isChannel = null,
        ?string $title = null,
    ): void {
        $user = $user ?? $this->user();

        $update = match ($type) {
            'bot_added' => new BotAddedToChatUpdate(
                timestamp: 1000,
                chatId: $chatId,
                user: $user,
                isChannel: $isChannel ?? false,
            ),
            'bot_started' => new BotStartedUpdate(timestamp: 1000, chatId: $chatId, user: $user),
            'bot_stopped' => new BotStoppedUpdate(timestamp: 1000, chatId: $chatId, user: $user),
            'bot_removed' => new BotRemovedFromChatUpdate(
                timestamp: 1000,
                chatId: $chatId,
                user: $user,
                isChannel: $isChannel ?? false,
            ),
            'chat_title_changed' => new ChatTitleChangedUpdate(
                timestamp: 1000,
                chatId: $chatId,
                user: $user,
                title: $title ?? '',
            ),
            default => throw new \LogicException(sprintf('Unsupported update type in test: %s', $type)),
        };

        $this->dispatchUpdate($update);
    }

    private function dispatchMessage(?string $chatType): void
    {
        $message = new Message(
            sender: $this->user(),
            recipient: new Recipient(
                chatId: 222,
                userId: 111,
                chatType: $chatType === null ? null : ChatType::tryFrom($chatType),
            ),
            timestamp: 1000,
        );

        $this->dispatchUpdate(new MessageCreatedUpdate(timestamp: 1000, message: $message));
    }

    private function dispatchCallback(?string $chatType): void
    {
        $message = new Message(
            sender: $this->user(),
            recipient: new Recipient(
                chatId: 222,
                userId: 111,
                chatType: $chatType === null ? null : ChatType::tryFrom($chatType),
            ),
            timestamp: 1000,
        );

        $this->dispatchUpdate(new MessageCallbackUpdate(
            timestamp: 1000,
            callback: new Callback(callbackId: 'cb-1', user: $this->user()),
            message: $message,
        ));
    }

    private function dispatchComment(?string $chatType): void
    {
        $message = new CommentMessage(
            recipient: new Recipient(
                chatId: 222,
                chatType: $chatType === null ? null : ChatType::tryFrom($chatType),
                postId: 'mid_post',
            ),
            timestamp: 1000,
            body: new CommentMessageBody(mid: 'c1', seq: 2, text: 'Хороший пост'),
            sender: $this->user(),
        );

        $this->dispatchUpdate(new CommentCreatedUpdate(timestamp: 1000, message: $message));
    }

    private function dispatchUpdate(Update $update): void
    {
        $this->app->make(Dispatcher::class)->dispatch(new MaxUpdateReceived($update));
    }

    private function user(): User
    {
        return new User(
            userId: 111,
            firstName: 'Иван',
            lastName: null,
            username: null,
            isBot: false,
            lastActivityTime: 1000,
        );
    }
}
