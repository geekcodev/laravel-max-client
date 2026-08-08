<?php

declare(strict_types=1);

namespace GeekCo\LaravelMaxClient\Console;

use GeekCo\LaravelMaxClient\Support\Config;
use GeekCo\LaravelMaxClient\Webhook\HandleMaxUpdateJob;
use GeekCo\LaravelMaxClient\Webhook\MaxUpdateReceived;
use GeekCo\MaxPhpClient\ApiClient;
use GeekCo\MaxPhpClient\LongPolling\LongPollingRunner;
use Illuminate\Console\Command;
use Illuminate\Contracts\Events\Dispatcher;

final class MaxListenCommand extends Command
{
    protected $signature = 'max:listen
        {--marker= : Начальный marker (последний обработанный timestamp)}
        {--once : Обработать одну партию апдейтов и завершиться}';

    protected $description = 'Обрабатывает апдейты MAX через Long Polling (для локальной разработки без публичного домена)';

    public function handle(
        ApiClient $api,
        Config $config,
        Dispatcher $dispatcher,
        LongPollingRunner $runner,
    ): int {
        if ($config->webhookEnabled()) {
            $this->warn('Вебхук включён: активная webhook-подписка отключает Long Polling.');
        }

        if (!$dispatcher->hasListeners(MaxUpdateReceived::class)) {
            $this->warn('Нет слушателей события MaxUpdateReceived — апдейты обрабатываться не будут.');

            return self::SUCCESS;
        }

        if ($this->option('once')) {
            return $this->handleOnce($api, $config);
        }

        $lastMarker = $runner->run($this->markerOption());

        $this->info(sprintf('Long polling остановлен. Последний marker: %d.', $lastMarker));

        return self::SUCCESS;
    }

    private function handleOnce(ApiClient $api, Config $config): int
    {
        $updates = $api->getUpdates(
            limit: $config->pollingLimit(),
            timeout: $config->pollingTimeout(),
            marker: $this->markerOption(),
        );

        foreach ($updates as $update) {
            HandleMaxUpdateJob::dispatch($update)->onQueue($config->webhookQueue());
        }

        $this->info(sprintf('Обработано апдейтов: %d.', count($updates)));

        return self::SUCCESS;
    }

    private function markerOption(): ?int
    {
        $marker = $this->option('marker');

        return $marker === null ? null : (int) $marker;
    }
}
