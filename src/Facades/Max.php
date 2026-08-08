<?php

declare(strict_types=1);

namespace GeekCo\LaravelMaxClient\Facades;

use GeekCo\MaxPhpClient\ApiClient;
use Illuminate\Support\Facades\Facade;

/**
 * @method static \GeekCo\MaxPhpClient\Dto\BotInfo getMe()
 * @method static \GeekCo\MaxPhpClient\Dto\Message sendMessage(\GeekCo\MaxPhpClient\Dto\Recipient $recipient, \GeekCo\MaxPhpClient\Dto\NewMessageBody $body)
 *
 * @see \GeekCo\MaxPhpClient\ApiClient
 */
final class Max extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return ApiClient::class;
    }
}
