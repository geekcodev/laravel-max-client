<?php

declare(strict_types=1);

namespace GeekCo\LaravelMaxClient\Models;

use GeekCo\LaravelMaxClient\Enums\BotChatStatus;
use Illuminate\Database\Eloquent\Model;

class BotChat extends Model
{
    protected $table = 'bot_chats';

    protected $fillable = [
        'user_id',
        'chat_id',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'user_id' => 'integer',
            'chat_id' => 'integer',
            'status' => BotChatStatus::class,
        ];
    }
}
