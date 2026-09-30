<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Отдельная миграция от добавления колонок: индекс по chat_id нужен сам по
 * себе (выборка метаданных идёт по одному чату для всех записей его
 * участников), а unique(user_id, chat_id) его не покрывает — он ведёт по
 * user_id. Идёт раньше миграций с колонками, чтобы на большой таблице
 * индекс добавлялся, пока она ещё не получила новые колонки.
 *
 * Отдельный файл, а не правка create_max_chats_table: у уже установленных
 * версий пакета файл создания таблицы помечен выполненным, и изменённый на
 * месте он не применился бы — `php artisan migrate` молча ничего не добавил бы.
 */
return new class () extends Migration {
    public function up(): void
    {
        Schema::table('max_chats', function (Blueprint $table): void {
            $table->index('chat_id');
        });
    }

    public function down(): void
    {
        Schema::table('max_chats', function (Blueprint $table): void {
            $table->dropIndex(['chat_id']);
        });
    }
};
