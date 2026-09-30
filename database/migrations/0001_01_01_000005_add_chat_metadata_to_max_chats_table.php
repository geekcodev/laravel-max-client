<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Отдельная аддитивная миграция, а не правка create_max_chats_table: у уже
 * установленных версий пакета файл создания таблицы помечен выполненным, и
 * изменённый на месте он не применился бы — `php artisan migrate` молча
 * ничего не добавил бы.
 *
 * Название, описание, ссылка и иконка приходят только из getChat(); индекс по
 * chat_id добавляется отдельной миграцией 0001_01_01_000004.
 */
return new class () extends Migration {
    public function up(): void
    {
        Schema::table('max_chats', function (Blueprint $table): void {
            $table->string('title', 256)->nullable()->comment('Название группы или канала');
            $table->text('description')->nullable()->comment('Описание чата');
            $table->string('link', 512)->nullable()->comment('Публичная ссылка на чат');
            $table->string('icon_url', 512)->nullable()->comment('URL иконки чата');
            $table->timestamp('title_checked_at')
                ->nullable()
                ->comment('Время последней проверки метаданных через getChat');
        });
    }

    public function down(): void
    {
        Schema::table('max_chats', function (Blueprint $table): void {
            $table->dropColumn(['title', 'description', 'link', 'icon_url', 'title_checked_at']);
        });
    }
};
