<?php

declare(strict_types=1);

namespace GeekCo\LaravelMaxClient\Enums;

enum BotChatStatus: string
{
    case Active = 'active';
    case Stopped = 'stopped';
    case Removed = 'removed';

    public function label(): string
    {
        return match ($this) {
            self::Active => 'Активен',
            self::Stopped => 'Остановлен',
            self::Removed => 'Удалён',
        };
    }
}
