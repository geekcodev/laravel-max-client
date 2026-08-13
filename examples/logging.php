<?php

declare(strict_types=1);

// Логирование запросов/ответов MAX (middleware max_bot.log, введено в v1.0.5).
//
// Пример рассчитан на Laravel-приложение, в котором установлен пакет
// geekcodev/laravel-max-client. По умолчанию логирование ВЫКЛЮЧЕНО (A09):
// включается через конфиг logging.enabled или env MAX_LOGGING_ENABLED=true.

// --- 1. Включение и канал ---
// В .env приложения:
//   MAX_LOGGING_ENABLED=true
//   MAX_LOGGING_CHANNEL=laravel-max-client       // канал из config/logging.php
//   MAX_LOGGING_FALLBACK_CHANNEL=single          // если канала нет — fallback
// Если указанных каналов нет — используется 'stack'.
// Неизвестные каналы Laravel молча не подставляет: резолв идёт по
// config('logging.channels.*'), поэтому fallback работает детерминированно.

use GeekCo\LaravelMaxClient\Http\Middleware\LogMaxRequestsMiddleware;
use GeekCo\LaravelMaxClient\Support\Logger;
use Illuminate\Contracts\Http\Kernel;

// --- 2. Вебхук ---
// При MAX_LOGGING_ENABLED=true провайдер сам подключает middleware к роуту
// /max/webhook ПЕРЕД проверкой секрета (VerifyMaxWebhookSecret): в лог попадут
// даже запросы с неверным X-Max-Bot-Api-Secret. Для прочих роутов — вручную:

$app->make(Kernel::class)
    ->appendMiddlewareToGroup('web', LogMaxRequestsMiddleware::class);

// --- 3. Что и как пишется ---
// Запрос:  INFO  Incoming MAX request  {method, url, ip, user_agent[, body]}
// Ответ:   INFO/ WARNING / ERROR  MAX response  {status, duration_ms[, body]}
// Уровень ответа: 2xx→info, 4xx→warning, 5xx→error.
// X-Request-ID из запроса проксируется в ответ.
// handle в HandleMaxUpdateJob пишет start/finish/failed (update_type,
// user_id, chat_id) — переносятся как есть.

// --- 4. Тело записей ---
// Тело пишется ТОЛЬКО при явном включении (A09 — не логировать секреты):
//   MAX_LOGGING_LOG_REQUEST_BODY=true
//   MAX_LOGGING_LOG_RESPONSE_BODY=true
//   MAX_LOGGING_LOG_RESPONSE_BODY_MAX_LENGTH=1000   // обрезка не-JSON тел
// Ключи password/token/secret/auth_token/access_token/api_key/authorization
// маскируются рекурсивно → '***'.

// --- 5. Исключения ---
//   MAX_LOGGING_EXCLUDE_PATHS=/health,/metrics        // полный пропуск
//   MAX_LOGGING_EXCLUDE_REQUEST_BODY_PATHS=/metrics   // без тела запроса
//   MAX_LOGGING_EXCLUDE_RESPONSE_BODY_PATHS=/metrics  // без тела ответа
// (значения разделяются запятой; совпадение — подстрока пути)

// --- 6. Программное использование (опционально) ---
// Support\Logger — резолвер с учётом enabled и fallback:
$logger = $app->make(Logger::class);
if ($logger->isEnabled()) {
    $logger->log('info', 'custom message', ['context_key' => 'value']);
}
