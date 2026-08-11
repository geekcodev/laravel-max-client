# AGENTS.md

> Проектный контекст и рабочие правила для разработчиков и ИИ-агентов (включая opencode).
> Читай этот файл **целиком** в начале работы — он задаёт архитектуру, обязательный процесс проверок
> (Gate) и требования SOLID / DRY / KISS / OWASP Top 10.
> Пользовательскую документацию (установка, быстрый старт, интеграция) — в `README.md`.

## 1. О проекте

- **Что это.** Laravel-пакет **`geekcodev/laravel-max-client`** — тонкий фреймворк-адаптер поверх framework-agnostic
  ядра **`geekcodev/max-php-client`** (клиент для **MAX Messenger Bot API**,
  https://max.ru). Репозиторий/рабочая папка — `laravel-max-client`.
- **Ядро.** `geekcodev/max-php-client` v1.0.0+ (namespace `GeekCo\MaxPhpClient`): PSR-7/17/18 транспорт, ретраи, rate
  limit, загрузка медиа, webhook-хендлер, верификация контакта и данных мини-приложения. Источник истины по API —
  `https://github.com/geekcodev/max-openapi` (OpenAPI 3.1).
- **Принцип.** Пакет — **тонкий адаптер**: всю бизнес-логику API (DTO, эндпоинты, ретраи, rate limit, безопасность)
  отдаёт ядру. Здесь живёт только Laravel-клей: ServiceProvider, конфиг, фасад, вебхук-роутинг, очередь. **Не форкать и
  не переписывать ядро**, не дублировать его методы.
- **Лицензия.** MIT (c) 2026 Evgeny Semenov (совпадает с ядром, файл `LICENSE`).
- **Язык.** Рабочий язык общения с пользователем — **русский**.

## 2. Ветки и состояние git

- `main` — стабильная, соответствует релизам.
- `dev` — рабочая ветка; изменения сначала здесь.
- Релизный процесс: PR `dev → main` → тег `vX.Y.Z` → GitHub Release → Packagist (webhook-автообновление).
- `version` в `composer.json` **не указывается** — версия берётся из git-тегов.
- `.env` — untracked (в `.gitignore`): `MAX_API_TOKEN`, `MAX_WEBHOOK_SECRET`. **Никогда не коммитить и не логировать
  значения.** Коммиты и push делает пользователь (в окружении нет credential.helper/gh) — без явного запроса не коммить.

## 3. Правила для ИИ-агентов

1. В начале работы прочитай `AGENTS.md` и `README.md`.
2. **Не коммить и не пушить без явного запроса пользователя.**
3. Перед завершением любой задачи, менявшей код, прогони обязательный Gate (раздел 7) целиком. Результаты не подменяй;
   недоступный шаг честно указывай в отчёте, а не пропускай молча.
4. Не выдумывай сигнатуры и эндпоинты: сверяйся с ядром (`GeekCo\MaxPhpClient\ApiClient`) и спецификацией
   `max-openapi`. Новые методы адаптера — только обёртки над ядром.
5. Если для задачи чего-то не хватает (токен, сеть, контейнер) — скажи об этом, а не упрощай задачу молча.
6. Ответы — краткие и по делу; в коде — без лишних комментариев.

## 4. Структура репозитория (целевая)

```
config/laravel-max-client.php      publishable-конфиг (echo php artisan vendor:publish)
examples/                          рабочие примеры (фасад, webhook-listener, PSR-18, webapp-mini-app, long-polling)
src/
  MaxServiceProvider.php           composition root: publish, bindings, регистрация роута/фасада
  Console/MaxListenCommand.php     artisan max:listen: Long Polling для локальной разработки (--once)
  Console/MaxSubscribeCommand.php  artisan max:subscribe: регистрация webhook-подписки (HTTPS + allowed_hosts)
  Console/MaxUnsubscribeCommand.php artisan max:unsubscribe: удаление webhook-подписки
  WebApp/WebAppContext.php         верификация WebAppData мини-приложения (resolve/verify из Request)
  Facades/Max.php                  фасад поверх ApiClient (из контейнера)
  Contracts/MaxClient.php          интерфейс-фасад-прокси (необязательный, решает KISS при реализации)
  Support/Config.php               stateless readonly-доступ к config('max.*') (live-read из репозитория)
  Http/HttpClientFactory.php       SRP: сборка PSR-18/17 клиентов (Guzzle по умолчанию)
  Webhook/
    MaxWebhookController.php       граница HTTP: verify → decode → dispatch (200 за <30с)
    VerifyMaxWebhookSecret.php     middleware: hash_equals по X-Max-Bot-Api-Secret
    HandleMaxUpdateJob.php         очередь: асинхронная обработка Update (отдельный job на Update)
routes/ (или роут в провайдере)    POST /max/webhook, вне CSRF, с throttle
tests/                             PHPUnit + Orchestra Testbench
  Support/                         MockHttpClient (PSR-18) и фабрики-заглушки
  Unit/                            конфиг, фабрики, middleware, контроллер, job, фасад
  Integration/SmokeTest.php        read-only смоук против реального API (группа integration)
.github/workflows/ci.yml           quality (lint/phpstan/phpunit/coverage/audit) + integration
Dockerfile                         PHP 8.4 + опциональный Xdebug (ARG INSTALL_XDEBUG=false)
docker-compose.yml                 сервис app, user 1000:1000, volume ./ , .env пробрасывается
composer.json                      PSR-4 GeekCo\LaravelMaxClient\, PHP ^8.4
phpunit.xml                        failOnRisky/failOnWarning; группа integration исключена по умолчанию
phpstan.neon                       level max
.php-cs-fixer.dist.php             PSR-12
.env.example                       эталон имён переменных (MAX_*)
scripts/check-coverage.php         порог покрытия строк (по умолчанию 95%)
```

`composer.lock`, `.phpunit.cache/`, `build/`, `vendor/` — в `.gitignore`.

## 5. Архитектура и ключевые контракты

### Слои

| Слой          | Классы                                                                 | Назначение                                                          |
|---------------|------------------------------------------------------------------------|---------------------------------------------------------------------|
| Composition   | `MaxServiceProvider`, `HttpClientFactory`, `Support\Config`            | Сборка зависимостей из контейнера Laravel, конфиг                   |
| Facade        | `Facades\Max`                                                          | Статический доступ к `ApiClient` из кода приложения                 |
| WebApp        | `WebApp\WebAppContext`                                                 | Верификация WebAppData мини-приложения (ядра `WebAppDataValidator`) |
| Webhook       | `MaxWebhookController`, `VerifyMaxWebhookSecret`, `HandleMaxUpdateJob` | Приём/верификация апдейтов, постановка в очередь                    |
| Subscriptions | `MaxSubscribeCommand`, `MaxUnsubscribeCommand`                         | Управление webhook-подписками (HTTPS, allowed_hosts)                |
| Core          | `GeekCo\MaxPhpClient\*` (зависимость)                                  | Транспорт, DTO, ретраи, rate limit, security, upload                |

### Контракты

- **`MaxServiceProvider`**: `register()` — привязки; `boot()` — публикация конфига (`config/laravel-max-client.php`),
  регистрация маршрута вебхука, фасада. Все привязки — синглтоны.
- **`ApiClient` создаётся один раз** из контейнера (Singleton) по конфигу:
    - `api_token` (обязательный) — из `config('laravel-max-client.api_token')` / `env('MAX_API_TOKEN')`;
    - `base_uri` — `MAX_BASE_URI` (по умолчанию `https://platform-api2.max.ru`, домен **`platform-api2`**, не
      `platform-api`);
    - PSR-18 клиент — из контейнера (`Psr\Http\Client\ClientInterface`); если не зарегистрирован — Guzzle с опциями из
      `http.options` (timeout, verify, connect_timeout);
    - PSR-17 фабрики — `GuzzleHttp\Psr7\HttpFactory`;
    - `RetryStrategy`, `RateLimiter` — из конфига (`retry.*`, `rate_limit.*`), при пустых значениях — дефолты ядра.
- **Facade `Max`**: резолвит `ApiClient` из контейнера. Пример:
  ```php
  use GeekCo\LaravelMaxClient\Facades\Max;

  $me = Max::getMe();
  Max::sendMessage(new Recipient(chatId: $chatId), new NewMessageBody(text: 'Привет!'));
  ```
- **Вебхук-роут** `POST /max/webhook` (имя `max.webhook`):
    - регистрируется только при `config('laravel-max-client.webhook.enabled')`;
    - **вне** группы CSRF (сервер-к-серверу, в Laravel — `except` в `ValidateCsrfToken`), с `throttle`;
    - middleware `VerifyMaxWebhookSecret`: `hash_equals(config secret, X-Max-Bot-Api-Secret)`, иначе 401 (без секрета в
      конфиге роут не регистрируется — fail-closed);
    - контроллер: верификация → `(new WebhookHandler(...))->decode()` → на каждый `Update` — `HandleMaxUpdateJob`
      в очередь `config('laravel-max-client.webhook.queue')` → HTTP 200 немедленно (API требует ответ ≤30с; ответ 400 —
      невалидный payload, 401 — неверный секрет);
    - `WebhookHandler::decode()` возвращает `Update|list<Update>` — **не итерировать без `instanceof`-проверки**;
    - обработку апдейтов держать асинхронной (очередь), чтобы уложиться в 30-секундное окно.
- **`HandleMaxUpdateJob`**: `public $deleteWhenMissingModels` не нужен (нет моделей); `$tries`/`$timeout` — публичные
  свойства джоба (по умолчанию 3/30). `shouldQueue()` возвращает `false`, если обработчик не задан (современный Laravel
  его не вызывает, поэтому действенная защита — проверка `hasListeners(MaxUpdateReceived::class)`
  в контроллере **до** dispatch, чтобы не ставить в очередь работу без обработчика). Для приёмки
  `Update` реализуется в приложении (например, через событие `MaxUpdateReceived`) — пакет поставляет механизм доставки,
  не бизнес-обработку.
- **Long Polling** (`artisan max:listen`, `src/Console/MaxListenCommand.php`): только для локальной разработки, когда
  нет публичного домена. Использует ядро `LongPollingRunner` (не дублировать цикл!) и ставит те же `HandleMaxUpdateJob`
  в `webhook.queue` — единый механизм доставки с вебхуком. `--once` — одна партия (для cron/смоука). `long_polling.*`
  конфиг (env `MAX_POLLING_*`); `break_on_failure=true` по умолчанию (для долгой работы в dev — `false`).
- **`WebApp\WebAppContext`** (`src/WebApp/WebAppContext.php`): верификация стартовых данных мини-приложения. Конструктор
  принимает `WebAppDataValidator` из ядра (singleton в контейнере, token из `api_token`, maxAge из
  `config('laravel-max-client.webapp.max_age')`). Методы:
    - `resolve(Request): ?WebAppIdentity` — верифицирует `?WebAppData=...` из query и возвращает идентичность
      (user/chat)
      либо `null` при невалидных/просроченных данных. Это **обязательная** проверка — без неё любой может подделать
      `user_id`/`chat_id`;
    - `verify(Request): bool` — булева проверка без резолва identity. Роутинг в мини-приложении — приложение: пакет
      поставляет только сервис. Значение `auth_date` сверяется с
      `webapp.max_age` (env `MAX_WEBAPP_MAX_AGE`, default 86400; `0` — не проверять).
- **Подписки** (`MaxSubscribeCommand`, `MaxUnsubscribeCommand`): `php artisan max:subscribe <url>` /
  `max:unsubscribe <url>`. URL — только HTTPS; при заданном `config('laravel-max-client.webhook.allowed_hosts')` хост
  сверяется до создания подписки (A10). Подписка — на рекомендованный набор апдейтов (`UpdateType::*`), секрет из
  `MAX_WEBHOOK_SECRET`. Активная подписка отключает Long Polling. Предупреждение без секрета: подписка создастся, но
  роут не зарегистрируется (fail-closed).
- **Токен аутентификации** — заголовок `Authorization: <token>` **без** `Bearer`, не в query (гарантирует ядро).
- **Ошибки**: пакет прокидывает исключения ядра (`GeekCo\MaxPhpClient\Exception\MaxApiException` и наследники). В
  вебхук-контроллере/джобе они логируются без чувствительных данных (code + message ошибки API допустимы; vcf_info,
  payload колбэка, секреты — нет).

### Соглашения

- PHP **8.4**, `declare(strict_types=1)` во всех файлах, PSR-12, PHPStan **level max**.
- Namespace `GeekCo\LaravelMaxClient` (тесты `GeekCo\LaravelMaxClient\Tests`), PSR-4.
- SOLID / DRY / KISS: единая ответственность, открытость к расширению, без избыточной абстракции. Никакого дублирования
  методов ядра — только делегирование.
- Не добавлять комментарии без необходимости.
- Тесты обязательны для нового кода: unit на компоненты пакета; HTTP-слой — через `tests/Support/MockHttpClient`
  (PSR-18) или подмену `ClientInterface` в контейнере Testbench. Интеграционные — read-only, группа `integration`, без
  токена `markTestSkipped` (не падать).
- `composer.json` constraints на момент разработки: `php ^8.4`, `geekcodev/max-php-client ^1.0.1` (WebAppDataValidator
  появился в v1.0.1 ядра — версия ниже не резолвит классы WebApp),
  `laravel/framework ^12.0|^13.0`, `guzzlehttp/guzzle ^7.15` (обязателен как PSR-18 по умолчанию),
  `illuminate/support`/`illuminate/queue`/`illuminate/routing` — через `laravel/framework`; dev — Testbench под
  поддерживаемую версию Laravel, phpunit ^11.5, phpstan ^2.0, friendsofphp/php-cs-fixer ^3.0. Точные версии Testbench
  сверить с совместимостью Laravel на момент реализации.

### OWASP Top 10 (обязательно при написании кода)

- **A01** — вебхук-роут вне CSRF (сервер-к-серверу), защита секретом; fail-closed (без секрета роут не включается).
- **A02** — токены/секреты только из env-конфига, никогда в коде, логах, коммитах; TLS гарантирует ядро (https-only).
- **A03** — не доверять входящим данным: вебхук-body, query/path-параметры, поля JSON (валидация в DTO ядра). Никаких
  конкатенаций URL вручную — только PSR-7 (ядро).
- **A04** — не доверять телу вебхука: жёсткий `json_decode(..., JSON_THROW_ON_ERROR)` через ядро, валидация структуры
  `Update`; неизвестные `update_type` не валят обработку. WebAppData мини-приложения — обязательно через
  `WebAppContext` (HMAC + max_age), иначе подделка `user_id`/`chat_id`.
- **A05** — publishable-конфиг с безопасными дефолтами; `php artisan config:cache` безопасен для `env()`
  (использовать только на этапе конфига); секреты не попадают в `config:show` без необходимости (документировать
  маскирование при выводе).
- **A06/A08** — актуальные зависимости: PHP ^8.4, ядро ^1.0.1, `composer audit` в Gate и CI; CI на push/PR.
- **A07** — все сравнения секретов — только `hash_equals` (ядро + middleware вебхука).
- **A09** — не логировать: access token, webhook secret, `vcf_info`, callback payload, тела запросов с токеном.
  Логировать статус/код/сообщение ошибки API (в коде ядра сообщения не содержат токенов).
- **A10** — URL подписок/загрузки только `https://` (ядро); при необходимости — allow-list хостов в конфиге
  (`webhook.allowed_hosts`), валидация домена до создания подписки.

## 6. Ключевые факты API MAX (источник — ядро/`max-openapi`)

- Аутентификация: `Authorization: <access_token>` без `Bearer`; query-передача токена не поддерживается.
- Сервер: `https://platform-api2.max.ru` (домен **`platform-api2`**).
- Нужна цепочка сертификатов Минцифры (в локальных средах — кастомный CA).
- Вебхуки — только HTTPS :443, доверенный CA, полная цепочка; секрет 5–256 символов `[a-zA-Z0-9_-]`; ответ обязателен
  200 за 30 сек, иначе повторы 60с→150с→375с→… (10 попыток ~8ч), затем автоотписка.
- Активная webhook-подписка отключает Long Polling. Long Polling — только для разработки/тестов.
- Rate limits: отправка/редактирование/удаление сообщений и ответы на callback — **макс. 2/сек** на диалог/чат/канал
  (ядро: `RateLimiter`, token bucket).
- Загрузка медиа: `POST /uploads` с `type` в query; после загрузки **ждать** готовности вложения —
  `attachment.not.ready` ретраится автоматически. Домены загрузки: `https://fu.oneme.ru`,
  `https://iu.oneme.ru`, `https://vu.okcdn.ru`.
- `GET /chats` **deprecated** — хранить `chat_id` через подписку на `bot_added`/`bot_started`.
- `type=photo` deprecated → `type=image`.
- Timestamp: почти все — **Unix в миллисекундах**; исключение `join_time` — секунды.
- Пагинация: `marker` (int64, nullable) + `count`. `message_id`/`callback_id` — строки; `chat_id`/`user_id` — int64.
- Эндпоинты и DTO — см. `src/ApiClient.php` ядра и `max-openapi`. Не выдумывать сигнатуры.

## 7. Локальная разработка и обязательный Gate

PHP и Composer на хосте **не установлены** — весь запуск через Docker:

```bash
docker compose run --rm app bash                       # интерактивная оболочка PHP 8.4
docker compose run --rm app composer install
docker compose run --rm app composer run lint          # php-cs-fixer --dry-run (PSR-12)
docker compose run --rm app composer run format        # php-cs-fixer: авто-исправление
docker compose run --rm app vendor/bin/phpstan analyse # level max
docker compose run --rm app vendor/bin/phpunit         # unit-тесты (Testbench)
docker compose run --rm app composer run coverage      # тесты + проверка покрытия ≥95%
docker compose run --rm app composer audit             # уязвимости зависимостей (OWASP A06/A08)
```

Интеграционные смоук-тесты (read-only, реальный API, нужен `MAX_API_TOKEN`):

```bash
source .env && docker run --rm --network host \
  -v "$(pwd)":/var/www/html -w /var/www/html \
  -e MAX_API_TOKEN="$MAX_API_TOKEN" \
  ghcr.io/geekcodev/php:8.4-bookworm vendor/bin/phpunit --group integration
```

Нюансы интеграционных тестов: TLS до `platform-api2.max.ru` из Docker-сети блокируется — только `--network host`;
цепочка сертификатов Минцифры — `tests/Fixtures/max-ca-chain.pem` (скопировать из ядра при необходимости); без
токена/доступа тесты пропускаются (`markTestSkipped`), а не падают.

### Обязательная последовательность (Gate) перед завершением задачи

После изменений в PHP-коде (`src/`, `tests/`, `config/`, `routes/`):

1. **Lint**: `composer run lint` → 0 файлов с правками.
2. Если есть правки — `composer run format`, затем повторить lint.
3. **Статика**: `vendor/bin/phpstan analyse` → 0 ошибок.
4. **Тесты**: `vendor/bin/phpunit` → все зелёные (failOnRisky/failOnWarning).
5. **Покрытие**: `composer run coverage` → ≥95% строк.
6. **Audit**: `composer audit` → 0 уязвимостей.

Все шаги обязательны. Если шаг недоступен в окружении — сообщить пользователю и указать в отчёте.

## 8. CI/CD и релизы

- **Job `quality`**: Docker-образ с Xdebug (`--build-arg INSTALL_XDEBUG=true`), `COMPOSER_ROOT_VERSION=dev-main`, lint,
  phpstan, phpunit + coverage gate, `composer audit`.
- **Job `integration`**: смоук-тесты реального API; без `MAX_API_TOKEN` — шаги пропускаются (`secrets` в `if`
  на уровне job запрещены GitHub Actions, передавать через job-level `env`).
- **Релиз**: merge PR `dev → main` → `git tag vX.Y.Z && git push origin vX.Y.Z` → GitHub Release из тега → Packagist
  (автообновление по webhook). Тег ставится только на `main`.

## 9. Частые ошибки (gotchas)

1. `WebhookHandler::decode()` возвращает `Update|list<Update>` — **не** итерировать без `instanceof`-проверки.
2. Токен — без `Bearer`; только заголовок, не query (гарантирует ядро — не пробрасывать в query вручную).
3. `attachment.not.ready` — вложение ещё не готово: ядро ретраит само, не дублировать ретрай на уровне пакета.
4. `join_time` — секунды; остальные timestamp — миллисекунды.
5. Из Docker-сети TLS до API блокируется — только `--network host`.
6. Имя переменной — только `MAX_API_TOKEN` (старое `MAX_ACCESS_TOKEN` не используется).
7. `getChats` deprecated — хранить `chat_id` через `bot_added`/`bot_started`.
8. Вебхук-роут обязан вернуть 200 ≤30с — обрабатывать апдейты только через очередь; никогда не блокировать контроллер
   бизнес-логикой.
9. Секреты/токены/`vcf_info`/callback payload — никогда в логи и коммиты.
10. Версионирование — только git-тегами; `version` в composer.json не указывать.
11. Вебхук-роут вне CSRF (иначе все запросы вернут 419) — но защищён секретом + throttle.
12. Long Polling (`max:listen`) — только для dev; активная webhook-подписка его отключает.
13. WebAppData мини-приложения — только через `WebAppContext` (не доверять `?WebAppData=...` без верификации HMAC).
14. `max:subscribe`/`max:unsubscribe` — только HTTPS-URL; `allowed_hosts` проверяется до создания подписки.
15. Пакет — тонкий адаптер: не переписывать логику ядра, только делегировать.

## 10. Чек-лист «production-grade» (самооценка при доработках)

- [ ] CI зелёный: lint 0, phpstan 0, phpunit зелёные, покрытие ≥95%, `composer audit` чист.
- [ ] Новый код покрыт unit-тестами (HTTP-слой — через MockHttpClient / подмену в контейнере).
- [ ] Секретов нет в коде, логах, коммитах; конфиг publishable с безопасными дефолтами.
- [ ] Входные данные валидируются (вебхук, middleware, параметры запросов).
- [ ] Вебхук-обработка асинхронная; 200 в окне 30с; fail-closed без секрета.
- [ ] Документация (README, .env.example, AGENTS.md) синхронна с реальным поведением кода.
- [ ] Релиз оформлен: merge в main → тег → GitHub Release → Packagist.
