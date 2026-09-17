<?php

declare(strict_types=1);

namespace GeekCo\LaravelMaxClient\Tests\Unit\Services;

use GeekCo\LaravelMaxClient\Enums\MaxChatStatus;
use GeekCo\LaravelMaxClient\MaxServiceProvider;
use GeekCo\LaravelMaxClient\Models\MaxChat;
use GeekCo\LaravelMaxClient\Models\MaxUser;
use GeekCo\LaravelMaxClient\Services\MaxUserProfileService;
use GeekCo\LaravelMaxClient\Tests\Support\MockHttpClient;
use GeekCo\LaravelMaxClient\Tests\TestCase;
use GeekCo\MaxPhpClient\Dto\ChatMember;
use GeekCo\MaxPhpClient\Enum\ChatType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Psr\Http\Client\ClientInterface;

final class MaxUserProfileServiceTest extends TestCase
{
    use RefreshDatabase;

    private MockHttpClient $http;

    private MaxUserProfileService $service;

    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(__DIR__ . '/../../../database/migrations');
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->http = new MockHttpClient();
        $this->app->instance(ClientInterface::class, $this->http);
        $this->service = $this->app->make(MaxUserProfileService::class);
    }

    public function testRefreshWithoutActiveChatSkipsApiCall(): void
    {
        $this->assertFalse($this->service->refresh(111));
        $this->assertSame(0, $this->http->callCount);
    }

    public function testRefreshWithStoppedChatSkipsApiCall(): void
    {
        MaxChat::create([
            'user_id' => 111,
            'chat_id' => 222,
            'status' => MaxChatStatus::Stopped,
        ]);

        $this->assertFalse($this->service->refresh(111));
        $this->assertSame(0, $this->http->callCount);
    }

    public function testRefreshWithActiveChatFetchesAndPersistsAvatar(): void
    {
        MaxChat::create([
            'user_id' => 111,
            'chat_id' => 222,
            'status' => MaxChatStatus::Active,
            'chat_type' => ChatType::Chat,
        ]);
        $this->http->queue($this->chatMemberResponse(111));

        $this->assertTrue($this->service->refresh(111));
        $this->assertSame(1, $this->http->callCount);
        $this->assertStringContainsString('/chats/222/members', $this->http->lastRequest->getUri()->getPath());

        parse_str($this->http->lastRequest->getUri()->getQuery(), $query);
        $this->assertSame('111', $query['user_ids']);

        $user = MaxUser::query()->sole();
        $this->assertSame(111, $user->user_id);
        $this->assertSame('https://avatars.example/111_s.jpg', $user->avatar_url);
        $this->assertSame('https://avatars.example/111.jpg', $user->full_avatar_url);
        $this->assertNotNull($user->profile_checked_at);
    }

    public function testRefreshGathersUserIdsFromMultipleChats(): void
    {
        MaxChat::create(['user_id' => 111, 'chat_id' => 222, 'status' => MaxChatStatus::Active, 'chat_type' => ChatType::Chat]);
        MaxChat::create(['user_id' => 222, 'chat_id' => 333, 'status' => MaxChatStatus::Active, 'chat_type' => ChatType::Chat]);
        $this->http->queue($this->chatMemberResponse(111));
        $this->http->queue($this->chatMemberResponse(222));

        $this->assertTrue($this->service->refresh([111, 222]));
        $this->assertSame(2, $this->http->callCount);

        parse_str($this->http->requests[0]->getUri()->getQuery(), $first);
        parse_str($this->http->requests[1]->getUri()->getQuery(), $second);
        $this->assertSame('111', $first['user_ids']);
        $this->assertSame('222', $second['user_ids']);
        $this->assertSame(2, MaxUser::query()->count());
    }

    public function testRefreshBatchesUserIdsByProfileBatchSize(): void
    {
        for ($id = 1; $id <= 120; ++$id) {
            MaxChat::create(['user_id' => $id, 'chat_id' => 222, 'status' => MaxChatStatus::Active, 'chat_type' => ChatType::Chat]);
        }
        $this->http->queue($this->emptyChatMembersResponse());
        $this->http->queue($this->emptyChatMembersResponse());
        $this->http->queue($this->emptyChatMembersResponse());

        $this->assertFalse($this->service->refresh(range(1, 120)));
        $this->assertSame(3, $this->http->callCount);

        $sizes = array_map(
            static function ($request): int {
                parse_str($request->getUri()->getQuery(), $query);

                return \count(explode(',', $query['user_ids']));
            },
            $this->http->requests,
        );
        $this->assertSame([50, 50, 20], $sizes);
    }

    public function testRefreshSkipsWhenProfileFromActiveChatsDisabled(): void
    {
        $this->app['config']->set(MaxServiceProvider::CONFIG_KEY . '.users.profile_from_active_chats', false);
        MaxChat::create(['user_id' => 111, 'chat_id' => 222, 'status' => MaxChatStatus::Active, 'chat_type' => ChatType::Chat]);

        $this->assertFalse($this->service->refresh(111));
        $this->assertSame(0, $this->http->callCount);
    }

    public function testRefreshIgnoresInvalidUserIds(): void
    {
        $this->assertFalse($this->service->refresh([0, -1]));
        $this->assertSame(0, $this->http->callCount);
    }

    public function testUpsertFromMemberPersistsFullUser(): void
    {
        $member = new ChatMember(
            userId: 111,
            firstName: 'Иван',
            lastName: 'Петров',
            username: 'ivan',
            isBot: false,
            lastActivityTime: 1700000000000,
            name: 'Иван Петров',
            description: 'Разработчик',
            avatarUrl: 'https://avatars.example/111_s.jpg',
            fullAvatarUrl: 'https://avatars.example/111.jpg',
        );

        $user = $this->service->upsertFromMember($member);

        $this->assertSame(111, $user->user_id);
        $this->assertSame('Иван', $user->first_name);
        $this->assertSame('Петров', $user->last_name);
        $this->assertSame('ivan', $user->username);
        $this->assertSame('Иван Петров', $user->name);
        $this->assertSame('Разработчик', $user->description);
        $this->assertSame('https://avatars.example/111_s.jpg', $user->avatar_url);
        $this->assertSame('https://avatars.example/111.jpg', $user->full_avatar_url);
        $this->assertSame(1700000000000, $user->last_activity_time);
        $this->assertNotNull($user->profile_checked_at);
    }

    public function testUpsertFromMemberUpdatesExistingUser(): void
    {
        MaxUser::create([
            'user_id' => 111,
            'first_name' => 'Старый',
            'is_bot' => false,
        ]);

        $this->service->upsertFromMember(new ChatMember(
            userId: 111,
            firstName: 'Новый',
            lastName: null,
            username: null,
            isBot: false,
        ));

        $user = MaxUser::findOrFail(111);
        $this->assertSame('Новый', $user->first_name);
        $this->assertSame(1, MaxUser::query()->count());
    }

    public function testEnsureAvatarWithExistingAvatarSkipsApiCall(): void
    {
        MaxUser::create([
            'user_id' => 111,
            'first_name' => 'Иван',
            'is_bot' => false,
            'avatar_url' => 'https://avatars.example/111_s.jpg',
            'full_avatar_url' => 'https://avatars.example/111.jpg',
            'profile_checked_at' => now(),
        ]);

        $this->assertFalse($this->service->ensureAvatar(MaxUser::findOrFail(111)));
        $this->assertSame(0, $this->http->callCount);
    }

    public function testEnsureAvatarWithIntervalZeroAlwaysSkipsForFilledAvatar(): void
    {
        $this->app['config']->set(MaxServiceProvider::CONFIG_KEY . '.users.profile_check_interval', 0);
        MaxUser::create([
            'user_id' => 111,
            'first_name' => 'Иван',
            'is_bot' => false,
            'avatar_url' => 'https://avatars.example/111_s.jpg',
            'full_avatar_url' => 'https://avatars.example/111.jpg',
            'profile_checked_at' => now()->subSeconds(7200),
        ]);

        $this->assertFalse($this->service->ensureAvatar(MaxUser::findOrFail(111)));
        $this->assertSame(0, $this->http->callCount);
    }

    public function testEnsureAvatarSkipsWhileProfileIsFresh(): void
    {
        $this->app['config']->set(MaxServiceProvider::CONFIG_KEY . '.users.profile_check_interval', 3600);
        MaxUser::create([
            'user_id' => 111,
            'first_name' => 'Иван',
            'is_bot' => false,
            'avatar_url' => 'https://avatars.example/111_s.jpg',
            'full_avatar_url' => 'https://avatars.example/111.jpg',
            'profile_checked_at' => now(),
        ]);

        $this->assertFalse($this->service->ensureAvatar(MaxUser::findOrFail(111)));
        $this->assertSame(0, $this->http->callCount);
    }

    public function testEnsureAvatarRefreshesWhenIntervalExpired(): void
    {
        $this->app['config']->set(MaxServiceProvider::CONFIG_KEY . '.users.profile_check_interval', 3600);
        MaxUser::create([
            'user_id' => 111,
            'first_name' => 'Иван',
            'is_bot' => false,
            'avatar_url' => 'https://avatars.example/old_s.jpg',
            'full_avatar_url' => 'https://avatars.example/old.jpg',
            'profile_checked_at' => now()->subSeconds(7200),
        ]);
        MaxChat::create(['user_id' => 111, 'chat_id' => 222, 'status' => MaxChatStatus::Active, 'chat_type' => ChatType::Chat]);
        $this->http->queue($this->chatMemberResponse(111));

        $this->assertTrue($this->service->ensureAvatar(MaxUser::findOrFail(111)));
        $this->assertSame(1, $this->http->callCount);
        $this->assertSame(
            'https://avatars.example/111_s.jpg',
            MaxUser::findOrFail(111)->fresh()->avatar_url,
        );
    }

    public function testEnsureAvatarFetchesWhenNeverChecked(): void
    {
        $this->app['config']->set(MaxServiceProvider::CONFIG_KEY . '.users.profile_check_interval', 3600);
        MaxUser::create([
            'user_id' => 111,
            'first_name' => 'Иван',
            'is_bot' => false,
            'avatar_url' => 'https://avatars.example/111_s.jpg',
            'full_avatar_url' => 'https://avatars.example/111.jpg',
        ]);
        MaxChat::create(['user_id' => 111, 'chat_id' => 222, 'status' => MaxChatStatus::Active, 'chat_type' => ChatType::Chat]);
        $this->http->queue($this->chatMemberResponse(111));

        $this->assertTrue($this->service->ensureAvatar(MaxUser::findOrFail(111)));
        $this->assertSame(1, $this->http->callCount);
    }

    public function testEnsureAvatarWithoutAvatarRefreshesProfile(): void
    {
        MaxUser::create([
            'user_id' => 111,
            'first_name' => 'Иван',
            'is_bot' => false,
        ]);
        MaxChat::create(['user_id' => 111, 'chat_id' => 222, 'status' => MaxChatStatus::Active, 'chat_type' => ChatType::Chat]);
        $this->http->queue($this->chatMemberResponse(111));

        $this->assertTrue($this->service->ensureAvatar(MaxUser::findOrFail(111)));
        $this->assertStringContainsString('/chats/222/members', $this->http->lastRequest->getUri()->getPath());

        $user = MaxUser::findOrFail(111)->fresh();
        $this->assertSame('https://avatars.example/111_s.jpg', $user->avatar_url);
    }

    public function testEnsureAvatarWithExplicitChatIdBypassesRegistry(): void
    {
        $this->app['config']->set(MaxServiceProvider::CONFIG_KEY . '.users.profile_from_active_chats', false);
        MaxUser::create([
            'user_id' => 111,
            'first_name' => 'Иван',
            'is_bot' => false,
        ]);
        $this->http->queue($this->chatResponse(222, 'chat'));
        $this->http->queue($this->chatMemberResponse(111));

        $this->assertTrue($this->service->ensureAvatar(MaxUser::findOrFail(111), chatId: 222));
        $this->assertStringContainsString('/chats/222/members', $this->http->lastRequest->getUri()->getPath());
        $this->assertSame('https://avatars.example/111_s.jpg', MaxUser::findOrFail(111)->fresh()->avatar_url);
    }

    public function testRefreshWithActiveDialogChatUsesDialogWithUser(): void
    {
        MaxChat::create([
            'user_id' => 111,
            'chat_id' => 222,
            'status' => MaxChatStatus::Active,
            'chat_type' => ChatType::Dialog,
        ]);
        $this->http->queue($this->chatResponse(222, 'dialog'));

        $this->assertTrue($this->service->refresh(111));
        $this->assertSame(1, $this->http->callCount);
        $this->assertStringContainsString('/chats/222', $this->http->lastRequest->getUri()->getPath());
        $this->assertStringNotContainsString('/members', $this->http->lastRequest->getUri()->getPath());

        $user = MaxUser::query()->sole();
        $this->assertSame(111, $user->user_id);
        $this->assertSame('https://avatars.example/111_s.jpg', $user->avatar_url);
        $this->assertSame('https://avatars.example/111.jpg', $user->full_avatar_url);
    }

    public function testRefreshDialogChatResolvesAndPersistsChatType(): void
    {
        MaxChat::create(['user_id' => 111, 'chat_id' => 222, 'status' => MaxChatStatus::Active]);
        $this->http->queue($this->chatResponse(222, 'dialog'));

        $this->assertTrue($this->service->refresh(111));
        $this->assertSame(1, $this->http->callCount);
        $this->assertSame(ChatType::Dialog, MaxChat::query()->where('chat_id', 222)->sole()->chat_type);
    }

    public function testEnsureAvatarWithExplicitDialogChatIdUsesDialogWithUser(): void
    {
        $this->app['config']->set(MaxServiceProvider::CONFIG_KEY . '.users.profile_from_active_chats', false);
        MaxUser::create([
            'user_id' => 111,
            'first_name' => 'Иван',
            'is_bot' => false,
        ]);
        $this->http->queue($this->chatResponse(222, 'dialog'));

        $this->assertTrue($this->service->ensureAvatar(MaxUser::findOrFail(111), chatId: 222));
        $this->assertSame(1, $this->http->callCount);
        $this->assertStringContainsString('/chats/222', $this->http->lastRequest->getUri()->getPath());
        $this->assertStringNotContainsString('/members', $this->http->lastRequest->getUri()->getPath());
        $this->assertSame('https://avatars.example/111_s.jpg', MaxUser::findOrFail(111)->fresh()->avatar_url);
    }

    public function testFetchForDialogSkipsUpsertWhenPartnerNotRequested(): void
    {
        $this->app['config']->set(MaxServiceProvider::CONFIG_KEY . '.users.profile_from_active_chats', false);
        MaxUser::create([
            'user_id' => 111,
            'first_name' => 'Иван',
            'is_bot' => false,
        ]);
        $this->http->queue($this->chatResponse(222, 'dialog', [
            'dialog_with_user' => [
                'user_id' => 333,
                'first_name' => 'Чужой',
                'last_name' => null,
                'username' => null,
                'is_bot' => false,
            ],
        ]));

        $this->assertFalse($this->service->ensureAvatar(MaxUser::findOrFail(111), chatId: 222));
        $this->assertSame(1, $this->http->callCount);
        $this->assertSame(0, MaxUser::query()->where('user_id', 333)->count());
        $this->assertSame(111, MaxUser::query()->sole()->user_id);
    }
}
