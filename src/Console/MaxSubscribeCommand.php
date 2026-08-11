<?php

declare(strict_types=1);

namespace GeekCo\LaravelMaxClient\Console;

use GeekCo\LaravelMaxClient\Support\Config;
use GeekCo\MaxPhpClient\ApiClient;
use GeekCo\MaxPhpClient\Enum\UpdateType;
use GeekCo\MaxPhpClient\Exception\MaxApiException;
use Illuminate\Console\Command;

final class MaxSubscribeCommand extends Command
{
    protected $signature = 'max:subscribe {url}';

    protected $description = 'Зарегистрировать webhook-подписку MAX на указанный URL';

    public function handle(ApiClient $api, Config $config): int
    {
        $url = $this->argument('url');

        if (!is_string($url)) {
            $this->error('URL должен быть строкой.');

            return self::FAILURE;
        }

        if (!$this->validUrl($url, $config)) {
            $this->error('URL должен быть HTTPS-адресом из allowed_hosts (например https://host/max/webhook).');

            return self::FAILURE;
        }

        $secret = $config->webhookSecret();

        if ($secret === null) {
            $this->warn('MAX_WEBHOOK_SECRET не задан — подписка создастся без секрета (небезопасно).');
        }

        try {
            $subscription = $api->createSubscription(
                url: $url,
                updateTypes: $this->updateTypes(),
                secret: $secret,
            );
        } catch (MaxApiException $exception) {
            $this->error("Не удалось создать подписку: {$exception->getMessage()}");

            return self::FAILURE;
        }

        $this->info("Подписка создана: {$subscription->url}");

        return self::SUCCESS;
    }

    private function validUrl(string $url, Config $config): bool
    {
        if (filter_var($url, FILTER_VALIDATE_URL) === false) {
            return false;
        }

        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        if ($scheme !== 'https') {
            return false;
        }

        $hosts = $config->webhookAllowedHosts();
        if ($hosts === []) {
            return true;
        }

        return in_array(strtolower((string) parse_url($url, PHP_URL_HOST)), $hosts, true);
    }

    /**
     * @return list<string>
     */
    private function updateTypes(): array
    {
        return array_map(
            static fn (UpdateType $type): string => $type->value,
            [
                UpdateType::MessageCreated,
                UpdateType::MessageCallback,
                UpdateType::BotAdded,
                UpdateType::BotStarted,
                UpdateType::BotStopped,
                UpdateType::BotRemoved,
            ],
        );
    }
}
