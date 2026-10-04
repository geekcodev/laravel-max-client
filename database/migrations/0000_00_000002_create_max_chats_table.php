<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Реестр чатов: одна строка на чат, первичный ключ — chat_id в MAX.
 *
 * Полоса 0000_00 — фундамент миграций, раскладка полос описана в
 * 0000_00_000001_create_max_users_table. Здесь она важна потому, что на max_chats
 * ссылается filament-max-chat (полоса 0000_02).
 *
 * hasTable в up() обязателен: прежнее имя 0001_01_01_000002 уже записано в
 * таблицу migrations у установленных приложений.
 */
return new class () extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('max_chats')) {
            return;
        }

        Schema::create('max_chats', function (Blueprint $table): void {
            $table->bigInteger('chat_id')->primary()->comment('Идентификатор чата в MAX (int64, у групп и каналов отрицательный)');
            $table->string('status', 16)->default('active')->comment('Статус чата');
            $table->string('chat_type', 16)->nullable()->comment('Тип чата');
            $table->string('title', 256)->nullable()->comment('Название группы или канала');
            $table->text('description')->nullable()->comment('Описание чата');
            $table->string('link', 512)->nullable()->comment('Публичная ссылка на чат');
            $table->string('icon_url', 512)->nullable()->comment('URL иконки чата');
            $table->timestamp('chat_checked_at')->nullable()->comment('Время последней проверки метаданных через getChat');
            $table->timestamp('last_activity_at')->nullable()->comment('Время последней активности в чате');
            $table->timestamps();

            $table->index('status');
            $table->index('last_activity_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('max_chats');
    }
};
