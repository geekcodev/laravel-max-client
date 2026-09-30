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

    public function testBotRemovedWithoutUserMarksEveryChatRowRemoved(): void
    {
        $this->givenChatRows();

        $this->dispatch(UpdateType::BotRemoved, withUser: false);

        $this->assertSame(
            [MaxChatStatus::Removed, MaxChatStatus::Removed],
            $this->statusesOfChat(222),
        );
    }

    public function testBotStoppedWithoutUserMarksEveryChatRowStopped(): void
    {
        $this->givenChatRows();

        $this->dispatch(UpdateType::BotStopped, withUser: false);

        $this->assertSame(
            [MaxChatStatus::Stopped, MaxChatStatus::Stopped],
            $this->statusesOfChat(222),
        );
    }

    public function testBotAddedWithoutUserReactivatesKnownChatRows(): void
    {
        $this->givenChatRows(status: MaxChatStatus::Removed);

        $this->dispatch(UpdateType::BotAdded, withUser: false);

        $this->assertSame(
            [MaxChatStatus::Active, MaxChatStatus::Active],
            $this->statusesOfChat(222),
        );
    }

    public function testChatWideStatusKeepsActivityTimestampFresh(): void
    {
        $this->givenChatRows();

        $this->dispatch(UpdateType::BotRemoved, withUser: false);

        $this->assertTrue(
            MaxChat::query()->where('chat_id', 222)->get()
                ->every(fn (MaxChat $chat): bool => $chat->last_activity_at->greaterThan(now()->subMinute())),
        );
    }

    public function testBotRemovedWithoutUserCreatesNoRowsForUnknownChat(): void
    {
        $this->dispatch(UpdateType::BotRemoved, withUser: false);

        $this->assertSame(0, MaxChat::query()->count());
    }

    public function testChatWideStatusWithoutChatIdChangesNothing(): void
    {
        $this->givenChatRows();

        $this->dispatch(UpdateType::BotRemoved, withUser: false, chatId: null);

        $this->assertSame(
            [MaxChatStatus::Active, MaxChatStatus::Active],
            $this->statusesOfChat(222),
        );
    }

    public function testChatWideStatusDoesNotTouchOtherChats(): void
    {
        $this->givenChatRows();
        MaxChat::query()->create([
            'user_id' => 333,
            'chat_id' => 999,
            'status' => MaxChatStatus::Active,
        ]);

        $this->dispatch(UpdateType::BotRemoved, withUser: false);

        $this->assertSame([MaxChatStatus::Active], $this->statusesOfChat(999));
    }

    public function testChatWideStatusFillsChatTypeWhenUpdateCarriesIt(): void
    {
        $this->givenChatRows();

        $this->dispatch(UpdateType::BotRemoved, withUser: false, isChannel: true);

        $this->assertSame([ChatType::Channel, ChatType::Channel], $this->chatTypesOfChat(222));
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

    public function testBotAddedWithIsChannelTrueFetchesMetadata(): void
    {
        $this->queueGetChatResponse('channel', title: 'Канал MAX');

        $this->dispatch(UpdateType::BotAdded, isChannel: true);

        $chat = MaxChat::query()->sole();

        $this->assertSame(1, $this->httpClient->callCount);
        $this->assertSame(ChatType::Channel, $chat->chat_type);
        $this->assertSame('Канал MAX', $chat->title);
    }

    /**
     * Реестр хранит по записи на каждого участника чата, поэтому один ответ
     * getChat должен достаться всем записям с тем же chat_id — включая те, что
     * появились позже, когда участник нажал «старт». Вторая запись не должна
     * ждать собственного запроса в API: метаданные уже лежат в реестре.
     */
    public function testSecondMemberOfSameChatInheritsMetadataWithoutSecondRequest(): void
    {
        $this->queueGetChatResponse('channel', title: 'Канал MAX');

        $this->dispatch(UpdateType::BotStarted, isChannel: true);
        $this->dispatch(UpdateType::BotStarted, user: new User(
            userId: 222,
            firstName: 'Мария',
            lastName: null,
            username: null,
            isBot: false,
            lastActivityTime: 1000,
        ), isChannel: true);

        $first = MaxChat::query()->where('user_id', 111)->sole();
        $second = MaxChat::query()->where('user_id', 222)->sole();

        $this->assertSame(1, $this->httpClient->callCount, 'Метаданные уже известны — второй запрос не нужен');
        $this->assertSame('Канал MAX', $first->title);
        $this->assertSame('Канал MAX', $second->title, 'Новая запись должна получить известные метаданные');
        $this->assertSame(ChatType::Channel, $second->chat_type);
        $this->assertNotNull($second->title_checked_at);
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

    public function testCommentUpdateFillsExistingChatType(): void
    {
        MaxChat::create([
            'user_id' => 111,
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
            'user_id' => 111,
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

        $this->dispatch(UpdateType::BotAdded, isChannel: true);

        $chat = MaxChat::query()->sole();

        $this->assertSame('Рабочая группа', $chat->title);
        $this->assertSame(ChatType::Channel, $chat->chat_type);
        $this->assertNotNull($chat->title_checked_at);
    }

    public function testBotAddedDoesNotCallGetChatWhenTitleAlreadyKnown(): void
    {
        MaxChat::create([
            'user_id' => 111,
            'chat_id' => 222,
            'status' => MaxChatStatus::Active,
            'chat_type' => ChatType::Channel,
            'title' => 'Уже известно',
        ]);

        $this->dispatch(UpdateType::BotAdded, isChannel: true);

        $this->assertSame(0, $this->httpClient->callCount);
        $this->assertSame('Уже известно', MaxChat::query()->sole()->title);
    }

    public function testBotAddedDoesNotCallGetChatWhenFetchMetadataDisabled(): void
    {
        config()->set(MaxServiceProvider::CONFIG_KEY . '.chats.fetch_metadata', false);

        $this->dispatch(UpdateType::BotAdded, isChannel: true);

        $this->assertSame(0, $this->httpClient->callCount);
        $this->assertSame(ChatType::Channel, MaxChat::query()->sole()->chat_type);
    }

    public function testChatTitleChangedUpdatesEveryRowOfTheChatWithoutApiCall(): void
    {
        foreach ([111, 112] as $userId) {
            MaxChat::create([
                'user_id' => $userId,
                'chat_id' => 222,
                'status' => MaxChatStatus::Active,
                'chat_type' => ChatType::Channel,
            ]);
        }

        $this->dispatch(UpdateType::ChatTitleChanged, title: 'Новое название');

        $this->assertSame(0, $this->httpClient->callCount);
        $this->assertSame(2, MaxChat::query()->where('title', 'Новое название')->count());

        foreach (MaxChat::query()->get() as $chat) {
            $this->assertNotNull($chat->title_checked_at);
        }
    }

    public function testChatTitleChangedIgnoresEmptyTitle(): void
    {
        MaxChat::create([
            'user_id' => 111,
            'chat_id' => 222,
            'status' => MaxChatStatus::Active,
            'chat_type' => ChatType::Channel,
            'title' => 'Известное',
        ]);

        $this->dispatch(UpdateType::ChatTitleChanged, title: '   ');
        $this->dispatch(UpdateType::ChatTitleChanged, title: null);

        $this->assertSame('Известное', MaxChat::query()->sole()->title);
        $this->assertNull(MaxChat::query()->sole()->title_checked_at);
    }

    public function testChatTitleChangedDoesNotCreateChat(): void
    {
        $this->dispatch(UpdateType::ChatTitleChanged, title: 'Несуществующий', chatId: 999);

        $this->assertSame(0, MaxChat::query()->count());
    }

    public function testMessageCreatedMovesLastActivityWithoutCreatingChat(): void
    {
        MaxChat::create([
            'user_id' => 111,
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
            'user_id' => 111,
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
        foreach ([111, 222] as $userId) {
            MaxChat::query()->create([
                'user_id' => $userId,
                'chat_id' => 222,
                'status' => $status,
            ]);
        }
    }

    /**
     * @return list<MaxChatStatus>
     */
    private function statusesOfChat(int $chatId): array
    {
        return MaxChat::query()
            ->where('chat_id', $chatId)
            ->orderBy('user_id')
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
        UpdateType $type,
        ?int $chatId = 222,
        bool $withUser = true,
        ?int $userId = null,
        ?User $user = null,
        ?bool $isChannel = null,
        ?string $title = null,
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
            title: $title,
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

    private function dispatchComment(?string $chatType): void
    {
        $update = Update::fromArray([
            'update_type' => UpdateType::CommentCreated->value,
            'timestamp' => 1000,
            'is_channel' => true,
            'message' => [
                'sender' => ['user_id' => 111, 'first_name' => 'Иван', 'is_bot' => false, 'last_activity_time' => 1000],
                'recipient' => ['chat_id' => 222, 'chat_type' => $chatType, 'post_id' => 'mid_post'],
                'timestamp' => 1000,
                'body' => ['mid' => 'c1', 'seq' => 2, 'text' => 'Хороший пост'],
            ],
        ]);

        $this->app->make(Dispatcher::class)->dispatch(new MaxUpdateReceived($update));
    }
}
