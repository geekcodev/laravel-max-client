<?php

declare(strict_types=1);

namespace GeekCo\LaravelMaxClient\Console;

use GeekCo\LaravelMaxClient\Services\MaxSchemaUpgrade;
use Illuminate\Console\Command;
use RuntimeException;
use Throwable;

/**
 * Перевод реестров MAX на форму v1.2.0: одна строка max_chats на чат вместо
 * строки на пару «пользователь + чат», первичный ключ строки — сам chat_id.
 *
 * Нужна проектам, у которых пакет стоял до v1.2.0. Create-миграции переписаны,
 * но их имена уже записаны в таблице migrations, поэтому до потребителя
 * изменённые файлы не доходят — без этой команды первый же апдейт упал бы на
 * колонке, которой больше нет.
 *
 * Порядок обязателен: сначала `php artisan migrate` (он создаёт таблицу
 * max_chat_users), затем эта команда. Пока она не выполнена, max_chats остаётся
 * в прежней форме, а модель MaxChat ждёт новую.
 *
 * Команда идемпотентна в обе стороны: на чистой схеме ей нечего делать, на
 * прежней она переносит данные, повторный запуск ничего не меняет. Сделать
 * резервную копию базы стоит до неё — откат восстанавливает форму, но не
 * содержимое тех колонок, которых в прежней форме не было.
 */
final class MaxUpgradeCommand extends Command
{
    protected $signature = 'max:upgrade
                            {--dry-run : Показать, что изменится, ничего не применяя}
                            {--force : Без подтверждения}
                            {--rollback : Вернуть форму до v1.2.0 (строка на пару «пользователь + чат»)}';

    protected $description = 'Перевести реестры MAX на форму v1.2.0: одна строка max_chats на чат с ключом chat_id';

    public function handle(MaxSchemaUpgrade $upgrade): int
    {
        try {
            return $this->rollback($upgrade)
                ?? $this->forward($upgrade);
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
    }

    private function forward(MaxSchemaUpgrade $upgrade): int
    {
        if (!$upgrade->isLegacy()) {
            $this->info('Реестры уже в форме v1.2.0: переносить нечего.');

            return self::SUCCESS;
        }

        if (!$upgrade->linksTableExists()) {
            $this->error('Нет таблицы max_chat_users. Сначала выполните `php artisan migrate`.');

            return self::FAILURE;
        }

        $plan = $upgrade->inspect();

        $this->table(
            ['Чатов', 'Пар «чат + пользователь»', 'Чатов с дублями'],
            [[$plan['chats'], $plan['pairs'], $plan['duplicateChats']]],
        );

        $this->line('Будет создана строка на чат с ключом chat_id, пары уйдут в max_chat_users, '
            .'колонки идентификаторов станут знаковыми.');

        if ((bool) $this->option('dry-run')) {
            $this->info('Режим --dry-run: ничего не изменено.');

            return self::SUCCESS;
        }

        if (!$this->confirmToProceed()) {
            $this->warn('Отменено, ничего не изменено.');

            return self::SUCCESS;
        }

        $upgrade->upgrade();

        $this->info('Реестры переведены на форму v1.2.0.');

        return self::SUCCESS;
    }

    private function rollback(MaxSchemaUpgrade $upgrade): ?int
    {
        if (!$this->option('rollback')) {
            return null;
        }

        if ($upgrade->isLegacy()) {
            $this->info('Реестры уже в форме до v1.2.0: откатывать нечего.');

            return self::SUCCESS;
        }

        $this->warn('Откат возвращает прежнюю форму: строка на пару «пользователь + чат» с суррогатным ключом.');

        $this->line('Пары из max_chat_users станут отдельными строками; чат без пар сохранится с пустым user_id.');

        if ((bool) $this->option('dry-run')) {
            $this->info('Режим --dry-run: ничего не изменено.');

            return self::SUCCESS;
        }

        if (!$this->confirmToProceed()) {
            $this->warn('Отменено, ничего не изменено.');

            return self::SUCCESS;
        }

        $result = $upgrade->rollback();

        $this->info(\sprintf('Возвращено прежней формы: чатов %d, строк по парам %d.', $result['chats'], $result['pairs']));

        return self::SUCCESS;
    }

    /**
     * @throws RuntimeException если не подтверждено
     */
    private function confirmToProceed(): bool
    {
        if ((bool) $this->option('force')) {
            return true;
        }

        if ($this->option('no-interaction')) {
            throw new RuntimeException(
                'Нужно подтверждение. Запустите команду с --force или интерактивно.',
            );
        }

        return $this->confirm('Выполнить?', true);
    }
}
