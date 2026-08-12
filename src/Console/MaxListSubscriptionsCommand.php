<?php

declare(strict_types=1);

namespace GeekCo\LaravelMaxClient\Console;

use GeekCo\MaxPhpClient\ApiClient;
use GeekCo\MaxPhpClient\Exception\MaxApiException;
use Illuminate\Console\Command;

final class MaxListSubscriptionsCommand extends Command
{
    protected $signature = 'max:subscriptions';

    protected $description = 'Показать все webhook-подписки MAX';

    public function handle(ApiClient $api): int
    {
        try {
            $subscriptions = $api->getSubscriptions();
        } catch (MaxApiException $exception) {
            $this->error("Не удалось получить подписки: {$exception->getMessage()}");

            return self::FAILURE;
        }

        if ($subscriptions === []) {
            $this->info('Подписок нет.');

            return self::SUCCESS;
        }

        $rows = [];
        foreach ($subscriptions as $subscription) {
            $rows[] = [
                $subscription->url,
                $subscription->updateTypes === null
                    ? ''
                    : implode(', ', $subscription->updateTypes),
            ];
        }

        $this->table(['URL', 'Update types'], $rows);

        return self::SUCCESS;
    }
}
