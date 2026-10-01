<?php

declare(strict_types=1);

namespace GeekCo\LaravelMaxClient\Tests\Unit\Models;

use GeekCo\LaravelMaxClient\Enums\MaxChatStatus;
use GeekCo\LaravelMaxClient\Models\MaxChat;
use GeekCo\LaravelMaxClient\Models\MaxChatUser;
use GeekCo\LaravelMaxClient\Models\MaxUser;
use GeekCo\LaravelMaxClient\Tests\TestCase;
use GeekCo\MaxPhpClient\Enum\ChatType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

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

        $chat->chatUsers()->delete();

        $this->assertSame('chat 222', $chat->displayName());
    }

    public function testDisplayNameForDialogIgnoresOtherUsersInGroup(): void
    {
        $this->makeUser(111, 'Иван Петров');
        $this->makeUser(222, 'Мария Сидорова');

        $chat = $this->makeChat(ChatType::Chat, title: null, chatId: 222);

        $this->assertSame('Иван Петров', $chat->displayName());
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

    /**
     * Ключ строки чата — сам chat_id из MAX, а суррогатный uuid остаётся только
     * у строки связи, где идентификаторов от MAX нет.
     */
    public function testChatKeyIsChatIdAndLinkIdIsUuid(): void
    {
        $chat = $this->makeChat(ChatType::Chat, 'Группа');
        $link = MaxChatUser::query()->where('chat_id', $chat->chat_id)->sole();

        $this->assertSame($chat->chat_id, $chat->getKey());
        $this->assertFalse($chat->getIncrementing());
        $this->assertTrue(Str::isUuid($link->id));
    }

    public function testIsGroupIsFalseForDialogAndNullType(): void
    {
        $this->assertFalse($this->makeChat(ChatType::Dialog, chatId: 222)->isGroup());
        $this->assertFalse($this->makeChat(null, chatId: 333)->isGroup());
    }

    public function testMetadataIsFillableAndCasted(): void
    {
        $chat = MaxChat::query()->create([
            'chat_id' => 222,
            'status' => MaxChatStatus::Active,
            'chat_type' => ChatType::Chat,
            'title' => 'Группа',
            'description' => 'Описание',
            'link' => 'https://max.ru/g',
            'icon_url' => 'https://max.ru/i.png',
            'chat_checked_at' => '2026-09-30 10:00:00',
        ]);

        $fresh = MaxChat::query()->findOrFail($chat->chat_id);

        $this->assertSame('Группа', $fresh->title);
        $this->assertSame('Описание', $fresh->description);
        $this->assertSame('https://max.ru/g', $fresh->link);
        $this->assertSame('https://max.ru/i.png', $fresh->icon_url);
        $this->assertSame('2026-09-30 10:00:00', $fresh->chat_checked_at?->format('Y-m-d H:i:s'));
    }

    /**
     * Связи проверяются на пересечении: пользователь может состоять в нескольких
     * чатах, а чат — включать многих пользователей, поэтому hasManyThrough легко
     * отдаёт лишние строки при неосторожном ключе.
     */
    public function testMaxUsersReturnsOnlyUsersLinkedToThatChat(): void
    {
        $this->makeUser(111, 'Иван Петров');
        $this->makeUser(112, 'Анна Смирнова');
        $this->makeUser(113, 'Пётр Иванов');

        $chat = $this->makeChat(ChatType::Chat, 'Группа', 222);

        MaxChatUser::query()->create([
            'chat_id' => $chat->chat_id,
            'user_id' => 112,
            'status' => MaxChatStatus::Active,
        ]);

        $other = $this->makeChat(ChatType::Chat, 'Другая группа', 333);

        $this->assertSame(1, $other->chatUsers()->count());
        $this->assertSame([111], $other->maxUsers()->pluck('max_users.user_id')->all());
        $userIds = $chat->maxUsers()
            ->orderBy('max_users.user_id')
            ->pluck('max_users.user_id')
            ->all();

        $this->assertSame([111, 112], $userIds);
    }

    public function testChatUsersReturnsLinksOfThatChat(): void
    {
        $chat = $this->makeChat(ChatType::Chat, 'Группа', 222);

        $links = $chat->chatUsers()->orderBy('max_chat_users.user_id')->get();

        $this->assertCount(1, $links);
        $this->assertSame(111, $links->first()->user_id);
        $this->assertSame(222, $links->first()->chat_id);
    }

    public function testMaxUserMaxChatsReturnsChatsTheUserIsLinkedTo(): void
    {
        $this->makeUser(111, 'Иван Петров');
        $this->makeUser(112, 'Анна Смирнова');

        $first = $this->makeChat(ChatType::Chat, 'Первая', 222);

        MaxChatUser::query()->create([
            'chat_id' => 222,
            'user_id' => 112,
            'status' => MaxChatStatus::Active,
        ]);

        $second = $this->makeChat(ChatType::Chat, 'Вторая', 333);

        $chatIds = MaxUser::query()->findOrFail(111)
            ->maxChats()
            ->orderBy('max_chats.chat_id')
            ->pluck('max_chats.chat_id')
            ->all();

        $this->assertSame([222, 333], $chatIds);
        $this->assertSame([222, 333], [$first->chat_id, $second->chat_id]);
    }

    public function testMaxUserChatLinksReturnsLinks(): void
    {
        $this->makeUser(111, 'Иван Петров');
        $this->makeChat(ChatType::Chat, 'Первая', 222);
        $this->makeChat(ChatType::Chat, 'Вторая', 333);

        $links = MaxUser::query()->findOrFail(111)->chatLinks()->orderBy('max_chat_users.chat_id')->get();

        $this->assertCount(2, $links);
        $this->assertSame([222, 333], $links->pluck('chat_id')->all());
    }

    public function testChatUserBelongsToItsChat(): void
    {
        $this->makeChat(ChatType::Chat, 'Группа', 222);

        $link = MaxChatUser::query()->where('chat_id', 222)->sole();

        $this->assertSame(222, $link->maxChat->chat_id);
        $this->assertSame('Группа', $link->maxChat->title);
    }

    private function makeChat(?ChatType $type, ?string $title = null, int $chatId = 222): MaxChat
    {
        $chat = MaxChat::query()->create([
            'chat_id' => $chatId,
            'status' => MaxChatStatus::Active,
            'chat_type' => $type,
            'title' => $title,
        ]);

        MaxChatUser::query()->create([
            'chat_id' => $chatId,
            'user_id' => 111,
            'status' => MaxChatStatus::Active,
        ]);

        return $chat;
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
