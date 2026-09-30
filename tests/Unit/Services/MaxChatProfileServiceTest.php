<?php

declare(strict_types=1);

namespace GeekCo\LaravelMaxClient\Tests\Unit\Services;

use GeekCo\LaravelMaxClient\Enums\MaxChatStatus;
use GeekCo\LaravelMaxClient\Models\MaxChat;
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
            'user_id' => 111,
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
        $this->assertNotNull($chat->title_checked_at);
        $this->assertSame(1, $http->callCount);
    }

    public function testSyncIssuesSingleRequestForEveryRegistryRowOfTheChat(): void
    {
        foreach ([111, 112, 113] as $userId) {
            MaxChat::create([
                'user_id' => $userId,
                'chat_id' => 222,
                'status' => MaxChatStatus::Active,
            ]);
        }

        $http = $this->httpWith($this->chatResponse(222, 'chat', ['title' => 'Группа']));

        $this->assertTrue($this->app->make(MaxChatProfileService::class)->sync(222));

        $this->assertSame(1, $http->callCount);
        $this->assertSame(3, MaxChat::query()->where('title', 'Группа')->count());
    }

    public function testSyncKeepsKnownTitleWhenResponseHasEmptyOne(): void
    {
        MaxChat::create([
            'user_id' => 111,
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
        $this->assertNotNull($chat->title_checked_at);
    }

    public function testSyncDoesNotOverwriteKnownChatType(): void
    {
        MaxChat::create([
            'user_id' => 111,
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
            'user_id' => 111,
            'chat_id' => 222,
            'status' => MaxChatStatus::Active,
        ]);

        $this->httpWith(new Response(404, [], '{"code":"not.found","message":"chat not found"}'));

        $this->assertFalse($this->app->make(MaxChatProfileService::class)->sync(222));

        $chat = MaxChat::query()->sole();

        $this->assertNull($chat->title);
        $this->assertNull($chat->title_checked_at);
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
            'user_id' => 111,
            'chat_id' => 222,
            'status' => MaxChatStatus::Active,
        ]);
        MaxChat::create([
            'user_id' => 111,
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
        foreach ([111, 112] as $userId) {
            MaxChat::create([
                'user_id' => $userId,
                'chat_id' => 222,
                'status' => MaxChatStatus::Active,
            ]);
        }

        MaxChat::create([
            'user_id' => 111,
            'chat_id' => 333,
            'status' => MaxChatStatus::Stopped,
        ]);

        $http = $this->httpWith($this->chatResponse(222, 'chat', ['title' => 'Активная']));

        $this->assertTrue($this->app->make(MaxChatProfileService::class)->refresh());

        $this->assertSame(1, $http->callCount);
        $this->assertSame('Активная', MaxChat::query()->where('chat_id', 222)->first()?->title);
        $this->assertNull(MaxChat::query()->where('chat_id', 333)->sole()->title);
    }

    public function testRefreshWithoutActiveChatsReturnsFalse(): void
    {
        $this->assertFalse($this->app->make(MaxChatProfileService::class)->refresh());
    }

    public function testChatTypeForReturnsStoredValueWithoutApiCall(): void
    {
        MaxChat::create([
            'user_id' => 111,
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
            'user_id' => 111,
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
     * Ключевое правило: запись, появившаяся после синхронизации чата, получает
     * известные метаданные без запроса в API. Реестр хранит по строке на
     * каждого участника, поэтому без этого все участники после первого остались
     * бы с пустыми метаданными.
     */
    public function testEnsureMetadataGivesKnownValuesToNewRowWithoutRequest(): void
    {
        $this->givenChatRow(userId: 111, chatId: 222, title: 'Новости MAX', extra: ['description' => 'Описание']);

        MaxChat::create([
            'user_id' => 222,
            'chat_id' => 222,
            'status' => MaxChatStatus::Active,
        ]);

        $http = $this->httpWith();

        $this->assertTrue($this->app->make(MaxChatProfileService::class)->ensureMetadata(222));

        $row = MaxChat::query()->where('user_id', 222)->sole();

        $this->assertSame('Новости MAX', $row->title);
        $this->assertSame('Описание', $row->description);
        $this->assertSame(0, $http->callCount);
    }

    public function testEnsureMetadataRequestsApiOnceWhenNothingKnown(): void
    {
        MaxChat::create([
            'user_id' => 111,
            'chat_id' => 222,
            'status' => MaxChatStatus::Active,
        ]);

        $http = $this->httpWith($this->chatResponse(222, 'channel', ['title' => 'Новости MAX']));

        $this->assertTrue($this->app->make(MaxChatProfileService::class)->ensureMetadata(222));

        $this->assertSame(1, $http->callCount);
        $this->assertSame('Новости MAX', MaxChat::query()->sole()->title);
    }

    public function testEnsureMetadataDoesNothingWhenAllRowsAlreadyFilled(): void
    {
        $this->givenChatRow(userId: 111, chatId: 222, title: 'Новости MAX');
        $this->givenChatRow(userId: 222, chatId: 222, title: 'Новости MAX');

        $http = $this->httpWith();

        $this->assertTrue($this->app->make(MaxChatProfileService::class)->ensureMetadata(222));

        $this->assertSame(0, $http->callCount);
    }

    /**
     * Наследование не должно затирать то, что уже известно новой записи
     * (например, пришло chat_title_changed раньше, чем появился участник).
     */
    public function testInheritKnownMetadataKeepsExistingValue(): void
    {
        $this->givenChatRow(userId: 111, chatId: 222, title: 'Старое название');
        $this->givenChatRow(userId: 222, chatId: 222, title: 'Актуальное название');

        $this->assertFalse($this->app->make(MaxChatProfileService::class)->inheritKnownMetadata(222));

        $this->assertSame('Актуальное название', MaxChat::query()->where('user_id', 222)->sole()->title);
    }

    /**
     * Регрессия на исходный баг: проверка «есть ли заполненная строка» и
     * заполнение по всем строкам меряли разные вещи, поэтому запись второго
     * участника оставалась пустой навсегда.
     */
    public function testNewRowIsFilledEvenWhenSiblingAlreadyHasTitle(): void
    {
        $this->givenChatRow(userId: 111, chatId: 222, title: 'Новости MAX');

        $http = $this->httpWith();

        MaxChat::create([
            'user_id' => 222,
            'chat_id' => 222,
            'status' => MaxChatStatus::Active,
        ]);

        $this->app->make(MaxChatProfileService::class)->ensureMetadata(222);

        $this->assertSame('Новости MAX', MaxChat::query()->where('user_id', 222)->sole()->title);
        $this->assertSame(0, $http->callCount, 'Запрос не нужен — данные уже в реестре');
    }

    /**
     * @param array<string, mixed> $extra
     */
    private function givenChatRow(int $userId, int $chatId, ?string $title = null, array $extra = []): MaxChat
    {
        return MaxChat::create($extra + [
            'user_id' => $userId,
            'chat_id' => $chatId,
            'status' => MaxChatStatus::Active,
            'title' => $title,
        ]);
    }

    private function httpWith(ResponseInterface ...$responses): MockHttpClient
    {
        $http = new MockHttpClient(array_values($responses));
        $this->app->instance(ClientInterface::class, $http);

        return $http;
    }
}
