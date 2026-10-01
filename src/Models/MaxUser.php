<?php

declare(strict_types=1);

namespace GeekCo\LaravelMaxClient\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;

/**
 * @property int $user_id
 * @property string $first_name
 * @property string|null $last_name
 * @property string|null $username
 * @property bool $is_bot
 * @property int|null $last_activity_time
 * @property string|null $name
 * @property string|null $description
 * @property string|null $avatar_url
 * @property string|null $full_avatar_url
 * @property string|null $phone
 * @property string|null $email
 * @property \Illuminate\Support\Carbon|null $phone_verified_at
 * @property \Illuminate\Support\Carbon|null $profile_checked_at
 */
class MaxUser extends Model
{
    protected $table = 'max_users';

    protected $primaryKey = 'user_id';

    public $incrementing = false;

    protected $keyType = 'int';

    protected $fillable = [
        'user_id',
        'first_name',
        'last_name',
        'username',
        'is_bot',
        'last_activity_time',
        'name',
        'description',
        'avatar_url',
        'full_avatar_url',
        'phone',
        'email',
        'phone_verified_at',
        'profile_checked_at',
    ];

    protected function casts(): array
    {
        return [
            'user_id' => 'integer',
            'is_bot' => 'boolean',
            'last_activity_time' => 'integer',
            'phone_verified_at' => 'datetime',
            'profile_checked_at' => 'datetime',
        ];
    }

    /**
     * Чаты, в которых бот получал апдейты от пользователя.
     *
     * @return HasManyThrough<MaxChat, MaxChatUser, $this>
     */
    public function maxChats(): HasManyThrough
    {
        return $this->hasManyThrough(
            MaxChat::class,
            MaxChatUser::class,
            'user_id',
            'chat_id',
            'user_id',
            'chat_id',
        );
    }

    /**
     * Прямой доступ к строкам связи: в отличие от maxChats() доступен статус
     * взаимодействия и время последней активности пользователя в чате.
     *
     * @return HasMany<MaxChatUser, $this>
     */
    public function chatLinks(): HasMany
    {
        return $this->hasMany(MaxChatUser::class, 'user_id', 'user_id');
    }
}
