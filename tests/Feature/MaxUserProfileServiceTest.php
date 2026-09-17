<?php

declare(strict_types=1);

namespace GeekCo\LaravelMaxClient\Tests\Feature;

use GeekCo\LaravelMaxClient\Enums\MaxChatStatus;
use GeekCo\LaravelMaxClient\Models\MaxChat;
use GeekCo\LaravelMaxClient\Models\MaxUser;
use GeekCo\LaravelMaxClient\Services\MaxUserProfileService;
use GeekCo\LaravelMaxClient\Tests\Support\MockHttpClient;
use GeekCo\LaravelMaxClient\Tests\TestCase;
use GeekCo\MaxPhpClient\Enum\ChatType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Psr\Http\Client\ClientInterface;

final class MaxUserProfileServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(__DIR__ . '/../../database/migrations');
    }

    public function testContainerResolvedServiceRefreshesProfileEndToEnd(): void
    {
        MaxChat::create([
            'user_id' => 111,
            'chat_id' => 222,
            'status' => MaxChatStatus::Active,
            'chat_type' => ChatType::Chat,
        ]);

        $http = new MockHttpClient([$this->chatMemberResponse(111)]);
        $this->app->instance(ClientInterface::class, $http);

        $service = $this->app->make(MaxUserProfileService::class);

        $this->assertTrue($service->refresh(111));
        $this->assertSame(1, $http->callCount);
        $this->assertDatabaseHas('max_users', ['user_id' => 111, 'avatar_url' => 'https://avatars.example/111_s.jpg']);
        $this->assertSame(1, MaxChat::query()->count());
        $this->assertSame(1, MaxUser::query()->count());
    }
}
