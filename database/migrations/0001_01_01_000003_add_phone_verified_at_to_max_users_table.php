<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Отдельная аддитивная миграция, а не правка create_max_users_table: у уже
 * установленных версий пакета файл создания таблицы помечен выполненным, и
 * изменённый на месте он не применился бы — `php artisan migrate` молча
 * ничего не добавил бы.
 */
return new class () extends Migration {
    public function up(): void
    {
        Schema::table('max_users', function (Blueprint $table): void {
            $table->timestamp('phone_verified_at')
                ->nullable()
                ->comment('Время получения подтверждённого телефона из контакта');
        });
    }

    public function down(): void
    {
        Schema::table('max_users', function (Blueprint $table): void {
            $table->dropColumn('phone_verified_at');
        });
    }
};
