<?php

declare(strict_types=1);

namespace GeekCo\LaravelMaxClient\Tests\Unit\Console;

use GeekCo\LaravelMaxClient\Tests\TestCase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;

/**
 * Сценарий обновления существующей установки: миграции create_max_users и
 * create_max_chats уже выполнялись, поэтому дополнения в них повторно не
 * применяются, и схему доводит команда max:upgrade.
 */
final class MaxUpgradeCommandTest extends TestCase
{
    public function testCommandAddsMissingColumnsAndIndexToLegacySchema(): void
    {
        $this->createLegacySchema();

        $this->assertFalse(Schema::hasColumn('max_chats', 'title'));
        $this->assertFalse(Schema::hasColumn('max_users', 'phone_verified_at'));

        $this->artisan('max:upgrade')->assertExitCode(0);

        foreach (['title', 'description', 'link', 'icon_url', 'title_checked_at'] as $column) {
            $this->assertTrue(Schema::hasColumn('max_chats', $column), "Нет колонки {$column}");
        }

        $this->assertTrue(Schema::hasColumn('max_users', 'phone_verified_at'));
        $this->assertTrue($this->hasChatIdIndex());
    }

    public function testSecondRunChangesNothing(): void
    {
        $this->createLegacySchema();

        $this->assertSame(0, Artisan::call('max:upgrade'));
        $this->assertSame(1, $this->chatIdIndexCount());
        $this->assertTrue(Schema::hasColumn('max_chats', 'title'));

        $columnsBefore = $this->columnList('max_chats');
        $userColumnsBefore = $this->columnList('max_users');

        // Повторный запуск не должен ни добавить дубль индекса, ни изменить состав колонок.
        $this->assertSame(0, Artisan::call('max:upgrade'));

        $this->assertSame(1, $this->chatIdIndexCount());
        $this->assertSame($columnsBefore, $this->columnList('max_chats'));
        $this->assertSame($userColumnsBefore, $this->columnList('max_users'));
    }

    public function testCommandSucceedsOnFreshSchemaFromMigrations(): void
    {
        $this->artisan('migrate')->run();

        $this->assertTrue(Schema::hasColumn('max_chats', 'title'));

        $this->assertSame(0, Artisan::call('max:upgrade'));
        $this->assertStringContainsString('Схема уже актуальна', Artisan::output());
    }

    public function testDryRunReportsChangesWithoutApplyingThem(): void
    {
        $this->createLegacySchema();

        $this->assertSame(0, Artisan::call('max:upgrade', ['--dry-run' => true]));

        $output = Artisan::output();

        $this->assertStringContainsString('Режим --dry-run', $output);
        $this->assertStringContainsString('Будет добавлено:', $output);
        $this->assertStringNotContainsString('Добавлено:', $output);

        $this->assertFalse(Schema::hasColumn('max_chats', 'title'));
        $this->assertFalse($this->hasChatIdIndex());
    }

    public function testCommandIsSafeWhenPackageTablesAreAbsent(): void
    {
        $this->assertSame(0, Artisan::call('max:upgrade'));
        $this->assertStringContainsString('пропущено', Artisan::output());
    }

    /**
     * Схема в состоянии до v1.2.0: без полей метаданных и индекса по chat_id.
     */
    private function createLegacySchema(): void
    {
        Schema::create('max_users', function (\Illuminate\Database\Schema\Blueprint $table): void {
            $table->unsignedBigInteger('user_id')->primary();
            $table->string('first_name', 128);
            $table->string('phone', 32)->nullable();
            $table->timestamps();
        });

        Schema::create('max_chats', function (\Illuminate\Database\Schema\Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('chat_id');
            $table->string('status', 16)->default('active');
            $table->string('chat_type', 16)->nullable();
            $table->timestamp('last_activity_at')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'chat_id']);
        });
    }

    private function hasChatIdIndex(): bool
    {
        return $this->chatIdIndexCount() > 0;
    }

    /**
     * @return list<string>
     */
    private function columnList(string $table): array
    {
        $names = [];

        foreach (Schema::getColumns($table) as $column) {
            $names[] = (string) $column['name'];
        }

        sort($names);

        return $names;
    }

    private function chatIdIndexCount(): int
    {
        $count = 0;

        foreach (Schema::getIndexes('max_chats') as $index) {
            if (($index['columns'] ?? null) === ['chat_id']) {
                ++$count;
            }
        }

        return $count;
    }

    protected function tearDown(): void
    {
        Schema::dropAllTables();

        parent::tearDown();
    }
}
