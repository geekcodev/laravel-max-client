<?php

declare(strict_types=1);

namespace GeekCo\LaravelMaxClient\Models;

use GeekCo\LaravelMaxClient\Enums\MaxChatStatus;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Зафиксированное взаимодействие бота с пользователем в чате.
 *
 * Не список состава чата: бот не видит участников, кроме тех, чьи апдейты он
 * получил. Строка появляется на bot_added/bot_started и обновляется на
 * message/comment/callback.
 *
 * @property string $id
 * @property int $chat_id
 * @property int $user_id
 * @property MaxChatStatus $status
 * @property \Illuminate\Support\Carbon|null $last_activity_at
 * @property-read MaxChat $maxChat
 * @property-read MaxUser $maxUser
 */
class MaxChatUser extends Model
{
    use HasUuids;

    protected $table = 'max_chat_users';

    protected $fillable = [
        'chat_id',
        'user_id',
        'status',
        'last_activity_at',
    ];

    protected function casts(): array
    {
        return [
            'chat_id' => 'integer',
            'user_id' => 'integer',
            'status' => MaxChatStatus::class,
            'last_activity_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<MaxChat, $this>
     */
    public function maxChat(): BelongsTo
    {
        return $this->belongsTo(MaxChat::class, 'chat_id', 'chat_id');
    }

    /**
     * @return BelongsTo<MaxUser, $this>
     */
    public function maxUser(): BelongsTo
    {
        return $this->belongsTo(MaxUser::class, 'user_id', 'user_id');
    }
}
