<?php

declare(strict_types=1);

namespace GeekCo\LaravelMaxClient\Tests\Support;

use GeekCo\LaravelMaxClient\Models\BotChat;

final class CustomBotChat extends BotChat
{
    protected $table = 'custom_chats';
}
