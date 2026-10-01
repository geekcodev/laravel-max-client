<?php

declare(strict_types=1);

namespace GeekCo\LaravelMaxClient\Tests\Support;

use GeekCo\LaravelMaxClient\Models\MaxChatUser;

final class CustomMaxChatUser extends MaxChatUser
{
    protected $table = 'max_chat_users';
}
