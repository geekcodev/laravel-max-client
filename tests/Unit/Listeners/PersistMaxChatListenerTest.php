<?php

declare(strict_types=1);

namespace GeekCo\LaravelMaxClient\Tests\Unit\Listeners;

use GeekCo\LaravelMaxClient\Enums\MaxChatStatus;
use GeekCo\LaravelMaxClient\MaxServiceProvider;
use GeekCo\LaravelMaxClient\Models\MaxChat;
use GeekCo\LaravelMaxClient\Models\MaxUser;
use GeekCo\LaravelMaxClient\Tests\TestCase;
use GeekCo\LaravelMaxClient\Webhook\MaxUpdateReceived;
use GeekCo\MaxPhpClient\Dto\Update;
use GeekCo\MaxPhpClient\Dto\User;
use GeekCo\MaxPhpClient\Enum\UpdateType;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;

final class PersistMaxChatListenerTest extends TestCase
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

        $chat = MaxChat::query()->sole();

        $this->assertSame(111, $chat->user_id);
        $this->assertSame(222, $chat->chat_id);
        $this->assertSame(MaxChatStatus::Active, $chat->status);
    }

    public function testBotStartedCreatesActiveChat(): void
    {
        $this->dispatch(UpdateType::BotStarted);

        $this->assertSame(MaxChatStatus::Active, MaxChat::query()->sole()->status);
    }

    public function testBotStartedReactivatesExistingChat(): void
    {
        MaxChat::create([
            'user_id' => 111,
            'chat_id' => 222,
            'status' => MaxChatStatus::Stopped,
        ]);

        $this->dispatch(UpdateType::BotStarted);

        $this->assertSame(1, MaxChat::query()->count());
        $this->assertSame(MaxChatStatus::Active, MaxChat::query()->sole()->status);
    }

    public function testBotStoppedMarksChatStopped(): void
    {
        $this->dispatch(UpdateType::BotStopped);

        $this->assertSame(MaxChatStatus::Stopped, MaxChat::query()->sole()->status);
    }

    public function testBotRemovedMarksChatRemoved(): void
    {
        $this->dispatch(UpdateType::BotRemoved);

        $this->assertSame(MaxChatStatus::Removed, MaxChat::query()->sole()->status);
    }

    public function testChatUpdateWithoutChatIdIsSkipped(): void
    {
        $this->dispatch(UpdateType::BotStarted, chatId: null);

        $this->assertSame(0, MaxChat::query()->count());
    }

    public function testChatUpdateWithoutUserAndUserIdIsSkipped(): void
    {
        $this->dispatch(UpdateType::BotStarted, withUser: false);

        $this->assertSame(0, MaxChat::query()->count());
    }

    public function testChatUpdateWithTopLevelUserIdIsPersisted(): void
    {
        $this->dispatch(UpdateType::BotStarted, withUser: false, userId: 111);

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
        $this->dispatch(UpdateType::BotAdded);

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
        $this->dispatch(UpdateType::BotAdded);

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

        $this->dispatch(UpdateType::BotStarted);

        $chat = MaxChat::query()->sole();

        $this->assertTrue($chat->last_activity_at->greaterThan($old));
    }

    public function testBotAddedLinksChatToUser(): void
    {
        $this->dispatch(UpdateType::BotAdded);

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

        $this->dispatch(UpdateType::BotStarted);

        $user = MaxUser::query()->sole();

        $this->assertSame('Иван', $user->first_name);
    }

    public function testBotStoppedPersistsUserWhenPresent(): void
    {
        $this->dispatch(UpdateType::BotStopped);

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

        $this->dispatch(UpdateType::BotStarted, withUser: false, userId: 111);

        $chat = MaxChat::query()->sole();

        $this->assertNotNull($chat->maxUser);
        $this->assertSame(111, $chat->maxUser->user_id);
    }

    public function testChatUpdateReturnsNullUserWhenNotInMaxUsers(): void
    {
        $this->dispatch(UpdateType::BotStarted, withUser: false, userId: 999);

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
