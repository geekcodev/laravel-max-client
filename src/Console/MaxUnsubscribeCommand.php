<?php

declare(strict_types=1);

namespace GeekCo\LaravelMaxClient\Console;

use GeekCo\MaxPhpClient\ApiClient;
use GeekCo\MaxPhpClient\Exception\MaxApiException;
use Illuminate\Console\Command;

final class MaxUnsubscribeCommand extends Command
{
    protected $signature = 'max:unsubscribe {url}';

    protected $description = 'Удалить webhook-подписку MAX для указанного URL';

    public function handle(ApiClient $api): int
    {
        $url = $this->argument('url');

        if (!is_string($url)) {
            $this->error('URL должен быть строкой.');

            return self::FAILURE;
        }

        try {
            $api->deleteSubscription($url);
        } catch (MaxApiException $exception) {
            $this->error("Не удалось удалить подписку: {$exception->getMessage()}");

            return self::FAILURE;
        }

        $this->info("Подписка удалена: {$url}");

        return self::SUCCESS;
    }
}
