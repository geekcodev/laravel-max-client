<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Реестр зафиксированных взаимодействий бота с пользователями чата.
 *
 * Не список состава чата: бот не видит участников, кроме тех, чьи апдейты он
 * получил. Строка появляется на bot_added/bot_started и обновляется на
 * message/comment/callback.
 *
 * Полоса 0000_00 — фундамент миграций, раскладка полос описана в
 * 0000_00_000001_create_max_users_table.
 *
 * hasTable в up() обязателен: прежнее имя 0001_01_01_000003 уже записано в
 * таблицу migrations у установленных приложений.
 */
return new class () extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('max_chat_users')) {
            return;
        }

        Schema::create('max_chat_users', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->bigInteger('chat_id')->comment('Идентификатор чата в MAX (int64, у групп и каналов отрицательный)');
            $table->bigInteger('user_id')->comment('Идентификатор пользователя в MAX (int64)');
            $table->string('status', 16)->default('active')->comment('Статус взаимодействия');
            $table->timestamp('last_activity_at')->nullable()->comment('Время последней активности пользователя в чате');
            $table->timestamps();

            $table->unique(['chat_id', 'user_id']);
            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('max_chat_users');
    }
};
