<?php

declare(strict_types=1);

namespace GeekCo\LaravelMaxClient\Tests\Unit\Console;

use GeekCo\LaravelMaxClient\Enums\MaxChatStatus;
use GeekCo\LaravelMaxClient\MaxServiceProvider;
use GeekCo\LaravelMaxClient\Models\MaxChat;
use GeekCo\LaravelMaxClient\Tests\Support\MockHttpClient;
use GeekCo\LaravelMaxClient\Tests\TestCase;
use GeekCo\MaxPhpClient\Enum\ChatType;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Psr\Http\Client\ClientInterface;

final class MaxChatsRefreshCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(__DIR__ . '/../../../database/migrations');
    }

    public function testRefreshesAllActiveChatsByDefault(): void
    {
        $this->makeChat(111, 222);
        $this->makeChat(112, 222);
        $this->makeChat(111, 333, MaxChatStatus::Stopped);

        $http = $this->httpWith(
            $this->chatResponse(222, 'chat', ['title' => 'Группа']),
        );

        $this->assertSame(0, Artisan::call('max:chats:refresh'));

        // Один запрос на chat_id, несмотря на две записи реестра у 222.
        $this->assertSame(1, $http->callCount);
        $this->assertSame(2, MaxChat::query()->where('title', 'Группа')->count());
        $this->assertNull(MaxChat::query()->where('chat_id', 333)->sole()->title);
    }

    public function testRefreshesOnlyGivenChats(): void
    {
        $this->makeChat(111, 222);
        $this->makeChat(111, 333);

        $http = $this->httpWith(
            $this->chatResponse(333, 'channel', ['title' => 'Канал']),
        );

        $this->assertSame(0, Artisan::call('max:chats:refresh', ['--chat' => ['333']]));

        $this->assertSame(1, $http->callCount);
        $this->assertNull(MaxChat::query()->where('chat_id', 222)->sole()->title);
        $this->assertSame('Канал', MaxChat::query()->where('chat_id', 333)->sole()->title);
    }

    public function testAcceptsCommaSeparatedChatIds(): void
    {
        $this->makeChat(111, 222);
        $this->makeChat(111, 333);

        $http = $this->httpWith(
            $this->chatResponse(222, 'chat', ['title' => 'Первая']),
            $this->chatResponse(333, 'chat', ['title' => 'Вторая']),
        );

        $this->assertSame(0, Artisan::call('max:chats:refresh', ['--chat' => ['222,333']]));

        $this->assertSame(2, $http->callCount);
    }

    public function testRejectsNonNumericChatId(): void
    {
        $this->assertSame(2, Artisan::call('max:chats:refresh', ['--chat' => ['abc']]));
        $this->assertStringContainsString('Некорректный chat_id', Artisan::output());
    }

    public function testRejectsNonPositiveChatId(): void
    {
        $this->assertSame(2, Artisan::call('max:chats:refresh', ['--chat' => ['0']]));
        $this->assertStringContainsString('Некорректный chat_id', Artisan::output());
    }

    public function testReportsWhenThereIsNothingToSync(): void
    {
        $this->assertSame(0, Artisan::call('max:chats:refresh'));

        $output = Artisan::output();

        $this->assertStringContainsString('К проверке: 0', $output);
        $this->assertStringContainsString('Обновлений нет', $output);
    }

    /**
     * Периодическая перепроверка из расписания: чат, проверенный недавно,
     * повторно не опрашивается.
     */
    public function testRecentlyCheckedChatsAreSkippedWhenIntervalIsSet(): void
    {
        config()->set(MaxServiceProvider::CONFIG_KEY . '.chats.title_check_interval', 3600);

        $this->makeChat(111, 222);
        $this->makeChat(111, 333);

        MaxChat::query()->where('chat_id', 222)->update(['title_checked_at' => now()]);

        $http = $this->httpWith($this->chatResponse(333, 'chat', ['title' => 'Свежий']));

        $this->assertSame(0, Artisan::call('max:chats:refresh'));

        $output = Artisan::output();

        $this->assertSame(1, $http->callCount);
        $this->assertNull(MaxChat::query()->where('chat_id', 222)->sole()->title);
        $this->assertSame('Свежий', MaxChat::query()->where('chat_id', 333)->sole()->title);
        $this->assertStringContainsString('К проверке: 1, пропущено как проверенные недавно: 1', $output);
    }

    public function testStaleChatsAreRefreshedEvenWithIntervalSet(): void
    {
        config()->set(MaxServiceProvider::CONFIG_KEY . '.chats.title_check_interval', 3600);

        $this->makeChat(111, 222);
        MaxChat::query()->where('chat_id', 222)->update([
            'title_checked_at' => now()->subDays(2),
        ]);

        $http = $this->httpWith($this->chatResponse(222, 'chat', ['title' => 'Обновлённое']));

        $this->assertSame(0, Artisan::call('max:chats:refresh'));

        $this->assertSame(1, $http->callCount);
        $this->assertSame('Обновлённое', MaxChat::query()->where('chat_id', 222)->sole()->title);
    }

    public function testNeverCheckedChatIsNotSkippedByInterval(): void
    {
        config()->set(MaxServiceProvider::CONFIG_KEY . '.chats.title_check_interval', 3600);

        $this->makeChat(111, 222);

        $http = $this->httpWith($this->chatResponse(222, 'chat', ['title' => 'Группа']));

        $this->assertSame(0, Artisan::call('max:chats:refresh'));

        $this->assertSame(1, $http->callCount);
        $this->assertSame('Группа', MaxChat::query()->sole()->title);
    }

    public function testZeroIntervalAlwaysRefreshes(): void
    {
        config()->set(MaxServiceProvider::CONFIG_KEY . '.chats.title_check_interval', 0);

        $this->makeChat(111, 222);
        MaxChat::query()->where('chat_id', 222)->update(['title_checked_at' => now()]);

        $http = $this->httpWith($this->chatResponse(222, 'chat', ['title' => 'Группа']));

        $this->assertSame(0, Artisan::call('max:chats:refresh'));

        $output = Artisan::output();

        $this->assertSame(1, $http->callCount);
        $this->assertStringContainsString('К проверке: 1, пропущено как проверенные недавно: 0', $output);
    }

    public function testRefreshStampsTitleCheckedAt(): void
    {
        $this->makeChat(111, 222);

        $this->httpWith($this->chatResponse(222, 'chat', ['title' => 'Группа']));

        $this->assertSame(0, Artisan::call('max:chats:refresh'));

        $this->assertNotNull(MaxChat::query()->sole()->title_checked_at);
    }

    public function testDeduplicatesRepeatedChatIds(): void
    {
        $this->makeChat(111, 222);

        $http = $this->httpWith($this->chatResponse(222, 'chat', ['title' => 'Группа']));

        $this->assertSame(0, Artisan::call('max:chats:refresh', ['--chat' => ['222', '222']]));

        $this->assertSame(1, $http->callCount);
    }

    private function makeChat(int $userId, int $chatId, MaxChatStatus $status = MaxChatStatus::Active): MaxChat
    {
        return MaxChat::query()->create([
            'user_id' => $userId,
            'chat_id' => $chatId,
            'status' => $status,
            'chat_type' => ChatType::Chat,
        ]);
    }

    private function httpWith(Response ...$responses): MockHttpClient
    {
        $http = new MockHttpClient(array_values($responses));
        $this->app->instance(ClientInterface::class, $http);

        return $http;
    }

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $app['config']->set(MaxServiceProvider::CONFIG_KEY . '.chats.enabled', true);
    }
}
