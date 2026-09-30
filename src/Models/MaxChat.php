<?php

declare(strict_types=1);

namespace GeekCo\LaravelMaxClient\Models;

use GeekCo\LaravelMaxClient\Enums\MaxChatStatus;
use GeekCo\MaxPhpClient\Enum\ChatType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $user_id
 * @property int $chat_id
 * @property-read MaxUser|null $maxUser
 * @property MaxChatStatus $status
 * @property ChatType|null $chat_type
 * @property string|null $title
 * @property string|null $description
 * @property string|null $link
 * @property string|null $icon_url
 * @property \Illuminate\Support\Carbon|null $title_checked_at
 * @property \Illuminate\Support\Carbon|null $last_activity_at
 */
class MaxChat extends Model
{
    protected $table = 'max_chats';

    protected $fillable = [
        'user_id',
        'chat_id',
        'status',
        'chat_type',
        'title',
        'description',
        'link',
        'icon_url',
        'title_checked_at',
        'last_activity_at',
    ];

    protected function casts(): array
    {
        return [
            'user_id' => 'integer',
            'chat_id' => 'integer',
            'status' => MaxChatStatus::class,
            'chat_type' => ChatType::class,
            'title_checked_at' => 'datetime',
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

    /**
     * Название чата для интерфейса: у группы и канала это `title` из getChat(),
     * у диалога — имя собеседника. Само название приходит только из getChat(),
     * апдейты bot_added/bot_started его не несут.
     */
    public function displayName(): string
    {
        if ($this->isGroup()) {
            $title = $this->title;

            if ($title !== null && $title !== '') {
                return $title;
            }
        }

        $name = $this->userDisplayName();

        if ($name !== '') {
            return $name;
        }

        return 'chat ' . $this->chat_id;
    }

    public function isGroup(): bool
    {
        return $this->chat_type === ChatType::Chat || $this->chat_type === ChatType::Channel;
    }

    /**
     * Имя пользователя, чей апдейт создал запись реестра. Для группы и канала
     * без названия это имя добавившего бота — лучше, чем ничего, но настоящее
     * название чата всё равно приходит из getChat().
     */
    private function userDisplayName(): string
    {
        $user = $this->maxUser;

        if ($user === null) {
            return '';
        }

        if ($user->name !== null && $user->name !== '') {
            return $user->name;
        }

        return trim(($user->first_name ?? '') . ' ' . ($user->last_name ?? ''));
    }
}
