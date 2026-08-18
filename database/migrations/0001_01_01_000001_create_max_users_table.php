<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::create('max_users', function (Blueprint $table): void {
            $table->unsignedBigInteger('user_id')->primary()->comment('Идентификатор пользователя в MAX');
            $table->string('first_name', 128)->comment('Имя пользователя');
            $table->string('last_name', 128)->nullable()->comment('Фамилия пользователя');
            $table->string('username', 128)->nullable()->comment('Username пользователя (@username)');
            $table->boolean('is_bot')->default(false)->comment('Является ли пользователь ботом');
            $table->unsignedBigInteger('last_activity_time')->nullable()->comment('Время последней активности (Unix, мс)');
            $table->string('name', 256)->nullable()->comment('Отображаемое имя (составное из first/last)');
            $table->text('description')->nullable()->comment('Описание профиля пользователя');
            $table->string('avatar_url', 512)->nullable()->comment('URL аватара (маленькое изображение)');
            $table->string('full_avatar_url', 512)->nullable()->comment('URL полного аватара');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('max_users');
    }
};
