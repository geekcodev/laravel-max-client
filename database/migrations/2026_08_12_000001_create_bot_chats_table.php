<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::create('bot_chats', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('chat_id');
            $table->string('status', 16)->default('active');
            $table->timestamps();

            $table->unique(['user_id', 'chat_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bot_chats');
    }
};
