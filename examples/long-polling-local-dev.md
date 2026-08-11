# Long Polling для локальной разработки и запуска

Вебхук MAX требует публичного домена с HTTPS и доверенным CA (полная цепочка Минцифры), поэтому для локальной разработки
по умолчанию используется **Long Polling** — команда
`php artisan max:listen`. Апдейты обрабатываются тем же слушателем `MaxUpdateReceived`, что и вебхук (см.
`examples/webhook-listener.php`).

Альтернатива — протестировать настоящий вебхук через туннель до локального приложения
(`cloudflared tunnel --url http://localhost:8000`, `ngrok http 8000`) и команды подписок
`php artisan max:subscribe https://<tunnel>/max/webhook` / `max:unsubscribe` (HTTPS + `webhook.allowed_hosts`).
После отписки для подписки Long Polling снова возвращается `max:listen`.

## 1. Конфигурация (.env)

```dotenv
# Токен бота (обязательно).
MAX_API_TOKEN=your-bot-access-token

# Вебхук выключен — активная webhook-подписка отключает Long Polling.
MAX_WEBHOOK_ENABLED=false

# Параметры Long Polling (опционально).
MAX_POLLING_LIMIT=100
MAX_POLLING_TIMEOUT=30
# true — завершаться при ошибке API; false — продолжать работать в dev.
MAX_POLLING_BREAK_ON_FAILURE=true

# Обработка джобов синхронно (без queue:work) — удобно для локальной разработки.
QUEUE_CONNECTION=sync
```

Если API недоступен с системными CA — укажите цепочку Минцифры в опубликованном конфиге
(`config/laravel-max-client.php`):

```php
'http' => [
    'options' => [
        'verify' => storage_path('certs/max-ca-chain.pem'),
    ],
],
```

## 2. Запуск

```bash
# Интерактивный цикл (Ctrl+C — остановка).
php artisan max:listen

# С указанного marker (последний обработанный timestamp).
php artisan max:listen --marker=1700000000000

# Одна партия апдейтов и завершение (cron/смоук).
php artisan max:listen --once
```

## 3. Запуск в Docker

Из Docker-сети TLS до `platform-api2.max.ru` блокируется — нужен `--network host`
(тот же нюанс, что и у интеграционных тестов). Токен берётся из `.env`:

```bash
# Одна партия (смоук).
docker compose run --rm --network host app php artisan max:listen --once

# Длительный цикл (Ctrl+C — остановка).
docker compose run --rm --network host app php artisan max:listen
```

Используйте `docker compose run --rm`, а не `up` — у сервиса `restart: always`.

## 4. Очередь

Джобы `HandleMaxUpdateJob` уходят в `webhook.queue` (по умолчанию `default`):

- с `QUEUE_CONNECTION=sync` обработка выполняется сразу — worker не нужен;
- иначе запустите worker: `php artisan queue:work --queue=default`.
