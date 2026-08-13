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

    /*
    |--------------------------------------------------------------------------
    | WebAppData (мини-приложение)
    |--------------------------------------------------------------------------
    |
    | Верификация стартовых данных мини-приложения (HMAC-SHA256, ядро
    | WebAppDataValidator). max_age — срок жизни auth_date в секундах
    | (replay-защита; 0 — не проверять свежесть).
    |
    | strict — если true, middleware max.webapp возвращает 403 при открытии
    | без валидного WebAppData. session — имена ключей сессии, в которые
    | middleware кладёт user_id/chat_id.
    |
    */

    'webapp' => [
        'max_age' => (int) env('MAX_WEBAPP_MAX_AGE', 86400),
        'strict' => filter_var(env('MAX_WEBAPP_STRICT', false), FILTER_VALIDATE_BOOLEAN),
        'session' => [
            'user_id' => (string) env('MAX_WEBAPP_SESSION_USER_ID', 'user_id'),
            'chat_id' => (string) env('MAX_WEBAPP_SESSION_CHAT_ID', 'chat_id'),
        ],
        'frame_ancestors' => [
            'enabled' => filter_var(env('MAX_WEBAPP_CSP_ENABLED', true), FILTER_VALIDATE_BOOLEAN),
            'hosts' => array_values(array_filter(array_map(
                'trim',
                explode(',', (string) env('MAX_WEBAPP_FRAME_ANCESTORS', 'https://max.ru,https://web.max.ru')),
            ))),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Реестр чатов (bot_chats)
    |--------------------------------------------------------------------------
    |
    | Готовая реализация документированной практики MAX: chat_id хранить через
    | подписку на bot_added/bot_started (getChats deprecated). При enabled=true
    | пакет регистрирует слушателя MaxUpdateReceived, который обновляет таблицу
    | bot_chats. Миграция публикуется: php artisan vendor:publish
    | --tag=laravel-max-client-migrations. model — класс модели (для переопределения).
    |
    */

    'chats' => [
        'enabled' => filter_var(env('MAX_CHATS_ENABLED', false), FILTER_VALIDATE_BOOLEAN),
        'model' => (string) env('MAX_CHATS_MODEL', \GeekCo\LaravelMaxClient\Models\BotChat::class),
    ],

    /*
    |--------------------------------------------------------------------------
    | Logging
    |--------------------------------------------------------------------------
    |
    | Логирование HTTP-запросов/ответов (middleware max_bot.log) и обработки
    | апдейтов. По умолчанию выключено (fail-safe, без накладных расходов).
    |
    | При enabled=true пакет автоматически подключает middleware к роуту вебхука;
    | для остальных роутов (например, /webapp) подключай alias 'max_bot.log'
    | вручную в списке middleware роута.
    |
    | channel — канал Laravel; если не определён — fallback_channel, затем stack.
    | log_request_body / log_response_body — включать тело (OWASP A09: тело и
    | возможные секреты логируются только при явном включении). Секретные ключи
    | в теле (token, secret, password и т.п.) всегда маскируются.
    | exclude_*_paths — пути, для которых тело/логирование пропускается
    | (LIKE-сравнение по подстроке, как в Laravel request path()).
    |
    */

    'logging' => [
        'enabled' => filter_var(env('MAX_LOGGING_ENABLED', false), FILTER_VALIDATE_BOOLEAN),
        'channel' => (string) env('MAX_LOGGING_CHANNEL', 'stack'),
        'fallback_channel' => (string) env('MAX_LOGGING_FALLBACK_CHANNEL', 'laravel-max-client'),
        'log_request_body' => filter_var(env('MAX_LOGGING_LOG_REQUEST_BODY', false), FILTER_VALIDATE_BOOLEAN),
        'log_response_body' => filter_var(env('MAX_LOGGING_LOG_RESPONSE_BODY', false), FILTER_VALIDATE_BOOLEAN),
        'log_response_body_max_length' => (int) env('MAX_LOGGING_LOG_RESPONSE_BODY_MAX_LENGTH', 1000),
        'exclude_paths' => [],
        'exclude_request_body_paths' => [],
        'exclude_response_body_paths' => [],
    ],

];
