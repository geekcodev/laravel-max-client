<?php

declare(strict_types=1);

namespace GeekCo\LaravelMaxClient\Enums;

enum MaxChatStatus: string
{
    case Active = 'active';
    case Stopped = 'stopped';
    case Removed = 'removed';

    public function label(): string
    {
        $label = __('laravel-max-client::enums.chat_status.'.$this->value);

        return \is_string($label) ? $label : $this->value;
    }
}
