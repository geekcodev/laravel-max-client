<?php

declare(strict_types=1);

namespace GeekCo\LaravelMaxClient\Tests\Unit\Listeners;

use GeekCo\LaravelMaxClient\Enums\BotChatStatus;
use GeekCo\LaravelMaxClient\MaxServiceProvider;
use GeekCo\LaravelMaxClient\Models\BotChat;
use GeekCo\LaravelMaxClient\Models\MaxUser;
use GeekCo\LaravelMaxClient\Tests\TestCase;
use GeekCo\LaravelMaxClient\Webhook\MaxUpdateReceived;
use GeekCo\MaxPhpClient\Dto\Update;
use GeekCo\MaxPhpClient\Dto\User;
use GeekCo\MaxPhpClient\Enum\UpdateType;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;

final class PersistBotChatListenerTest extends TestCase
{
    use RefreshDatabase;

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
        $this->dispatch(UpdateType::BotAdded);

        $chat = BotChat::query()->sole();

        $this->assertSame(111, $chat->user_id);
        $this->assertSame(222, $chat->chat_id);
        $this->assertSame(BotChatStatus::Active, $chat->status);
    }

    public function testBotStartedCreatesActiveChat(): void
    {
        $this->dispatch(UpdateType::BotStarted);

        $this->assertSame(BotChatStatus::Active, BotChat::query()->sole()->status);
    }

    public function testBotStartedReactivatesExistingChat(): void
    {
        BotChat::create([
            'user_id' => 111,
            'chat_id' => 222,
            'status' => BotChatStatus::Stopped,
        ]);

        $this->dispatch(UpdateType::BotStarted);

        $this->assertSame(1, BotChat::query()->count());
        $this->assertSame(BotChatStatus::Active, BotChat::query()->sole()->status);
    }

    public function testBotStoppedMarksChatStopped(): void
    {
        $this->dispatch(UpdateType::BotStopped);

        $this->assertSame(BotChatStatus::Stopped, BotChat::query()->sole()->status);
    }

    public function testBotRemovedMarksChatRemoved(): void
    {
        $this->dispatch(UpdateType::BotRemoved);

        $this->assertSame(BotChatStatus::Removed, BotChat::query()->sole()->status);
    }

    public function testChatUpdateWithoutChatIdIsSkipped(): void
    {
        $this->dispatch(UpdateType::BotStarted, chatId: null);

        $this->assertSame(0, BotChat::query()->count());
    }

    public function testChatUpdateWithoutUserAndUserIdIsSkipped(): void
    {
        $this->dispatch(UpdateType::BotStarted, withUser: false);

        $this->assertSame(0, BotChat::query()->count());
    }

    public function testChatUpdateWithTopLevelUserIdIsPersisted(): void
    {
        $this->dispatch(UpdateType::BotStarted, withUser: false, userId: 111);

        $chat = BotChat::query()->sole();

        $this->assertSame(111, $chat->user_id);
        $this->assertSame(222, $chat->chat_id);
        $this->assertSame(BotChatStatus::Active, $chat->status);
    }

    public function testNonChatUpdateTypeIsIgnored(): void
    {
        $this->dispatch(UpdateType::MessageCreated);

        $this->assertSame(0, BotChat::query()->count());
    }

    public function testBotAddedUpsertsUser(): void
    {
        $this->dispatch(UpdateType::BotAdded);

        $user = MaxUser::query()->sole();

        $this->assertSame(111, $user->user_id);
        $this->assertSame('Иван', $user->first_name);
        $this->assertNull($user->last_name);
        $this->assertNull($user->username);
        $this->assertFalse($user->is_bot);
    }

    public function testBotAddedLinksChatToUser(): void
    {
        $this->dispatch(UpdateType::BotAdded);

        $chat = BotChat::query()->sole();

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

        $this->dispatch(UpdateType::BotStarted);

        $user = MaxUser::query()->sole();

        $this->assertSame('Иван', $user->first_name);
    }

    public function testBotStoppedPersistsUserWhenPresent(): void
    {
        $this->dispatch(UpdateType::BotStopped);

        $user = MaxUser::query()->sole();
        $chat = BotChat::query()->sole();

        $this->assertSame(111, $user->user_id);
        $this->assertSame(BotChatStatus::Stopped, $chat->status);
        $this->assertNotNull($chat->maxUser);
    }

    public function testChatUpdateResolvesExistingUserViaRelation(): void
    {
        MaxUser::create([
            'user_id' => 111,
            'first_name' => 'Иван',
            'is_bot' => false,
        ]);

        $this->dispatch(UpdateType::BotStarted, withUser: false, userId: 111);

        $chat = BotChat::query()->sole();

        $this->assertNotNull($chat->maxUser);
        $this->assertSame(111, $chat->maxUser->user_id);
    }

    public function testChatUpdateReturnsNullUserWhenNotInMaxUsers(): void
    {
        $this->dispatch(UpdateType::BotStarted, withUser: false, userId: 999);

        $chat = BotChat::query()->sole();

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
        ));

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

    private function dispatch(
        UpdateType $type,
        ?int $chatId = 222,
        bool $withUser = true,
        ?int $userId = null,
        ?User $user = null,
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
        );

        $this->app->make(Dispatcher::class)->dispatch(new MaxUpdateReceived($update));
    }
}
