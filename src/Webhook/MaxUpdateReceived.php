<?php

declare(strict_types=1);

namespace GeekCo\LaravelMaxClient\Webhook;

use GeekCo\MaxPhpClient\Dto\Update;

final class MaxUpdateReceived
{
    public function __construct(
        public readonly Update $update,
    ) {
    }
}
