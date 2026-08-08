<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | API token
    |--------------------------------------------------------------------------
    |
    | Access token бота. Передаётся в заголовке Authorization без префикса Bearer.
    |
    */

    'api_token' => env('MAX_API_TOKEN', ''),

    /*
    |--------------------------------------------------------------------------
    | Base URI
    |--------------------------------------------------------------------------
    |
    | Базовый URI API MAX. По умолчанию https://platform-api2.max.ru (домен platform-api2).
    |
    */

    'base_uri' => env('MAX_BASE_URI', 'https://platform-api2.max.ru'),

    /*
    |--------------------------------------------------------------------------
    | HTTP client options
    |--------------------------------------------------------------------------
    |
    | Опции Guzzle по умолчанию (используются, если в контейнере не зарегистрирован
    | собственный PSR-18 клиент Psr\Http\Client\ClientInterface).
    |
    */

    'http' => [
        'options' => [
            'timeout' => 30.0,
            'connect_timeout' => 10.0,
            'verify' => true,
            'http_errors' => false,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Retry strategy
    |--------------------------------------------------------------------------
    |
    | Экспоненциальный бэкофф: 429 / 5xx / сетевые сбои / attachment.not.ready.
    |
    */

    'retry' => [
        'max_attempts' => (int) env('MAX_RETRY_MAX_ATTEMPTS', 3),
        'base_delay_seconds' => (float) env('MAX_RETRY_BASE_DELAY', 1.0),
        'max_delay_seconds' => (float) env('MAX_RETRY_MAX_DELAY', 30.0),
        'factor' => (float) env('MAX_RETRY_FACTOR', 2.0),
        'retry_on_non_idempotent' => filter_var(
            env('MAX_RETRY_ON_NON_IDEMPOTENT', false),
            FILTER_VALIDATE_BOOLEAN,
        ),
    ],

    /*
    |--------------------------------------------------------------------------
    | Rate limit
    |--------------------------------------------------------------------------
    |
    | Token bucket 2 req/s на диалог/чат/канал (лимит API MAX).
    |
    */

    'rate_limit' => [
        'tokens_per_second' => (float) env('MAX_RATE_LIMIT_TOKENS_PER_SECOND', 2.0),
        'max_tokens' => (float) env('MAX_RATE_LIMIT_MAX_TOKENS', 2.0),
    ],

    /*
    |--------------------------------------------------------------------------
    | Webhook
    |--------------------------------------------------------------------------
    |
    | Роут регистрируется только при enabled=true и заданном secret (fail-closed).
    | allowed_hosts — allow-list хостов для подписок/загрузок (пусто — разрешены только
    | домены API MAX, https://).
    |
    */

    'webhook' => [
        'enabled' => filter_var(env('MAX_WEBHOOK_ENABLED', false), FILTER_VALIDATE_BOOLEAN),
        'secret' => env('MAX_WEBHOOK_SECRET', ''),
        'queue' => env('MAX_WEBHOOK_QUEUE', 'default'),
        'path' => env('MAX_WEBHOOK_PATH', '/max/webhook'),
        'middleware' => ['throttle:60,1'],
        'allowed_hosts' => [],
    ],

    /*
    |--------------------------------------------------------------------------
    | Long Polling
    |--------------------------------------------------------------------------
    |
    | Только для локальной разработки: вебхук требует публичного домена с HTTPS
    | и доверенным CA. Активная webhook-подписка отключает Long Polling.
    | Запуск: php artisan max:listen (--once — одна партия апдейтов).
    |
    */

    'long_polling' => [
        'limit' => (int) env('MAX_POLLING_LIMIT', 100),
        'timeout' => (int) env('MAX_POLLING_TIMEOUT', 30),
        'break_on_failure' => filter_var(env('MAX_POLLING_BREAK_ON_FAILURE', true), FILTER_VALIDATE_BOOLEAN),
    ],

];
