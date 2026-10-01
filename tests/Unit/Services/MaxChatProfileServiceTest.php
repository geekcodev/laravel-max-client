<?php

declare(strict_types=1);

namespace GeekCo\LaravelMaxClient\Tests\Unit\Services;

use GeekCo\LaravelMaxClient\Enums\MaxChatStatus;
use GeekCo\LaravelMaxClient\Models\MaxChat;
use GeekCo\LaravelMaxClient\Models\MaxChatUser;
use GeekCo\LaravelMaxClient\Services\MaxChatProfileService;
use GeekCo\LaravelMaxClient\Tests\Support\MockHttpClient;
use GeekCo\LaravelMaxClient\Tests\TestCase;
use GeekCo\MaxPhpClient\Enum\ChatType;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\ResponseInterface;

final class MaxChatProfileServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(__DIR__ . '/../../database/migrations');
    }

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $app['config']->set('laravel-max-client.logging.enabled', true);
    }

    public function testSyncWritesAllMetadataFields(): void
    {
        MaxChat::create([
            'chat_id' => 222,
            'status' => MaxChatStatus::Active,
        ]);

        $http = $this->httpWith($this->chatResponse(222, 'channel', [
            'title' => 'Новости MAX',
            'description' => 'Описание канала',
            'link' => 'https://max.ru/news',
            'icon' => ['url' => 'https://max.ru/icon.png'],
        ]));

        $service = $this->app->make(MaxChatProfileService::class);

        $this->assertTrue($service->sync(222));

        $chat = MaxChat::query()->sole();

        $this->assertSame('Новости MAX', $chat->title);
        $this->assertSame('Описание канала', $chat->description);
        $this->assertSame('https://max.ru/news', $chat->link);
        $this->assertSame('https://max.ru/icon.png', $chat->icon_url);
        $this->assertSame(ChatType::Channel, $chat->chat_type);
        $this->assertNotNull($chat->chat_checked_at);
        $this->assertSame(1, $http->callCount);
    }

    public function testSyncIssuesSingleRequestPerChatRegardlessOfParticipants(): void
    {
        $this->givenChatRow();
        $this->linkUser(111);
        $this->linkUser(112);
        $this->linkUser(113);

        $http = $this->httpWith($this->chatResponse(222, 'chat', ['title' => 'Группа']));

        $this->assertTrue($this->app->make(MaxChatProfileService::class)->sync(222));

        $this->assertSame(1, $http->callCount);
        $this->assertSame(1, MaxChat::query()->where('title', 'Группа')->count());
    }

    public function testSyncKeepsKnownTitleWhenResponseHasEmptyOne(): void
    {
        MaxChat::create([
            'chat_id' => 222,
            'status' => MaxChatStatus::Active,
            'title' => 'Известное название',
        ]);

        $this->httpWith($this->chatResponse(222, 'channel', [
            'title' => '',
            'description' => '',
            'link' => '   ',
        ]));

        $this->assertTrue($this->app->make(MaxChatProfileService::class)->sync(222));

        $chat = MaxChat::query()->sole();

        $this->assertSame('Известное название', $chat->title);
        $this->assertNull($chat->description);
        $this->assertNull($chat->link);
        $this->assertNotNull($chat->chat_checked_at);
    }

    public function testSyncDoesNotOverwriteKnownChatType(): void
    {
        MaxChat::create([
            'chat_id' => 222,
            'status' => MaxChatStatus::Active,
            'chat_type' => ChatType::Dialog,
        ]);

        $this->httpWith($this->chatResponse(222, 'channel', ['title' => 'Канал']));

        $this->app->make(MaxChatProfileService::class)->sync(222);

        $this->assertSame(ChatType::Dialog, MaxChat::query()->sole()->chat_type);
    }

    public function testSyncLogsWarningAndReturnsFalseWhenGetChatFails(): void
    {
        MaxChat::create([
            'chat_id' => 222,
            'status' => MaxChatStatus::Active,
        ]);

        $this->httpWith(new Response(404, [], '{"code":"not.found","message":"chat not found"}'));

        $this->assertFalse($this->app->make(MaxChatProfileService::class)->sync(222));

        $chat = MaxChat::query()->sole();

        $this->assertNull($chat->title);
        $this->assertNull($chat->chat_checked_at);
    }

    public function testFetchCachesResponseWithinServiceInstance(): void
    {
        $http = $this->httpWith($this->chatResponse(222, 'chat'));

        $service = $this->app->make(MaxChatProfileService::class);

        $this->assertNotNull($service->fetch(222));
        $this->assertNotNull($service->fetch(222));
        $this->assertSame(1, $http->callCount);
    }

    public function testForgetChatCacheForcesNewRequest(): void
    {
        $http = $this->httpWith(
            $this->chatResponse(222, 'chat'),
            $this->chatResponse(222, 'chat'),
        );

        $service = $this->app->make(MaxChatProfileService::class);

        $service->fetch(222);
        $service->forgetChatCache();
        $service->fetch(222);

        $this->assertSame(2, $http->callCount);
    }

    public function testRefreshWithExplicitListOfChats(): void
    {
        MaxChat::create([
            'chat_id' => 222,
            'status' => MaxChatStatus::Active,
        ]);
        MaxChat::create([
            'chat_id' => 333,
            'status' => MaxChatStatus::Active,
        ]);

        $http = $this->httpWith(
            $this->chatResponse(222, 'chat', ['title' => 'Первая']),
            $this->chatResponse(333, 'chat', ['title' => 'Вторая']),
        );

        $this->assertTrue($this->app->make(MaxChatProfileService::class)->refresh([222, 333]));

        $this->assertSame(2, $http->callCount);
        $this->assertSame('Первая', MaxChat::query()->where('chat_id', 222)->sole()->title);
        $this->assertSame('Вторая', MaxChat::query()->where('chat_id', 333)->sole()->title);
    }

    public function testRefreshWithoutArgumentSyncsAllActiveChatsOnce(): void
    {
        $this->givenChatRow();
        $this->linkUser(111);
        $this->linkUser(112);

        MaxChat::create([
            'chat_id' => 333,
            'status' => MaxChatStatus::Stopped,
        ]);

        $http = $this->httpWith($this->chatResponse(222, 'chat', ['title' => 'Активная']));

        $this->assertTrue($this->app->make(MaxChatProfileService::class)->refresh());

        $this->assertSame(1, $http->callCount);
        $this->assertSame('Активная', MaxChat::query()->where('chat_id', 222)->sole()->title);
        $this->assertNull(MaxChat::query()->where('chat_id', 333)->sole()->title);
    }

    public function testRefreshWithoutActiveChatsReturnsFalse(): void
    {
        $this->assertFalse($this->app->make(MaxChatProfileService::class)->refresh());
    }

    public function testChatTypeForReturnsStoredValueWithoutApiCall(): void
    {
        MaxChat::create([
            'chat_id' => 222,
            'status' => MaxChatStatus::Active,
            'chat_type' => ChatType::Dialog,
        ]);

        $http = $this->httpWith();

        $this->assertSame(
            ChatType::Dialog,
            $this->app->make(MaxChatProfileService::class)->chatTypeFor(222),
        );
        $this->assertSame(0, $http->callCount);
    }

    public function testChatTypeForResolvesUnknownTypeViaApiAndStoresIt(): void
    {
        MaxChat::create([
            'chat_id' => 222,
            'status' => MaxChatStatus::Active,
        ]);

        $http = $this->httpWith($this->chatResponse(222, 'chat'));

        $this->assertSame(
            ChatType::Chat,
            $this->app->make(MaxChatProfileService::class)->chatTypeFor(222),
        );
        $this->assertSame(1, $http->callCount);
        $this->assertSame(ChatType::Chat, MaxChat::query()->sole()->chat_type);
    }

    public function testChatTypeForReturnsNullWhenChatUnknown(): void
    {
        $this->httpWith(new Response(500, [], '{"code":"server.error","message":"boom"}'));

        $this->assertNull($this->app->make(MaxChatProfileService::class)->chatTypeFor(999));
    }

    /**
     * Ключевое правило: известное название не требует запроса в API. Строка чата
     * в реестре одна, поэтому участники на частоту запросов не влияют: новый
     * участник не должен вызывать повторный getChat.
     */
    public function testEnsureMetadataDoesNotRequestWhenTitleKnown(): void
    {
        $this->givenChatRow(title: 'Новости MAX', extra: ['description' => 'Описание']);
        $this->linkUser(111);
        $this->linkUser(222);

        $http = $this->httpWith();

        $service = $this->app->make(MaxChatProfileService::class);

        $this->assertTrue($service->ensureMetadata(222));
        $this->assertTrue($service->ensureMetadata(222));

        $row = MaxChat::query()->sole();

        $this->assertSame('Новости MAX', $row->title);
        $this->assertSame('Описание', $row->description);
        $this->assertSame(0, $http->callCount);
    }

    public function testEnsureMetadataRequestsApiOnceWhenNothingKnown(): void
    {
        MaxChat::create([
            'chat_id' => 222,
            'status' => MaxChatStatus::Active,
        ]);

        $http = $this->httpWith($this->chatResponse(222, 'channel', ['title' => 'Новости MAX']));

        $this->assertTrue($this->app->make(MaxChatProfileService::class)->ensureMetadata(222));

        $this->assertSame(1, $http->callCount);
        $this->assertSame('Новости MAX', MaxChat::query()->sole()->title);
    }

    public function testEnsureMetadataDoesNothingWhenNothingToDo(): void
    {
        $this->givenChatRow(title: 'Новости MAX');

        $http = $this->httpWith();

        $this->assertTrue($this->app->make(MaxChatProfileService::class)->ensureMetadata(222));

        $this->assertSame(0, $http->callCount);
    }

    /**
     * Регрессия на исходный баг: один ответ getChat достаётся всему чату, а
     * заполненность метаданных не зависит от числа участников.
     */
    public function testMetadataIsStoredForWholeChatNotPerParticipant(): void
    {
        $this->givenChatRow();
        $this->linkUser(111);

        $http = $this->httpWith($this->chatResponse(222, 'channel', ['title' => 'Новости MAX']));

        $this->assertTrue($this->app->make(MaxChatProfileService::class)->ensureMetadata(222));

        $this->linkUser(222);

        $this->assertSame('Новости MAX', MaxChat::query()->sole()->title);
        $this->assertSame(1, $http->callCount, 'Запрос не нужен — данные уже в реестре');
        $this->assertSame([111, 222], MaxChatUser::query()->orderBy('user_id')->pluck('user_id')->all());
    }

    /**
     * @param array<string, mixed> $extra
     */
    /**
     * У групп и каналов в MAX идентификатор отрицательный, поэтому знак отбрасывать
     * нельзя: иначе название группы никогда не запросилось бы (название есть только
     * у групп и каналов), а чат попадал бы в очередь на перепроверку каждый запуск.
     */
    public function testNegativeChatIdIsTreatedAsGroupOrChannel(): void
    {
        $chatId = -79032376695376;

        MaxChat::create([
            'chat_id' => $chatId,
            'status' => MaxChatStatus::Active,
        ]);

        $http = $this->httpWith($this->chatResponse($chatId, 'chat', ['title' => 'Группа']));

        $service = $this->app->make(MaxChatProfileService::class);

        $this->assertSame([$chatId], $service->activeChatIds());
        $this->assertTrue($service->sync($chatId));
        $this->assertSame('Группа', MaxChat::query()->sole()->title);
        $this->assertSame(1, $http->callCount);
    }

    /**
     * Свежая отметка группы не должна сбрасываться из-за отрицательного знака: иначе
     * каждая проверка запрашивала бы getChat заново и расходовала лимит API.
     */
    public function testPendingChatIdsKeepsRecentlyCheckedNegativeChatId(): void
    {
        $chatId = -79032376695376;

        MaxChat::create([
            'chat_id' => $chatId,
            'status' => MaxChatStatus::Active,
            'chat_checked_at' => now(),
        ]);

        $this->app['config']->set('laravel-max-client.chats.chat_check_interval', 86400);

        $service = $this->app->make(MaxChatProfileService::class);

        $this->assertSame([], $service->pendingChatIds(), 'Свежий чат попал в очередь на перепроверку');
    }

    /**
     * Нулевой идентификатор в MAX не бывает — это мусор, его отбрасываем.
     */
    public function testZeroChatIdIsIgnored(): void
    {
        MaxChat::create([
            'chat_id' => -100,
            'status' => MaxChatStatus::Active,
        ]);

        $service = $this->app->make(MaxChatProfileService::class);

        $this->assertSame([-100], $service->activeChatIds());
        $this->assertFalse($service->ensureMetadata(0));
    }

    private function givenChatRow(?string $title = null, array $extra = []): MaxChat
    {
        return MaxChat::create($extra + [
            'chat_id' => 222,
            'status' => MaxChatStatus::Active,
            'title' => $title,
        ]);
    }

    private function linkUser(int $userId, int $chatId = 222): MaxChatUser
    {
        return MaxChatUser::create([
            'chat_id' => $chatId,
            'user_id' => $userId,
            'status' => MaxChatStatus::Active,
        ]);
    }

    private function httpWith(ResponseInterface ...$responses): MockHttpClient
    {
        $http = new MockHttpClient(array_values($responses));
        $this->app->instance(ClientInterface::class, $http);

        return $http;
    }
}
