<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::create('max_bot_chats', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('user_id')->comment('Идентификатор пользователя MAX');
            $table->unsignedBigInteger('chat_id')->comment('Идентификатор чата в MAX');
            $table->string('status', 16)->default('active')->comment('Статус чата: active/stopped/removed');
            $table->timestamp('last_activity_at')->nullable()->comment('Время последней активности в чате');
            $table->timestamps();

            $table->unique(['user_id', 'chat_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('max_bot_chats');
    }
};
