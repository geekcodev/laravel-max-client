<?php

declare(strict_types=1);

namespace GeekCo\LaravelMaxClient\Tests\Feature;

use GeekCo\LaravelMaxClient\Tests\TestCase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

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

        $this->assertFileDoesNotExist($published . '/0000_00_000001_create_max_users_table.php');
        $this->assertFileDoesNotExist($published . '/0000_00_000002_create_max_chats_table.php');

        $this->assertTrue(Schema::hasTable('max_users'));
        $this->assertTrue(Schema::hasTable('max_chats'));
        $this->assertTrue(Schema::hasTable('max_chat_users'));
    }

    /**
     * Миграции пакета лежат в полосе 0000_00.
     *
     * Полоса — это позиция в общем порядке миграций всех пакетов и приложения,
     * а не отдельный именованный диапазон: Laravel сортирует их по имени файла,
     * поэтому сдвиг полосы ломает чужие внешние ключи. Здесь полоса 0000_00,
     * потому что на max_users ссылается users из системных миграций приложения
     * (полоса 0000_01), а на max_chats — filament-max-chat (полоса 0000_02).
     * Раскладка полос описана в 0000_00_000001_create_max_users_table.
     */
    public function testMigrationsUseFoundationBand(): void
    {
        $names = array_map(basename(...), glob($this->migrationsDirectory() . '/*.php') ?: []);

        $this->assertNotEmpty($names);

        foreach ($names as $name) {
            $this->assertStringStartsWith(
                '0000_00_',
                $name,
                sprintf('%s: миграция вне полосы 0000_00, порядок применения может сломаться', $name),
            );
        }
    }

    public function testMaxUsersColumns(): void
    {
        $this->assertSame([
            'user_id', 'first_name', 'last_name', 'username', 'is_bot', 'last_activity_time',
            'name', 'description', 'avatar_url', 'full_avatar_url', 'phone', 'email',
            'phone_verified_at', 'profile_checked_at', 'created_at', 'updated_at',
        ], $this->columns('max_users'));

        $this->assertIndex('max_users', ['phone'], false);
        $this->assertIndex('max_users', ['profile_checked_at'], false);
    }

    /**
     * Одна строка на чат: user_id в max_chats больше нет, суррогатного id тоже —
     * первичным ключом является сам chat_id из MAX.
     */
    public function testMaxChatsColumns(): void
    {
        $this->assertSame([
            'chat_id', 'status', 'chat_type', 'title', 'description', 'link',
            'icon_url', 'chat_checked_at', 'last_activity_at', 'created_at', 'updated_at',
        ], $this->columns('max_chats'));

        $this->assertFalse(Schema::hasColumn('max_chats', 'user_id'));
        $this->assertFalse(Schema::hasColumn('max_chats', 'id'));

        $this->assertIndex('max_chats', ['chat_id'], true);
        $this->assertIndex('max_chats', ['status'], false);
        $this->assertIndex('max_chats', ['last_activity_at'], false);
        $this->assertFalse($this->hasIndex('max_chats', ['user_id', 'chat_id']));
    }

    public function testMaxChatUsersColumns(): void
    {
        $this->assertSame([
            'id', 'chat_id', 'user_id', 'status', 'last_activity_at', 'created_at', 'updated_at',
        ], $this->columns('max_chat_users'));

        $this->assertNotContains(
            Schema::getColumnType('max_chat_users', 'id'),
            ['integer', 'int', 'bigint', 'biginteger'],
            'id связи должен быть uuid, а не значением счётчика',
        );
        $this->assertIndex('max_chat_users', ['chat_id', 'user_id'], true);
        $this->assertIndex('max_chat_users', ['user_id'], false);
    }

    /**
     * Метаданные и отметка телефона объявлены в create-миграциях: отдельных
     * аддитивных миграций больше нет.
     */
    public function testAdditiveMigrationsAreGone(): void
    {
        foreach (['phone_verified_at', 'chat_checked_at', 'icon_url'] as $column) {
            $this->assertNotEmpty(
                $this->migrationFilesDeclaring($column),
                "{$column} не объявлен ни в одной миграции пакета",
            );
        }

        $this->assertSame(
            [],
            $this->migrationFilesDeclaring('add_phone_verified_at_to_max_users'),
            'Миграция добавления phone_verified_at должна быть удалена',
        );
    }

    /**
     * Перенос старых данных делает команда max:upgrade, а не миграция.
     *
     * Так решил владелец пакета: миграция не показывает план заранее и не
     * откатывается вручную, а пересборка max_chats в миграции означала бы ещё
     * один файл в базе у каждого потребителя. Создание таблицы связей при этом
     * остаётся миграцией — без неё чистая установка не поднимется одним migrate.
     */
    public function testSchemaRestructureIsNotAMigration(): void
    {
        $this->assertSame(
            [],
            $this->migrationFilesDeclaring('max_chats_rebuild'),
            'Пересборка реестра должна жить в команде max:upgrade, а не в миграции',
        );

        $this->assertNotEmpty(
            $this->migrationFilesDeclaring('max_chat_users'),
            'Таблица связей должна создаваться миграцией',
        );

        $this->assertFileExists(
            $this->projectPath('src/Console/MaxUpgradeCommand.php'),
            'Перенос данных без команды max:upgrade недоступен потребителю',
        );
    }

    /**
     * Идентификаторы MAX — знаковые int64, поэтому объявлять их unsigned нельзя.
     * Проверка идёт по исходникам: SQLite знаковость игнорирует, и вставка
     * отрицательного значения такой баг не поймает. В MaxSchemaUpgrade
     * единственное исключение — сужение user_id при откате, и это ожидаемо:
     * откат возвращает форму до v1.2.0, но не ценой потери отрицательных
     * значений.
     */
    public function testIdentifierColumnsAreDeclaredAsSignedBigInteger(): void
    {
        $this->assertSame(
            [],
            $this->migrationFilesDeclaring("unsignedBigInteger('chat_id')"),
            'chat_id объявлен как unsigned: отрицательные идентификаторы групп и каналов не сохранятся',
        );

        foreach (['000001_create_max_users_table.php', '000003_create_max_chat_users_table.php'] as $suffix) {
            $this->assertStringNotContainsString(
                "unsignedBigInteger('user_id')",
                (string) file_get_contents($this->migrationPath($suffix)),
                $suffix . ': user_id должен быть знаковым bigInteger',
            );
        }

        $this->assertStringContainsString(
            "bigInteger('user_id')",
            (string) file_get_contents($this->migrationPath('000001_create_max_users_table.php')),
        );

        $upgrade = (string) file_get_contents(
            $this->projectPath('src/Services/MaxSchemaUpgrade.php'),
        );

        $this->assertStringContainsString(
            "bigInteger('user_id')",
            $upgrade,
            'MaxSchemaUpgrade должен расширять max_users.user_id до знакового',
        );
        $this->assertSame(
            1,
            substr_count($upgrade, "unsignedBigInteger('user_id')"),
            'unsigned user_id допустим только в шаге сужения при откате',
        );

        $this->assertNotEmpty($this->migrationFilesDeclaring("bigInteger('chat_id')"));
    }

    public function testNegativeIdentifiersAreStoredInAllRegistries(): void
    {
        $chatId = -79032376695376;
        $userId = -6253184354684;

        DB::table('max_users')->insert(['user_id' => $userId, 'first_name' => 'Тест']);
        DB::table('max_chats')->insert(['chat_id' => $chatId, 'status' => 'active']);
        DB::table('max_chat_users')->insert([
            'id' => (string) Str::uuid(),
            'chat_id' => $chatId,
            'user_id' => $userId,
            'status' => 'active',
        ]);

        $this->assertSame($userId, (int) DB::table('max_users')->value('user_id'));
        $this->assertSame($chatId, (int) DB::table('max_chats')->value('chat_id'));
        $this->assertSame($chatId, (int) DB::table('max_chat_users')->value('chat_id'));
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
        $this->assertFalse(Schema::hasTable('max_chat_users'));
    }

    /**
     * @return list<string>
     */
    private function columns(string $table): array
    {
        return array_map(
            static fn (array $column): string => (string) $column['name'],
            Schema::getColumns($table),
        );
    }

    /**
     * @param list<string> $columns
     */
    private function assertIndex(string $table, array $columns, bool $unique): void
    {
        $this->assertTrue(
            $this->hasIndex($table, $columns, $unique),
            "Нет индекса {$table}(" . implode(', ', $columns) . "), unique=" . var_export($unique, true),
        );
    }

    /**
     * @param list<string> $columns
     */
    private function hasIndex(string $table, array $columns, bool $unique = true): bool
    {
        foreach (Schema::getIndexes($table) as $index) {
            if (!\is_array($index)) {
                continue;
            }

            if (($index['columns'] ?? null) === $columns && ($index['unique'] ?? false) === $unique) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<string>
     */
    private function migrationFilesDeclaring(string $needle): array
    {
        $owners = [];

        foreach (glob($this->migrationsDirectory() . '/*.php') ?: [] as $file) {
            if (str_contains((string) file_get_contents($file), $needle)) {
                $owners[] = basename($file);
            }
        }

        sort($owners);

        return $owners;
    }

    private function migrationsDirectory(): string
    {
        return $this->projectPath('database/migrations');
    }

    private function migrationPath(string $suffix): string
    {
        return $this->migrationsDirectory() . '/0000_00_' . $suffix;
    }

    private function projectPath(string $relative): string
    {
        return \dirname(__DIR__, 2) . '/' . $relative;
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
