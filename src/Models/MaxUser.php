<?php

declare(strict_types=1);

namespace GeekCo\LaravelMaxClient\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

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
    ];

    protected function casts(): array
    {
        return [
            'user_id' => 'integer',
            'is_bot' => 'boolean',
            'last_activity_time' => 'integer',
        ];
    }

    /**
     * @return HasMany<BotChat, $this>
     */
    public function botChats(): HasMany
    {
        return $this->hasMany(BotChat::class, 'user_id', 'user_id');
    }
}
