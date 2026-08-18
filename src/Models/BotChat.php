<?php

declare(strict_types=1);

namespace GeekCo\LaravelMaxClient\Models;

use GeekCo\LaravelMaxClient\Enums\BotChatStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BotChat extends Model
{
    protected $table = 'max_bot_chats';

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

    /**
     * @return BelongsTo<MaxUser, $this>
     */
    public function maxUser(): BelongsTo
    {
        return $this->belongsTo(MaxUser::class, 'user_id', 'user_id');
    }
}
