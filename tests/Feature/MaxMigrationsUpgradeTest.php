<?php

declare(strict_types=1);

namespace GeekCo\LaravelMaxClient\Tests\Feature;

use GeekCo\LaravelMaxClient\Tests\TestCase;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Сценарий обновления уже установленной версии пакета.
 *
 * У такой установки файлы create_max_users_table и create_max_chats_table уже
 * помечены выполненными, поэтому новые поля обязаны приезжать отдельными
 * аддитивными миграциями: правка файла создания таблицы на месте привела бы к
 * тихому расхождению — `php artisan migrate` отработал бы вхолостую, и без
 * ручного вмешательства (max:upgrade) схема осталась бы прежней.
 *
 * Ключевое утверждение теста: обычного `php artisan migrate` достаточно.
 */
final class MaxMigrationsUpgradeTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->artisan('migrate:install')->run();
    }

    public function testPlainMigrateUpgradesExistingInstall(): void
    {
        $this->simulateExistingInstall();

        $this->assertFalse(Schema::hasColumn('max_chats', 'title'));
        $this->assertFalse(Schema::hasColumn('max_users', 'phone_verified_at'));

        $this->artisan('migrate')->assertExitCode(0);

        foreach (['title', 'description', 'link', 'icon_url', 'title_checked_at'] as $column) {
            $this->assertTrue(Schema::hasColumn('max_chats', $column), "Нет колонки {$column}");
        }

        $this->assertTrue(Schema::hasColumn('max_users', 'phone_verified_at'));
        $this->assertTrue($this->hasChatIdIndex());
    }

    public function testExistingDataSurvivesUpgrade(): void
    {
        $this->simulateExistingInstall();

        DB::table('max_users')->insert(['user_id' => 111, 'first_name' => 'Иван', 'is_bot' => false]);
        DB::table('max_chats')->insert([
            'user_id' => 111,
            'chat_id' => 222,
            'status' => 'active',
            'chat_type' => 'chat',
        ]);

        $this->artisan('migrate')->assertExitCode(0);

        $this->assertSame(1, DB::table('max_users')->count());
        $this->assertSame('Иван', DB::table('max_users')->value('first_name'));
        $this->assertSame(1, DB::table('max_chats')->count());
        $this->assertSame(222, DB::table('max_chats')->value('chat_id'));

        // Новые поля пустые, а не потерянные.
        $this->assertNull(DB::table('max_chats')->value('title'));
        $this->assertNull(DB::table('max_users')->value('phone_verified_at'));
    }

    /**
     * Файлы создания таблиц повторно не выполняются: иначе миграция упала бы
     * на уже существующих таблицах.
     */
    public function testCreateMigrationsAreNotReplayed(): void
    {
        $this->simulateExistingInstall();

        $this->artisan('migrate')->assertExitCode(0);

        $ran = DB::table('migrations')->pluck('migration')->all();

        $this->assertSame(1, substr_count(implode("\n", $ran), 'create_max_users_table'));
        $this->assertSame(1, substr_count(implode("\n", $ran), 'create_max_chats_table'));
    }

    public function testNewColumnsAreNullableSoBackfillIsNotRequired(): void
    {
        $this->simulateExistingInstall();

        $this->artisan('migrate')->assertExitCode(0);

        foreach (Schema::getColumns('max_chats') as $column) {
            if (\in_array($column['name'], ['title', 'description', 'link', 'icon_url', 'title_checked_at'], true)) {
                $this->assertTrue($column['nullable'], "{$column['name']} должен быть nullable");
            }
        }
    }

    public function testRollbackRemovesOnlyNewColumns(): void
    {
        $this->simulateExistingInstall();

        $this->artisan('migrate')->assertExitCode(0);

        $this->artisan('migrate:rollback', ['--step' => 3])->assertExitCode(0);

        $this->assertTrue(Schema::hasTable('max_chats'));
        $this->assertTrue(Schema::hasTable('max_users'));

        foreach (['title', 'description', 'link', 'icon_url', 'title_checked_at'] as $column) {
            $this->assertFalse(Schema::hasColumn('max_chats', $column), "Колонка {$column} осталась");
        }

        $this->assertFalse(Schema::hasColumn('max_users', 'phone_verified_at'));
    }

    /**
     * Файлы создания таблиц заморожены: правка уже опубликованной миграции
     * ломает обновление, поэтому новые поля объявляются только в отдельных
     * аддитивных миграциях. Поведенческий тест выше этого не поймает — пока
     * аддитивные миграции существуют, колонки появятся в любом случае.
     */
    public function testCreateMigrationsStayFrozen(): void
    {
        $frozen = [
            '0001_01_01_000001_create_max_users_table.php' => ['phone_verified_at'],
            '0001_01_01_000002_create_max_chats_table.php' => ['title_checked_at', 'icon_url', "'link'"],
        ];

        foreach ($frozen as $file => $forbidden) {
            $source = (string) file_get_contents($this->packageMigrationPath($file));

            foreach ($forbidden as $column) {
                $this->assertStringNotContainsString(
                    $column,
                    $source,
                    "{$file} изменён на месте — новая колонка должна быть в отдельной миграции",
                );
            }
        }
    }

    public function testNewColumnsAreDeclaredInAdditiveMigrationsOnly(): void
    {
        $declarations = [
            'phone_verified_at' => '0001_01_01_000003_add_phone_verified_at_to_max_users_table.php',
            'title_checked_at' => '0001_01_01_000005_add_chat_metadata_to_max_chats_table.php',
        ];

        foreach ($declarations as $column => $expectedFile) {
            $this->assertSame(
                [$expectedFile],
                $this->filesDeclaring($column),
                "{$column} объявлен не в своей аддитивной миграции",
            );
        }
    }

    /**
     * Индекс по chat_id добавляется отдельной миграцией и раньше миграций с
     * колонками: на большой таблице индекс строится, пока она ещё не получила
     * новые колонки, и не смешивается с добавлением колонок.
     */
    public function testChatIdIndexHasItsOwnMigration(): void
    {
        $indexFile = '0001_01_01_000004_add_chat_id_index_to_max_chats_table.php';
        $metadataFile = '0001_01_01_000005_add_chat_metadata_to_max_chats_table.php';

        $this->assertSame(
            [$indexFile],
            $this->filesDeclaring("index('chat_id')"),
            'Индекс по chat_id должен объявляться только в своей миграции',
        );

        $this->assertLessThan(
            $metadataFile,
            $indexFile,
            'Миграция с индексом должна идти раньше миграции с колонками',
        );
    }

    /**
     * @return list<string>
     */
    private function filesDeclaring(string $needle): array
    {
        $owners = [];

        foreach (glob($this->packageMigrationPath('*_table.php')) ?: [] as $file) {
            if (str_contains((string) file_get_contents($file), $needle)) {
                $owners[] = basename($file);
            }
        }

        sort($owners);

        return $owners;
    }

    /**
     * Схема в состоянии прежних версий пакета — ровно то, что создавали
     * create_max_users_table и create_max_chats_table.
     */
    private function simulateExistingInstall(): void
    {
        Schema::create('max_users', function (Blueprint $table): void {
            $table->unsignedBigInteger('user_id')->primary();
            $table->string('first_name', 128);
            $table->string('last_name', 128)->nullable();
            $table->string('username', 128)->nullable();
            $table->boolean('is_bot')->default(false);
            $table->unsignedBigInteger('last_activity_time')->nullable();
            $table->string('name', 256)->nullable();
            $table->text('description')->nullable();
            $table->string('avatar_url', 512)->nullable();
            $table->string('full_avatar_url', 512)->nullable();
            $table->string('phone', 32)->nullable();
            $table->string('email', 256)->nullable();
            $table->timestamp('profile_checked_at')->nullable();
            $table->timestamps();
        });

        Schema::create('max_chats', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('chat_id');
            $table->string('status', 16)->default('active');
            $table->string('chat_type', 16)->nullable();
            $table->timestamp('last_activity_at')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'chat_id']);
        });

        foreach (['0001_01_01_000001_create_max_users_table', '0001_01_01_000002_create_max_chats_table'] as $name) {
            DB::table('migrations')->insert(['migration' => $name, 'batch' => 1]);
        }
    }

    private function hasChatIdIndex(): bool
    {
        foreach (Schema::getIndexes('max_chats') as $index) {
            if (($index['columns'] ?? null) === ['chat_id']) {
                return true;
            }
        }

        return false;
    }

    private function packageMigrationPath(string $file): string
    {
        return \dirname(__DIR__, 2) . '/database/migrations/' . $file;
    }

    protected function tearDown(): void
    {
        Schema::dropAllTables();

        parent::tearDown();
    }
}
