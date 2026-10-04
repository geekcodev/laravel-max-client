<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Полоса 0000_00 — фундамент, на который ссылаются остальные миграции.
 *
 * Полосы миграций i2tech распределены по глубине зависимости внешних ключей,
 * потому что Laravel сортирует миграции приложения и пакетов по имени файла и
 * идёт по списку сверху вниз:
 *
 *   0000_00 — этот пакет: max_users, max_chats, max_chat_users
 *   0000_01 — приложение, системные таблицы (users и прочие)
 *   0000_02 — filament-max-chat
 *   0000_03 — filament-max-broadcasts
 *   0000_04 — приложение, доменные таблицы
 *
 * Например, users.max_user_id ссылается на max_users, а max_chat_messages и
 * max_broadcasts ссылаются на users: поэтому этот пакет идёт и до, и после
 * системных миграций приложения. Свою полосу выделять нельзя, если на таблицы
 * пакета ссылается чужой пакет: полоса — это позиция в общем порядке, а не
 * отдельный именованный диапазон.
 *
 * hasTable в up() обязателен: прежние имена 0001_01_01_000001… уже записаны в
 * таблицу migrations у установленных приложений, поэтому после обновления они
 * числятся невыполненными при существующей таблице.
 */
return new class () extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('max_users')) {
            return;
        }

        Schema::create('max_users', function (Blueprint $table): void {
            $table->bigInteger('user_id')->primary()->comment('Идентификатор пользователя в MAX (int64)');
            $table->string('first_name', 128)->comment('Имя пользователя');
            $table->string('last_name', 128)->nullable()->comment('Фамилия пользователя');
            $table->string('username', 128)->nullable()->comment('Username пользователя (@username)');
            $table->boolean('is_bot')->default(false)->comment('Является ли пользователь ботом');
            $table->unsignedBigInteger('last_activity_time')->nullable()->comment('Время последней активности (Unix, мс)');
            $table->string('name', 256)->nullable()->comment('Отображаемое имя (составное из first/last)');
            $table->text('description')->nullable()->comment('Описание профиля пользователя');
            $table->string('avatar_url', 512)->nullable()->comment('URL аватара (маленькое изображение)');
            $table->string('full_avatar_url', 512)->nullable()->comment('URL полного аватара');
            $table->string('phone', 32)->nullable()->comment('Телефон пользователя');
            $table->string('email', 256)->nullable()->comment('Email пользователя');
            $table->timestamp('phone_verified_at')->nullable()->comment('Время получения подтверждённого телефона из контакта');
            $table->timestamp('profile_checked_at')->nullable()->comment('Время последней синхронизации профиля с MAX');
            $table->timestamps();

            $table->index('phone');
            $table->index('profile_checked_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('max_users');
    }
};
