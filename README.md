# geekcodev/laravel-max-client

Тонкий Laravel-адаптер для **MAX Messenger Bot API** поверх framework-agnostic ядра
[`geekcodev/max-php-client`](https://github.com/geekcodev/max-php-client).

Пакет отвечает только за «Laravel-клей»: конфиг, DI, фасад, вебхук-роутинг, очередь. Вся бизнес-логика API (DTO,
эндпоинты, ретраи, rate limit, безопасность, загрузка медиа) живёт в ядре — см. его документацию и OpenAPI-спецификацию
`max-openapi`.

## Требования

- PHP ^8.4
- Laravel ^12.0|^13.0
- `geekcodev/max-php-client` ^1.0.6

## Установка

```bash
composer require geekcodev/laravel-max-client
```

Сервис-провайдер `GeekCo\LaravelMaxClient\MaxServiceProvider` и alias `Max`
подхватываются автоматически (package discovery). Затем опубликуйте конфиг:

```bash
php artisan vendor:publish --tag=laravel-max-client-config
```

## Конфигурация

Минимально необходима одна переменная — токен бота:

```dotenv
MAX_API_TOKEN=your-bot-access-token
```

Все доступные переменные (имена см. в `.env.example`):

| Переменная                                 | По умолчанию                             | Описание                                                               |
|--------------------------------------------|------------------------------------------|------------------------------------------------------------------------|
| `MAX_API_TOKEN`                            | —                                        | Токен бота (заголовок `Authorization`)                                 |
| `MAX_BASE_URI`                             | `https://platform-api2.max.ru`           | Базовый URI API (домен `platform-api2`)                                |
| `MAX_WEBHOOK_ENABLED`                      | `false`                                  | Регистрировать вебхук-роут                                             |
| `MAX_WEBHOOK_SECRET`                       | —                                        | Секрет вебхука (без него роут не включается)                           |
| `MAX_WEBHOOK_QUEUE`                        | `default`                                | Очередь для джобов обработки Update                                    |
| `MAX_WEBHOOK_PATH`                         | `/max/webhook`                           | Путь вебхук-роута                                                      |
| `MAX_RETRY_*`                              | 3 / 1 / 30 / 2 / false                   | Ретраи (попытки/базовая/макс. задержка/фактор/не-идемпотентные)        |
| `MAX_RATE_LIMIT_*`                         | 2.0 / 2.0                                | Token bucket на диалог/чат/канал: токенов в секунду / максимум         |
| `MAX_GLOBAL_RATE_LIMIT_*`                  | 30.0 / 30.0                              | Глобальный token bucket на весь API (ожидание, не ошибка)              |
| `MAX_WEBAPP_MAX_AGE`                       | `86400`                                  | Срок жизни `auth_date` мини-приложения, сек (0 — не проверять)         |
| `MAX_WEBAPP_STRICT`                        | `false`                                  | `max.webapp` возвращает 403 без валидного WebAppData                   |
| `MAX_WEBAPP_SESSION_USER_ID`               | `user_id`                                | Ключ сессии для user_id (middleware `max.webapp`)                      |
| `MAX_WEBAPP_SESSION_CHAT_ID`               | `chat_id`                                | Ключ сессии для chat_id (middleware `max.webapp`)                      |
| `MAX_WEBAPP_CSP_ENABLED`                   | `true`                                   | Добавлять `frame-ancestors` в CSP (middleware `max.csp`)               |
| `MAX_WEBAPP_FRAME_ANCESTORS`               | `https://max.ru,https://web.max.ru`      | Хосты, которым разрешено встраивать мини-приложение (через запятую)    |
| `MAX_CHATS_ENABLED`                        | `false`                                  | Включает реестр чатов `bot_chats` (слушатель `PersistBotChatListener`) |
| `MAX_CHATS_MODEL`                          | `GeekCo\LaravelMaxClient\Models\BotChat` | Модель реестра чатов (для переопределения)                             |
| `MAX_LOGGING_ENABLED`                      | `false`                                  | Включает логирование (middleware `max.log`)                           |
| `MAX_LOGGING_CHANNEL`                      | `stack`                                  | Канал Laravel для логов                                                |
| `MAX_LOGGING_FALLBACK_CHANNEL`             | `laravel-max-client`                     | Запасной канал, если основной не определён                             |
| `MAX_LOGGING_LOG_REQUEST_BODY`             | `false`                                  | Логировать тело запроса (секреты маскируются)                          |
| `MAX_LOGGING_LOG_RESPONSE_BODY`            | `false`                                  | Логировать тело ответа                                                 |
| `MAX_LOGGING_LOG_RESPONSE_BODY_MAX_LENGTH` | `1000`                                   | Макс. длина не-JSON тела ответа в логе                                 |

> Токен и секрет никогда не должны попадать в код, логи или коммиты — только env.

## Использование

Фасад `Max` резолвит единый экземпляр `ApiClient` из контейнера:

```php
use GeekCo\LaravelMaxClient\Facades\Max;
use GeekCo\MaxPhpClient\Dto\Recipient;
use GeekCo\MaxPhpClient\Dto\NewMessageBody;

$me = Max::getMe();

Max::sendMessage(
    new Recipient(chatId: $chatId),
    new NewMessageBody(text: 'Привет!'),
);

// Фасад делегирует все методы ядра (см. PHPDoc @method): чаты, участники,
// админы, закреп, команды, медиа, подписки.
Max::sendBotAction($chatId, SenderAction::Typing);
$admins = Max::getChatAdmins($chatId); // ChatAdminsResult::$members
```

Список доступных методов — в PHPDoc фасада `GeekCo\LaravelMaxClient\Facades\Max` и в ядре
`GeekCo\MaxPhpClient\ApiClient` (актуальные сигнатуры — v1.0.6).

Полные рабочие примеры — в каталоге [`examples/`](examples/):
`basic-usage.php` (фасад), `webhook-listener.php` (обработка апдейтов),
`custom-http-client.php` (подмена PSR-18 клиента),
`webapp.php` (верификация WebAppData мини-приложения),
`long-polling-local-dev.md` (настройка и запуск Long Polling локально и в Docker, а также тест настоящего вебхука через
туннель + `max:subscribe`/`max:unsubscribe`).

### Свой PSR-18 клиент

По умолчанию используется Guzzle с опциями `http.options`. Чтобы подменить транспорт, зарегистрируйте свою реализацию
`Psr\Http\Client\ClientInterface` в контейнере:

```php
// AppServiceProvider
$this->app->instance(\Psr\Http\Client\ClientInterface::class, $yourClient);
```

## WebAppData (мини-приложение)

Сервис `WebAppContext` верифицирует стартовые данные мини-приложения MAX (HMAC-SHA256, ядро
`WebAppDataValidator`) и извлекает из них идентификацию пользователя и диалога. Верификация обязательна — без неё любой
может подделать `user_id`/`chat_id`:

```php
use GeekCo\LaravelMaxClient\WebApp\WebAppContext;
use Illuminate\Http\Request;

class WebAppController
{
    public function __invoke(Request $request, WebAppContext $webAppContext)
    {
        $identity = $webAppContext->resolve($request); // GeekCo\MaxPhpClient\Dto\WebAppIdentity|null

        if ($identity === null) {
            abort(403);
        }

        // $identity->userId, $identity->chatId
    }
}
```

Свежесть `auth_date` проверяется по `MAX_WEBAPP_MAX_AGE` (по умолчанию 86400 сек; `0` — не проверять). Сырой
`WebAppDataValidator` доступен из контейнера для случаев, когда данные получены не из `Request`.

> **Важно.** MAX открывает мини-приложение по URL `https://<domain>/webapp#WebAppData=...` — стартовые параметры лежат в
> **URL-фрагменте** и до сервера не доходят. В вебхуке/на странице брать их из `?WebAppData=` нельзя: в реальном MAX его
> нет. Поэтому фронт должен передать строку WebAppData (из фрагмента или `window.WebApp.initData`) в запросе —
> например, заголовком `X-Max-WebApp-Data` — а сервер верифицировать её через `WebAppContext::verifyData()` /
> `resolveData()`:

```php
$webAppData = $request->header('X-Max-WebApp-Data');

if (is_string($webAppData) && $webAppContext->verifyData($webAppData)) {
    $identity = $webAppContext->resolveData($webAppData);
    // $identity->userId, $identity->chatId
}
```

`verify(Request)`/`resolve(Request)` остаются для пути `?WebAppData=` (фолбэк/dev).

### Middleware `max.webapp` (сессия + strict)

Готовый middleware верифицирует WebAppData и кладёт `user_id`/`chat_id` в сессию, при `MAX_WEBAPP_STRICT=true` отвечает
`403` без валидных данных (иначе — пропускает в демо-режиме):

```php
// routes/web.php
Route::get('/webapp', WebAppController::class)->middleware('max.webapp');
```

```php
use GeekCo\LaravelMaxClient\WebApp\ResolveWebAppIdentity;

class WebAppController
{
    public function __invoke(Request $request)
    {
        $identity = $request->attributes->get(ResolveWebAppIdentity::REQUEST_ATTRIBUTE); // WebAppIdentity|null
        // $request->session()->get('user_id'), $request->session()->get('chat_id')
    }
}
```

Ключи сессии настраиваются (`MAX_WEBAPP_SESSION_USER_ID` / `MAX_WEBAPP_SESSION_CHAT_ID`). Верифицированная идентичность
также доступна в атрибуте запроса `ResolveWebAppIdentity::REQUEST_ATTRIBUTE`.

### Middleware `max.csp` (встраивание в MAX)

Добавляет в `Content-Security-Policy` директиву `frame-ancestors 'self' <hosts>` (по умолчанию
`https://max.ru https://web.max.ru`) — необходимо каждому мини-приложению, встраиваемому в MAX. Если CSP-заголовок уже
задан приложением — директива дописывается:

```php
Route::get('/webapp', WebAppController::class)->middleware(['max.webapp', 'max.csp']);
```

Отключение — `MAX_WEBAPP_CSP_ENABLED=false`, хосты — `MAX_WEBAPP_FRAME_ANCESTORS=https://a.ru,https://b.ru`.

## Реестр чатов (bot_chats)

Реализация документированной практики MAX: `getChats` deprecated, `chat_id` хранить через подписку на
`bot_added`/`bot_started`. Пакет даёт готовую модель, миграцию и слушателя, обновляющего реестр по апдейтам
`bot_added`/`bot_started`/`bot_stopped`/`bot_removed`.

1. Опубликуйте и выполните миграцию:

   ```bash
   php artisan vendor:publish --tag=laravel-max-client-migrations
   php artisan migrate
   ```

2. Включите реестр:

   ```dotenv
   MAX_CHATS_ENABLED=true
   ```

Пакет регистрирует `PersistBotChatListener` на событие `MaxUpdateReceived` (таблица `bot_chats`, статусы
`active`/`stopped`/`removed`). Модель можно переопределить через `MAX_CHATS_MODEL` (класс-наследник
`GeekCo\LaravelMaxClient\Models\BotChat`).

## Подписки (webhook)

Пакет регистрирует команды `max:subscribe` и `max:unsubscribe` для управления webhook-подписками:

```bash
php artisan max:subscribe https://example.com/max/webhook
php artisan max:unsubscribe https://example.com/max/webhook
```

- Подписка создаётся на рекомендованный набор апдейтов (`message_created`, `message_callback`, `bot_added`,
  `bot_started`, `bot_stopped`, `bot_removed`) с секретом из `MAX_WEBHOOK_SECRET`.
- URL проверяется: только HTTPS. Если задан `webhook.allowed_hosts` — хост должен быть в списке.
- Предупреждение без секрета: подписка создастся, но роут не зарегистрируется (fail-closed).

## Вебхук

1. Включите вебхук и задайте секрет:

   ```dotenv
   MAX_WEBHOOK_ENABLED=true
   MAX_WEBHOOK_SECRET=some-secret
   ```

   Роут `POST /max/webhook` (имя `max.webhook`) регистрируется **только** при включённом флаге и заданном секрете
   (fail-closed). Роут вне CSRF, с `throttle:60,1`
   (настраивается в `webhook.middleware` конфига). Приёмка проверяет
   `X-Max-Bot-Api-Secret` через `hash_equals` (иначе 401).

2. Подпишитесь на событие доставки `MaxUpdateReceived`:

   ```php
   // app/Providers/EventServiceProvider.php
   protected $listen = [
       \GeekCo\LaravelMaxClient\Webhook\MaxUpdateReceived::class => [
           YourUpdateListener::class,
       ],
   ];
   ```

   Обработчик:

   ```php
   use GeekCo\LaravelMaxClient\Webhook\MaxUpdateReceived;

   class YourUpdateListener
   {
       public function handle(MaxUpdateReceived $event): void
       {
           $update = $event->update; // GeekCo\MaxPhpClient\Dto\Update
           // бизнес-обработка апдейта
       }
   }
   ```

3. Пакет ставит `HandleMaxUpdateJob` в очередь `webhook.queue` на **каждый** `Update`
   и сразу отвечает `200` (API требует ответ в течение 30 секунд). Если на событие нет слушателей — работа в очередь не
   ставится.

## Long Polling (локальная разработка)

Вебхук требует публичного домена с HTTPS и доверенным CA, поэтому для локальной разработки используйте Long Polling:

```bash
php artisan max:listen
```

Команда опрашивает `GET /updates` через ядро (`LongPollingRunner`) и ставит
`HandleMaxUpdateJob` в ту же очередь (`webhook.queue`) — апдейты обрабатывает тот же слушатель `MaxUpdateReceived`.
Остановка — Ctrl+C.

Опции:

- `--marker=42` — начать с указанного marker (последний обработанный timestamp);
- `--once` — обработать одну партию апдейтов и завершиться (для cron/смоука).

Поведение по умолчанию — в секции `long_polling` конфига (env `MAX_POLLING_*`):
`limit` (100), `timeout` (30 сек), `break_on_failure` (`true` — завершаться при ошибке API; для долгой работы в dev
задайте `MAX_POLLING_BREAK_ON_FAILURE=false`).

> Активная webhook-подписка отключает Long Polling — не используйте оба механизма одновременно.

## Логирование (middleware `max.log`)

Опциональное логирование входящих запросов/ответов и обработки апдейтов. По умолчанию выключено (fail-safe). При
`MAX_LOGGING_ENABLED=true` middleware автоматически подключается к роуту вебхука **перед** `VerifyMaxWebhookSecret` — в
лог попадают и ответы 401/400.

```dotenv
MAX_LOGGING_ENABLED=true
MAX_LOGGING_CHANNEL=max   # канал нужно определить в config/logging.php приложения
```

Что пишется:

- `Incoming MAX request` / `MAX response` (метод, url, ip, user_agent, статус, `duration_ms`); уровни: 2xx→info,
  4xx→warning, 5xx→error.
- `HandleMaxUpdateJob`: start/finish/failed с контекстом `update_type`, `user_id`, `chat_id` — видна обработка в
  очереди.
- Тело запроса/ответа — только при `MAX_LOGGING_LOG_REQUEST_BODY` / `MAX_LOGGING_LOG_RESPONSE_BODY`
  (OWASP A09). Секретные ключи (`token`, `secret`, `password`, `api_key`, `authorization` и т.п.)
  всегда маскируются как `***` (рекурсивно).

Для остальных роутов (например мини-приложения) подключайте alias вручную:

```php
Route::get('/webapp', WebAppController::class)->middleware(['max.webapp', 'max.log']);
```

Пути из `logging.exclude_paths` полностью пропускаются, из `exclude_request_body_paths` /
`exclude_response_body_paths` — логируются без тела. Заголовок `X-Request-ID` из запроса проксируется в ответ. Если
канал из `MAX_LOGGING_CHANNEL` не определён — используется
`MAX_LOGGING_FALLBACK_CHANNEL`, затем `stack`.

## Тестирование

```bash
# unit-тесты (Testbench), lint, статика, покрытие, аудит
composer run lint
composer run format
composer run analyse
vendor/bin/phpunit
composer run coverage
composer audit
```

Интеграционные смоук-тесты против реального API (read-only, нужен `MAX_API_TOKEN`, TLS из Docker-сети блокируется —
только `--network host`):

```bash
source .env && docker run --rm --network host \
  -v "$(pwd)":/var/www/html -w /var/www/html \
  -e MAX_API_TOKEN="$MAX_API_TOKEN" \
  ghcr.io/geekcodev/php:8.4-bookworm vendor/bin/phpunit --group integration
```

## Лицензия

MIT (c) 2026 Evgeny Semenov. См. `LICENSE`.
