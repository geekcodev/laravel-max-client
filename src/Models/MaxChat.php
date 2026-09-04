<?php

declare(strict_types=1);

namespace GeekCo\LaravelMaxClient\Models;

use GeekCo\LaravelMaxClient\Enums\MaxChatStatus;
use GeekCo\MaxPhpClient\Enum\ChatType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MaxChat extends Model
{
    protected $table = 'max_chats';

    protected $fillable = [
        'user_id',
        'chat_id',
        'status',
        'chat_type',
        'last_activity_at',
    ];

    protected function casts(): array
    {
        return [
            'user_id' => 'integer',
            'chat_id' => 'integer',
            'status' => MaxChatStatus::class,
            'chat_type' => ChatType::class,
            'last_activity_at' => 'datetime',
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
