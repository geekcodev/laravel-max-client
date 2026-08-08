<?php

declare(strict_types=1);

// Подмена HTTP-транспорта (PSR-18) своим клиентом.
//
// По умолчанию пакет использует Guzzle с опциями http.options из конфига.
// Чтобы задействовать собственную реализацию Psr\Http\Client\ClientInterface
// (например, mock для тестов), зарегистрируйте её в контейнере до первого
// резолва ApiClient — синглтон создаётся один раз.

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Psr\Http\Client\ClientInterface;

final class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // $this->app->instance(ClientInterface::class, new YourClient());

        // Пример: обернуть Guzzle с кастомными настройками (таймауты, TLS).
        $this->app->singleton(ClientInterface::class, static function (): ClientInterface {
            return new \GuzzleHttp\Client([
                'timeout' => 10.0,
                'connect_timeout' => 5.0,
                'verify' => storage_path('certs/max-ca-chain.pem'),
            ]);
        });
    }
}
