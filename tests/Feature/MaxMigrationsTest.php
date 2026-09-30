<?php

declare(strict_types=1);

namespace GeekCo\LaravelMaxClient\Tests\Feature;

use GeekCo\LaravelMaxClient\Tests\TestCase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;

/**
 * Миграции пакета должны применяться без vendor:publish: они подгружаются
 * провайдером напрямую из каталога database/migrations.
 */
final class MaxMigrationsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->artisan('migrate')->run();
    }

    public function testMigrationsRunWithoutPublishingThem(): void
    {
        $published = $this->app->databasePath('migrations');

        $this->assertFileDoesNotExist($published . '/0001_01_01_000001_create_max_users_table.php');
        $this->assertFileDoesNotExist($published . '/0001_01_01_000002_create_max_chats_table.php');

        $this->assertTrue(Schema::hasTable('max_users'));
        $this->assertTrue(Schema::hasTable('max_chats'));
    }

    public function testMaxUsersHasPhoneVerifiedAt(): void
    {
        $this->assertTrue(Schema::hasColumn('max_users', 'phone_verified_at'));
        $this->assertTrue(Schema::hasColumn('max_users', 'phone'));
    }

    public function testMaxChatsHasMetadataColumns(): void
    {
        foreach (['title', 'description', 'link', 'icon_url', 'title_checked_at'] as $column) {
            $this->assertTrue(Schema::hasColumn('max_chats', $column), "Нет колонки {$column}");
        }
    }

    public function testMaxChatsHasIndexOnChatId(): void
    {
        $indexed = false;

        foreach (Schema::getIndexes('max_chats') as $index) {
            if (($index['columns'] ?? null) === ['chat_id']) {
                $indexed = true;
            }
        }

        $this->assertTrue($indexed, 'Нет индекса по chat_id');
    }

    public function testMigrateStatusListsPackageMigrations(): void
    {
        $this->artisan('migrate:status')->assertSuccessful();

        $output = $this->artisan('migrate:status')->run();

        $this->assertSame(0, $output);
    }

    public function testRollbackRemovesPackageTables(): void
    {
        $this->artisan('migrate:rollback')->assertSuccessful();

        $this->assertFalse(Schema::hasTable('max_users'));
        $this->assertFalse(Schema::hasTable('max_chats'));
    }

    protected function tearDown(): void
    {
        Schema::dropAllTables();

        parent::tearDown();
    }

    public function testDatabaseDirectoryHasNoLeftoverPublishedCopies(): void
    {
        $path = $this->app->databasePath('migrations');

        if (!File::isDirectory($path)) {
            $this->assertTrue(true);

            return;
        }

        $this->assertSame([], glob($path . '/*_create_max_*_table.php') ?: []);
    }
}
