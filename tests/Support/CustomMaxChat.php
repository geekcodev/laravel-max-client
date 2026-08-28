<?php

declare(strict_types=1);

namespace GeekCo\LaravelMaxClient\Tests\Support;

use GeekCo\LaravelMaxClient\Models\MaxChat;

final class CustomMaxChat extends MaxChat
{
    protected $table = 'custom_chats';
}
