<?php

declare(strict_types=1);

namespace GeekCo\LaravelMaxClient\Models;

use GeekCo\LaravelMaxClient\Enums\MaxChatStatus;
use GeekCo\MaxPhpClient\Enum\ChatType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;

/**
 * @property int $chat_id
 * @property-read MaxChatStatus $status
 * @property ChatType|null $chat_type
 * @property string|null $title
 * @property string|null $description
 * @property string|null $link
 * @property string|null $icon_url
 * @property \Illuminate\Support\Carbon|null $chat_checked_at
 * @property \Illuminate\Support\Carbon|null $last_activity_at
 * @property-read \Illuminate\Database\Eloquent\Collection<int, MaxUser> $maxUsers
 * @property-read \Illuminate\Database\Eloquent\Collection<int, MaxChatUser> $chatUsers
 */
class MaxChat extends Model
{
    protected $table = 'max_chats';

    /**
     * Первичный ключ — сам идентификатор чата из MAX: строка реестра одна на
     * чат, суррогатного счётчика нет. Значение приходит из `bot_added` или
     * `bot_started` и не генерируется приложением.
     */
    protected $primaryKey = 'chat_id';

    public $incrementing = false;

    protected $keyType = 'int';

    protected $fillable = [
        'chat_id',
        'status',
        'chat_type',
        'title',
        'description',
        'link',
        'icon_url',
        'chat_checked_at',
        'last_activity_at',
    ];

    protected function casts(): array
    {
        return [
            'chat_id' => 'integer',
            'status' => MaxChatStatus::class,
            'chat_type' => ChatType::class,
            'chat_checked_at' => 'datetime',
            'last_activity_at' => 'datetime',
        ];
    }

    /**
     * Пользователи, чьи апдейты бот получал в этом чате.
     *
     * У колонки user_id есть общий источник в max_users и в max_chat_users,
     * поэтому в выборках её нужно указывать с именем таблицы:
     * $chat->maxUsers()->pluck('max_users.user_id').
     *
     * @return HasManyThrough<MaxUser, MaxChatUser, $this>
     */
    public function maxUsers(): HasManyThrough
    {
        return $this->hasManyThrough(
            MaxUser::class,
            MaxChatUser::class,
            'chat_id',
            'user_id',
            'chat_id',
            'user_id',
        );
    }

    /**
     * @return HasMany<MaxChatUser, $this>
     */
    public function chatUsers(): HasMany
    {
        return $this->hasMany(MaxChatUser::class, 'chat_id', 'chat_id');
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
     * Имя первого пользователя, чей апдейт создал запись в реестре. Для группы
     * и канала без названия это имя добавившего бота — лучше, чем ничего, но
     * настоящее название чата всё равно приходит из getChat().
     */
    private function userDisplayName(): string
    {
        $user = $this->chatUsers()->first()?->maxUser;

        if ($user === null) {
            return '';
        }

        if ($user->name !== null && $user->name !== '') {
            return $user->name;
        }

        return trim(($user->first_name ?? '') . ' ' . ($user->last_name ?? ''));
    }
}
