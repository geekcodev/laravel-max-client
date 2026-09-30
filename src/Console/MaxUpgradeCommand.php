<?php

declare(strict_types=1);

namespace GeekCo\LaravelMaxClient\Console;

use Illuminate\Console\Command;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Идемпотентное добавление колонок и индекса, появившихся в обновлении пакета.
 *
 * Зачем это нужно. Имена миграций намеренно не менялись (0001_01_01_000001 и
 * 0001_01_01_000002), поэтому дополнение create_max_users/create_max_chats
 * доходит только до чистой установки: у потребителя, который уже выполнял эти
 * миграции, имена записаны в таблице migrations, и изменённый файл повторно не
 * запустится. Без этой команды первый же message_created упал бы с
 * «Unknown column».
 *
 * Команда безопасна в обе стороны: на чистой схеме ей нечего делать (всё уже
 * создано миграцией), на устаревшей она дописывает недостающее. Повторный
 * запуск ничего не меняет и завершается успешно.
 */
final class MaxUpgradeCommand extends Command
{
    protected $signature = 'max:upgrade
                            {--dry-run : Показать, что будет изменено, ничего не применяя}';

    protected $description = 'Добавить отсутствующие колонки и индекс реестров MAX (max_users, max_chats)';

    /** @var list<string> */
    private array $added = [];

    /** @var list<string> */
    private array $failed = [];

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');

        $this->components->info('Проверка схемы реестров MAX');

        $this->ensureChatsTable($dryRun);
        $this->ensureUsersTable($dryRun);

        $this->newLine();

        if ($this->added !== []) {
            $this->components->info(
                ($dryRun ? 'Будет добавлено: ' : 'Добавлено: ') . implode(', ', $this->added),
            );
        }

        if ($dryRun) {
            $this->components->info('Режим --dry-run: изменения не применялись.');

            return $this->failed === [] ? self::SUCCESS : self::FAILURE;
        }

        if ($this->failed !== []) {
            $this->components->error('Не удалось применить: ' . implode(', ', $this->failed));

            return self::FAILURE;
        }

        if ($this->added === []) {
            $this->components->info('Схема уже актуальна, изменений не требуется.');
        }

        return self::SUCCESS;
    }

    private function ensureChatsTable(bool $dryRun): void
    {
        if (!$this->tableExists('max_chats')) {
            return;
        }

        $this->addColumn(
            'max_chats',
            'title',
            static fn (Blueprint $table) => $table->string('title', 256)->nullable()
                ->comment('Название группы или канала'),
            $dryRun,
        );

        $this->addColumn(
            'max_chats',
            'description',
            static fn (Blueprint $table) => $table->text('description')->nullable()
                ->comment('Описание чата'),
            $dryRun,
        );

        $this->addColumn(
            'max_chats',
            'link',
            static fn (Blueprint $table) => $table->string('link', 512)->nullable()
                ->comment('Публичная ссылка на канал'),
            $dryRun,
        );

        $this->addColumn(
            'max_chats',
            'icon_url',
            static fn (Blueprint $table) => $table->string('icon_url', 512)->nullable()
                ->comment('URL иконки чата'),
            $dryRun,
        );

        $this->addColumn(
            'max_chats',
            'title_checked_at',
            static fn (Blueprint $table) => $table->timestamp('title_checked_at')->nullable()
                ->comment('Время последней синхронизации метаданных чата с MAX'),
            $dryRun,
        );

        $this->addIndex('max_chats', 'chat_id', $dryRun);
    }

    private function ensureUsersTable(bool $dryRun): void
    {
        if (!$this->tableExists('max_users')) {
            return;
        }

        $this->addColumn(
            'max_users',
            'phone_verified_at',
            static fn (Blueprint $table) => $table->timestamp('phone_verified_at')->nullable()
                ->comment('Время получения подтверждённого телефона из контакта'),
            $dryRun,
        );
    }

    /**
     * @param callable(Blueprint): mixed $definition описание колонки, выполняется
     *                                            на Blueprint внутри Schema::table
     */
    private function addColumn(string $table, string $column, callable $definition, bool $dryRun): void
    {
        $target = "{$table}.{$column}";

        if (Schema::hasColumn($table, $column)) {
            $this->skip($target);

            return;
        }

        if ($dryRun) {
            $this->markAdded($target);

            return;
        }

        try {
            Schema::table($table, static function (Blueprint $blueprint) use ($definition): void {
                $definition($blueprint);
            });
        } catch (\Throwable $e) {
            $this->markFailed($target, $e->getMessage());

            return;
        }

        $this->markAdded($target);
    }

    private function addIndex(string $table, string $column, bool $dryRun): void
    {
        $target = "{$table}.{$column} (index)";

        if ($this->hasIndexOn($table, $column)) {
            $this->skip($target);

            return;
        }

        if ($dryRun) {
            $this->markAdded($target);

            return;
        }

        try {
            Schema::table($table, static function (Blueprint $blueprint) use ($column): void {
                $blueprint->index($column);
            });
        } catch (\Throwable $e) {
            $this->markFailed($target, $e->getMessage());

            return;
        }

        $this->markAdded($target);
    }

    /**
     * Публичного хелпера с проверкой индекса по колонке в Laravel нет, поэтому
     * смотрим каталог индексов таблицы.
     */
    private function hasIndexOn(string $table, string $column): bool
    {
        foreach (Schema::getIndexes($table) as $index) {
            if (!\is_array($index)) {
                continue;
            }

            $columns = $index['columns'] ?? null;

            if (\is_array($columns) && $columns === [$column]) {
                return true;
            }
        }

        return false;
    }

    private function tableExists(string $table): bool
    {
        if (Schema::hasTable($table)) {
            return true;
        }

        $this->components->warn("Таблица {$table} отсутствует — пропущено (миграции пакета не выполнялись).");

        return false;
    }

    private function skip(string $target): void
    {
        $this->components->twoColumnDetail($target, '<fg=gray>уже есть</>');
    }

    private function markAdded(string $target): void
    {
        $this->added[] = $target;
        $this->components->twoColumnDetail($target, '<fg=green>добавлено</>');
    }

    private function markFailed(string $target, string $message): void
    {
        $this->failed[] = $target;
        $this->components->twoColumnDetail($target, '<fg=red>ошибка: ' . $message . '</>');
    }
}
