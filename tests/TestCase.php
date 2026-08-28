<?php

declare(strict_types=1);

namespace GeekCo\LaravelMaxClient\Tests;

use GeekCo\LaravelMaxClient\MaxServiceProvider;
use GuzzleHttp\Psr7\Response;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [MaxServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set(MaxServiceProvider::CONFIG_KEY . '.api_token', 'test-token');
        $app['config']->set(MaxServiceProvider::CONFIG_KEY . '.retry.base_delay_seconds', 0.0);
    }

    protected function botInfoResponse(): Response
    {
        return new Response(200, [], json_encode([
            'user_id' => 1,
            'first_name' => 'Test',
            'last_name' => 'Bot',
            'username' => 'testbot',
            'is_bot' => true,
            'last_activity_time' => 1700000000000,
        ], JSON_THROW_ON_ERROR));
    }

    protected function messageResponse(): Response
    {
        return new Response(200, [], json_encode([
            'message' => [
                'recipient' => ['chat_id' => 42],
                'timestamp' => 1700000000000,
            ],
        ], JSON_THROW_ON_ERROR));
    }

    /**
     * @param int   $userId
     * @param array<string, mixed> $overrides
     */
    protected function chatMemberResponse(int $userId, array $overrides = []): Response
    {
        return new Response(200, [], json_encode([
            'members' => [array_replace([
                'user_id' => $userId,
                'first_name' => 'Test',
                'last_name' => 'User',
                'username' => 'testuser',
                'is_bot' => false,
                'last_activity_time' => 1700000000000,
                'name' => 'Test User',
                'description' => null,
                'avatar_url' => sprintf('https://avatars.example/%d_s.jpg', $userId),
                'full_avatar_url' => sprintf('https://avatars.example/%d.jpg', $userId),
                'last_access_time' => 1700000000000,
                'is_owner' => false,
                'is_admin' => false,
                'join_time' => 1700000000,
                'permissions' => null,
                'alias' => null,
            ], $overrides)],
        ], JSON_THROW_ON_ERROR));
    }

    protected function emptyChatMembersResponse(): Response
    {
        return new Response(200, [], json_encode(['members' => []], JSON_THROW_ON_ERROR));
    }

    /**
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    protected function updatePayload(array $overrides = []): array
    {
        return array_replace([
            'update_type' => 'message_created',
            'timestamp' => 1700000000000,
            'chat_id' => 42,
            'user' => [
                'user_id' => 1,
                'first_name' => 'Test',
                'last_name' => 'User',
                'username' => 'testuser',
                'is_bot' => false,
                'last_activity_time' => 1700000000000,
            ],
            'is_channel' => false,
        ], $overrides);
    }
}
