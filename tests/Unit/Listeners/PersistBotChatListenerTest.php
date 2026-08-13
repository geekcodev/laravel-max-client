<?php

declare(strict_types=1);

namespace GeekCo\LaravelMaxClient\Tests\Unit\Listeners;

use GeekCo\LaravelMaxClient\Enums\BotChatStatus;
use GeekCo\LaravelMaxClient\MaxServiceProvider;
use GeekCo\LaravelMaxClient\Models\BotChat;
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

    private function dispatch(UpdateType $type, ?int $chatId = 222, bool $withUser = true, ?int $userId = null): void
    {
        $update = new Update(
            updateType: $type,
            timestamp: 1000,
            user: $withUser ? new User(
                userId: 111,
                firstName: 'Иван',
                lastName: null,
                username: null,
                isBot: false,
                lastActivityTime: 1000,
            ) : null,
            chatId: $chatId,
            userId: $userId,
        );

        $this->app->make(Dispatcher::class)->dispatch(new MaxUpdateReceived($update));
    }
}
