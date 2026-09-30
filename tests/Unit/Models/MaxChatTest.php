<?php

declare(strict_types=1);

namespace GeekCo\LaravelMaxClient\Tests\Unit\Models;

use GeekCo\LaravelMaxClient\Enums\MaxChatStatus;
use GeekCo\LaravelMaxClient\Models\MaxChat;
use GeekCo\LaravelMaxClient\Models\MaxUser;
use GeekCo\LaravelMaxClient\Tests\TestCase;
use GeekCo\MaxPhpClient\Enum\ChatType;
use Illuminate\Foundation\Testing\RefreshDatabase;

final class MaxChatTest extends TestCase
{
    use RefreshDatabase;

    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(__DIR__ . '/../../database/migrations');
    }

    public function testDisplayNameForDialogUsesUserName(): void
    {
        $this->makeUser(111, 'Иван Петров');

        $chat = $this->makeChat(ChatType::Dialog);

        $this->assertSame('Иван Петров', $chat->displayName());
        $this->assertFalse($chat->isGroup());
    }

    public function testDisplayNameForGroupUsesChatTitle(): void
    {
        $this->makeUser(111, 'Иван Петров');

        $chat = $this->makeChat(ChatType::Chat, title: 'Группа MAX');

        $this->assertSame('Группа MAX', $chat->displayName());
        $this->assertTrue($chat->isGroup());
    }

    public function testDisplayNameForChannelUsesChatTitle(): void
    {
        $this->makeUser(111, 'Иван Петров');

        $chat = $this->makeChat(ChatType::Channel, title: 'Канал MAX');

        $this->assertSame('Канал MAX', $chat->displayName());
        $this->assertTrue($chat->isGroup());
    }

    public function testDisplayNameForGroupWithoutTitleFallsBackToUser(): void
    {
        $this->makeUser(111, 'Иван Петров');

        $chat = $this->makeChat(ChatType::Chat, title: null);

        $this->assertSame('Иван Петров', $chat->displayName());
    }

    public function testDisplayNameFallsBackToChatIdWhenNothingKnown(): void
    {
        $chat = $this->makeChat(ChatType::Chat, title: null);

        $this->assertSame('chat 222', $chat->displayName());
    }

    public function testDisplayNameFallsBackToFullNameWhenNameColumnEmpty(): void
    {
        MaxUser::query()->create([
            'user_id' => 111,
            'first_name' => 'Иван',
            'last_name' => 'Петров',
        ]);

        $this->assertSame('Иван Петров', $this->makeChat(ChatType::Dialog)->displayName());
    }

    public function testIsGroupIsFalseForDialogAndNullType(): void
    {
        $this->assertFalse($this->makeChat(ChatType::Dialog, chatId: 222)->isGroup());
        $this->assertFalse($this->makeChat(null, chatId: 333)->isGroup());
    }

    public function testMetadataIsFillableAndCasted(): void
    {
        $chat = MaxChat::query()->create([
            'user_id' => 111,
            'chat_id' => 222,
            'status' => MaxChatStatus::Active,
            'chat_type' => ChatType::Chat,
            'title' => 'Группа',
            'description' => 'Описание',
            'link' => 'https://max.ru/g',
            'icon_url' => 'https://max.ru/i.png',
            'title_checked_at' => '2026-09-30 10:00:00',
        ]);

        $fresh = MaxChat::query()->findOrFail($chat->id);

        $this->assertSame('Группа', $fresh->title);
        $this->assertSame('Описание', $fresh->description);
        $this->assertSame('https://max.ru/g', $fresh->link);
        $this->assertSame('https://max.ru/i.png', $fresh->icon_url);
        $this->assertSame('2026-09-30 10:00:00', $fresh->title_checked_at?->format('Y-m-d H:i:s'));
    }

    private function makeChat(?ChatType $type, ?string $title = null, int $chatId = 222): MaxChat
    {
        return MaxChat::query()->create([
            'user_id' => 111,
            'chat_id' => $chatId,
            'status' => MaxChatStatus::Active,
            'chat_type' => $type,
            'title' => $title,
        ]);
    }

    private function makeUser(int $userId, string $name): MaxUser
    {
        return MaxUser::query()->create([
            'user_id' => $userId,
            'first_name' => explode(' ', $name)[0],
            'last_name' => explode(' ', $name)[1] ?? null,
            'name' => $name,
        ]);
    }
}
