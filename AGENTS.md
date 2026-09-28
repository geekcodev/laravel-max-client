# AGENTS.md

> Проектный контекст и рабочие правила для разработчиков и ИИ-агентов (включая opencode).
> Читай этот файл **целиком** в начале работы — он задаёт архитектуру, обязательный процесс проверок
> (Gate) и требования SOLID / DRY / KISS / OWASP Top 10.
> Пользовательскую документацию (установка, быстрый старт, интеграция) — в `README.md`.
> Справочник контрактов **API MAX** (спека, эндпоинты, enums, DTO, лимиты, вебхуки) находится в ядре —
> `max-php-client/docs/api-reference.md`; читай его перед правками DTO, enum, транспорта, вебхуков и вызовов API.
> Здесь перечислены только факты, влияющие на адаптер.

## 1. О проекте

- **Что это.** Laravel-пакет **`geekcodev/laravel-max-client`** — тонкий фреймворк-адаптер поверх framework-agnostic
  ядра **`geekcodev/max-php-client`** (клиент для **MAX Messenger Bot API**,
  https://max.ru). Репозиторий/рабочая папка — `laravel-max-client`.
- **Статус.** Последний выпущенный релиз адаптера — **v1.1.2** (тег `v1.1.2`, GitHub Release, Packagist
  `geekcodev/laravel-max-client`). Актуальную версию всегда уточняй по `git tag --sort=-v:refname | head -1` и
  `git log --oneline -10`, а не по этому файлу. Незакоммиченная работа лежит в `dev`.
- **Ядро.** `geekcodev/max-php-client` (namespace `GeekCo\MaxPhpClient`) — последний тег на момент последней
  синхронизации **v1.1.6**, constraint в `composer.json` — `^1.1.6`. Ядро даёт PSR-7/17/18 транспорт, ретраи, rate
  limit, загрузку медиа, webhook-хендлер, верификацию контакта и данных мини-приложения. **Фактические версии проверяй
  динамически**: `composer show geekcodev/max-php-client` и `git -C ../max-php-client tag --sort=-v:refname |
  head -1` — не по этому файлу. Исправления ядра (CRLF/base64/`vcf_info` в верификации контакта, строковый
  `message.link.sender`, комментарии и разметка) адаптер наследует автоматически вместе с обновлением зависимости.
  Источник истины по API — `docs/api-reference.md` ядра и https://github.com/geekcodev/max-openapi (OpenAPI 3.1).
- **Принцип.** Пакет — **тонкий адаптер**: всю бизнес-логику API (DTO, эндпоинты, ретраи, rate limit, безопасность)
  отдаёт ядру. Здесь живёт только Laravel-клей: ServiceProvider, конфиг, фасад, вебхук-роутинг, очередь. **Не форкать и
  не переписывать ядро**, не дублировать его методы.
- **Лицензия.** MIT (c) 2026 Evgeny Semenov (совпадает с ядром, файл `LICENSE`).
- **Язык.** Рабочий язык общения, все md-файлы и журнал — **русский**.

## 2. Ветки и состояние git

- `main` — стабильная, соответствует релизам.
- `dev` — рабочая ветка; изменения сначала здесь.
- Релизный процесс: PR `dev → main` → тег `vX.Y.Z` → GitHub Release → Packagist (webhook-автообновление).
- `version` в `composer.json` **не указывается** — версия берётся из git-тегов.
- `.env` — untracked (в `.gitignore`): `MAX_API_TOKEN`, `MAX_WEBHOOK_SECRET`. **Никогда не коммитить и не логировать
  значения.** Коммиты и push делает пользователь (в окружении нет credential.helper/gh) — без явного запроса не коммить.
- **Различай «текст коммита» и «коммит».** Если пользователь просит «напиши текст/сообщение коммита» — верни краткий
  HEAD (одна строка subject) на английском языке по [Conventional Commits](https://www.conventionalcommits.org/):
  `тип` (`feat`, `fix`, `refactor`, `style`, `docs`, `test`, `chore`, `ci`, ...) + `scope` + краткое описание, без тела,
  **без** выполнения `git commit`. Если просит «закоммить» / «сделай коммит» — тогда выполняй реальный `git commit` с
  таким коротким сообщением. Никогда не коммить по умолчанию и не делай `git add .` без проверки `git status` и
  `git diff`.
- Перед завершением релиза проверь, что в рабочем дереве нет мусора: `git status --short` должен содержать только
  ожидаемые записи. Каталог `.agents/` в `.gitignore` — это ожидаемо, а не мусор.

## 3. Правила для ИИ-агентов

1. В начале работы прочитай `AGENTS.md` и `README.md`; при задачах на обновление под новую версию ядра/`max-openapi`
   также читай протокол обновления `.agents/upgrade/UPGRADE_PLAN.md` и `docs/api-reference.md` ядра.
2. **Не коммить и не пушить без явного запроса пользователя.**
3. Перед завершением любой задачи, менявшей код, прогони обязательный Gate (раздел 7) целиком. Результаты не подменяй;
   недоступный шаг честно указывай в отчёте, а не пропускай молча.
4. Не выдумывай сигнатуры и эндпоинты: сверяйся с ядром (`GeekCo\MaxPhpClient\ApiClient`), `docs/api-reference.md` ядра
   и спецификацией `max-openapi`. Прод-поведение важнее спеки в случаях, перечисленных в `docs/api-reference.md` ядра
   (раздел 9). Новые методы адаптера — только обёртки над ядром.
5. Если для задачи чего-то не хватает (токен, сеть, контейнер) — скажи об этом, а не упрощай задачу молча.
6. Ответы — краткие и по делу; в коде — без лишних комментариев.
7. **Расхождение или пробел, найденные в интеграционном Laravel-приложении, — регрессия этого пакета.** Зафиксируй его
   здесь как отдельную задачу (тест + фикс + релиз), а не как обход в стороннем проекте. Найденные интеграторами
   расхождения ядра: v1.1.1 — CRLF, v1.1.2 — base64, v1.1.3 — raw `vcf_info`; схема — `docs/api-reference.md` ядра,
   раздел 9.
8. **Веди `.agents`** (раздел 4.1): после каждой содержательной сессии обнови `journals/JOURNAL.md` и добавь файл
   сессии; многошаговые задачи фиксируй в `plans/`; правки релиза — в `release/`.
9. **Язык — русский.** Все md-файлы, комментарии в коде, описания, планы и журнал пиши по-русски, информативно, без
   смешения языков и без декоративных артефактов (значков, условных обозначений, символов непонятного происхождения).
   Допустимы только русский и английский. Идентификаторы в коде, имена API-полей и термины спеки остаются как есть.

## 4. Структура репозитория (целевая)

```
config/laravel-max-client.php      publishable-конфиг (echo php artisan vendor:publish)
database/migrations/               publishable-миграции (max_chats; vendor:publish --tag=laravel-max-client-migrations)
examples/                          рабочие примеры (фасад, webhook-listener, PSR-18, webapp, long-polling)
src/
  MaxServiceProvider.php           composition root: publish, bindings, регистрация роута/фасада/алиасов middleware
  Console/MaxListenCommand.php     artisan max:listen: Long Polling для локальной разработки (--once)
  Console/MaxSubscribeCommand.php  artisan max:subscribe: регистрация webhook-подписки (HTTPS + allowed_hosts)
  Console/MaxUnsubscribeCommand.php artisan max:unsubscribe: удаление webhook-подписки
  WebApp/WebAppContext.php         верификация WebAppData мини-приложения (resolve/verify из Request и из строки)
  WebApp/ResolveWebAppIdentity.php middleware max.webapp: сессия user_id/chat_id + strict (403)
  Enums/MaxChatStatus.php        статусы реестра чатов (active/stopped/removed + label())
  Models/MaxChat.php               модель реестра чатов max_chats (переопределяемая через chats.model)
  Listeners/PersistMaxChatListener.php upsert max_chats по bot_added/bot_started/bot_stopped/bot_removed
  Services/MaxUserProfileService.php  заполнение max_users (аватар/профиль) через getChatMembers
  Http/HttpClientFactory.php       SRP: сборка PSR-18/17 клиентов (Guzzle по умолчанию)
  Http/Middleware/SetMaxFrameAncestors.php middleware max.csp: frame-ancestors для встраивания в MAX
  Http/Middleware/LogMaxRequestsMiddleware.php middleware max.log: лог request/response
  Facades/Max.php                  фасад поверх ApiClient (из контейнера)
  Contracts/MaxClient.php          интерфейс-фасад-прокси (необязательный, решает KISS при реализации)
  Support/Config.php               stateless readonly-доступ к config('max.*') (live-read из репозитория)
  Support/Logger.php               резолвер канала логирования (channel→fallback→stack, no-op при выключенном)
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
Dockerfile                         PHP 8.4 + опциональный Xdebug (ARG INSTALL_XDEBUG=false); COPY docker/config
docker-compose.yml                 сервис app, user 1000:1000, volume ./ , .env пробрасывается
.dockerignore                      секреты (.env) и тяжёлые каталоги (.git, vendor, build, кэши) вне build context
docker/config/usr/local/etc/php/conf.d/40-custom.ini  PHP-конфиг dev-контейнера (memory_limit=1G)
composer.json                      PSR-4 GeekCo\LaravelMaxClient\, PHP ^8.4
phpunit.xml                        failOnRisky/failOnWarning; группа integration исключена по умолчанию
phpstan.neon                       level max
.php-cs-fixer.dist.php             PSR-12
.env.example                       эталон имён переменных (MAX_*)
scripts/check-coverage.php         порог покрытия строк (по умолчанию 95%)
```

`composer.lock`, `.phpunit.cache/`, `build/`, `vendor/`, `.agents/` — в `.gitignore` (для библиотеки lock не коммитится;
`.agents/` — локальная рабочая память, наружу не отдаётся).

### 4.1 Рабочие каталоги `.agents` и документация

`.agents/` — **локальный** каталог (в `.gitignore`): журнал сессий, планы, протокол обновления ядра и описания релизов.
Он не попадает в репозиторий и в дистрибутив Packagist, поэтому туда не кладут то, что должно быть публичным: для
внешних потребителей истина — `README.md`, `docs/api-reference.md` ядра и описания в GitHub Release.

| Каталог               | Содержимое                                                                                     |
|-----------------------|------------------------------------------------------------------------------------------------|
| `journals/JOURNAL.md` | Карта сессий: дата · файл · теги · краткое описание                                            |
| `journals/sessions/`  | Файлы сессий `YYYY-MM-DD-тема.md`: frontmatter с тегами, тело ≤5 КБ                            |
| `plans/`              | Планы многошаговых задач `YYYY-MM-DD-тема.md`, статус: `в работе` или `завершён`; не удаляются |
| `release/`            | `RELEASE_NOTES_vX.Y.Z.md` — описание каждой версии                                             |
| `upgrade/`            | `UPGRADE_PLAN.md` — протокол обновления адаптера под новые версии ядра                         |

**Правила ведения**

- **Файл сессии** — компактный отчёт: frontmatter (`tags`, `date`), затем секции `Проблема` / `Решение` / `Тесты` /
  `Нюансы` / `Gate`. Обязательная строка о Gate: что именно прогналось и с каким результатом. Секреты, токены,
  `vcf_info`, payload колбэков и прод-ответы в журнал не пишутся.
- **`JOURNAL.md`** — одна строка на сессию, самые новые сверху; формат строки: `дата · файл · теги · описание`.
- **План** — для задач из трёх и более шагов или требующих исследования (например, синхронизация со спекой): цель,
  исследование, реализация, тесты, нюансы, статус. Готовый план не удаляется, а помечается завершённым.
- **Release notes** — пишутся в `.agents/release/RELEASE_NOTES_vX.Y.Z.md` при выпуске версии; значимые пункты
  дублируются в README (раздел «История изменений») и в GitHub Release.
- Если правка изменила поведение публичного API или контракт с интеграторами — обнови `README.md` в той же сессии.

## 5. Архитектура и ключевые контракты

### Слои

| Слой          | Классы                                                                 | Назначение                                                          |
|---------------|------------------------------------------------------------------------|---------------------------------------------------------------------|
| Composition   | `MaxServiceProvider`, `HttpClientFactory`, `Support\Config`            | Сборка зависимостей из контейнера Laravel, конфиг                   |
| Logging       | `Http\Middleware\LogMaxRequestsMiddleware`, `Support\Logger`           | Лог запросов/ответов и апдейтов (config-gated, канал с fallback)    |
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
    - `RetryStrategy`, `RateLimiter` (per-chat и глобальный) — из конфига (`retry.*`, `rate_limit.*`,
      `global_rate_limit.*`), при пустых значениях — дефолты ядра.
- **Facade `Max`**: резолвит `ApiClient` из контейнера, PHPDoc `@method` покрывает всё API ядра (синхронизировать с
  `ApiClient` при обновлении ядра; расхождение ловит
  `MaxFacadeTest::testFacadeDocumentsEveryPublicApiClientMethodWithItsReturnType`). Пример:
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
    - `resolveData(string): ?WebAppIdentity` — верифицирует строку WebAppData и возвращает идентичность (user/chat)
      либо `null` при невалидных/просроченных данных. **Основной путь**: MAX открывает мини-приложение по URL с
      `#WebAppData=...` в **фрагменте**, который до сервера не доходит, — фронт передаёт строку (например, заголовком
      `X-Max-WebApp-Data`), сервер вызывает этот метод. Это **обязательная** проверка — без неё любой может подделать
      `user_id`/`chat_id`;
    - `verifyData(string): bool` — булева проверка строки без резолва identity;
    - `resolve(Request): ?WebAppIdentity` — `?WebAppData=...` из query (фолбэк/dev-путь), делегирует `resolveData()`;
    - `verify(Request): bool` — булева проверка из Request, делегирует `verifyData()`. Значение `auth_date` сверяется с
      `webapp.max_age` (env `MAX_WEBAPP_MAX_AGE`, default 86400; `0` — не проверять).
- **`ResolveWebAppIdentity`** (алиас `max.webapp`): middleware верифицирует WebAppData через `WebAppContext`, кладёт
  `user_id`/`chat_id` в сессию (ключи `webapp.session.*`, env `MAX_WEBAPP_SESSION_*`) и в атрибут запроса
  `ResolveWebAppIdentity::REQUEST_ATTRIBUTE`; при `webapp.strict` (`MAX_WEBAPP_STRICT`) и отсутствии/невалидности
  данных —
  `403`. Регистрация алиаса — в `MaxServiceProvider::boot()`. Демо-режим — ответственность приложения (вьюха видит
  `null`).
- **`SetMaxFrameAncestors`** (алиас `max.csp`): middleware добавляет в `Content-Security-Policy` директиву
  `frame-ancestors 'self' <hosts>` (хосты из `webapp.frame_ancestors.hosts`, env `MAX_WEBAPP_FRAME_ANCESTORS`, default
  `max.ru`/`web.max.ru`); если CSP-заголовок уже задан — дописывает. Отключение — `webapp.frame_ancestors.enabled`
  (`MAX_WEBAPP_CSP_ENABLED`).
- **`LogMaxRequestsMiddleware`** (алиас `max.log`): лог request (`Incoming MAX request`) и response (`MAX response`,
  `duration_ms`), уровни 2xx→info / 4xx→warning / 5xx→error. Включение — `logging.enabled`
  (`MAX_LOGGING_ENABLED`, default `false`). При включении провайдер автоматически подключает middleware к роуту вебхука
  **перед** `VerifyMaxWebhookSecret`; для остальных роутов — алиас в middleware роута. Тело — только при
  `logging.log_request_body`/`logging.log_response_body` (`MAX_LOGGING_LOG_*_BODY`, default `false`, A09), секретные
  ключи маскируются рекурсивно (`***`). Канал — `logging.channel` → `logging.fallback_channel` → `stack` (резолвер
  `Support\Logger`, no-op при выключенном). `exclude_paths` (полный пропуск), `exclude_request_body_paths` /
  `exclude_response_body_paths` (без тела); `X-Request-ID` проксируется в ответ. `HandleMaxUpdateJob` логирует
  start/finish/failed (context: `update_type`, `user_id`, `chat_id`).
- **Реестр чатов** (`max_chats`): реализация документированной практики MAX (getChats deprecated — chat_id хранить через
  `bot_added`/`bot_started`). Publishable-миграция, модель `Models\MaxChat` (переопределяемая `chats.model`,
  `MAX_CHATS_MODEL`), enum `Enums\MaxChatStatus`, слушатель `Listeners\PersistMaxChatListener` (upsert по
  `bot_added`/`bot_started`/`bot_stopped`/`bot_removed`, пропуск при `chat_id=null`; апдейты с `message`/`comment`/
  `callback` статус не меняют, но дозаполняют `chat_type` из `Recipient::chatType`). Включается `chats.enabled`
  (`MAX_CHATS_ENABLED`); регистрация слушателя на `MaxUpdateReceived` — в `MaxServiceProvider::boot()`. Это
  инфраструктура — бизнес-обработка остаётся в приложении.
- **Профиль пользователя** (`Services\MaxUserProfileService`): наполнение `max_users` полноценным профилем (аватар
  `avatar_url`/`full_avatar_url`, `name`, `description`) через ядро `getChatMembers` (в апдейтах аватар не приходит).
  Singleton в контейнере (зависимости `ApiClient`, `Config`, `Logger`). API: `refresh(int|list<int>): bool`
  (chat_id из активных `max_chats`, батчинг userIds по `users.profile_batch_size`, флаг
  `users.profile_from_active_chats` отключает резолв из реестра), `upsertFromMember(ChatMember): MaxUser`
  (`updateOrCreate`, пишет `profile_checked_at`), `ensureAvatar(MaxUser, ?int $chatId = null): bool` (пропуск при уже
  заполненном аватаре; при `users.profile_check_interval` > 0 — периодическая перепроверка по `profile_checked_at` в
  секундах (по умолчанию 86400 — раз в сутки); явный `chatId` работает без реестра). «Когда вызывать» — ответственность
  приложения.
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

| Принцип              | Применение к этому пакету                                                                                                           |
|----------------------|-------------------------------------------------------------------------------------------------------------------------------------|
| **SOLID**            | Один класс — одна ответственность; композиция через контейнер Laravel и сервис-контракты ядра                                       |
| **DRY**              | Не дублировать методы ядра — только делегирование; API-факты берутся из `docs/api-reference.md` ядра, а не копируются               |
| **KISS**             | Никаких собственных DI-контейнеров и магических абстракций; фасад и middleware как точки входа                                      |
| **TDD**              | Новый компонент сначала покрывается unit-тестом; HTTP-слой — через `tests/Support/MockHttpClient` (PSR-18) или подмену в контейнере |
| **BC-совместимость** | Публичный API пакета: в patch-релизе не удалять и не менять сигнатуры; новое — через необязательные параметры и конфиг с дефолтом   |
| **Production-grade** | Gate (раздел 7), покрытие ≥95%, fail-closed на секретах, никаких глобальных состояний                                               |

- PHP **8.4**, `declare(strict_types=1)` во всех файлах, PSR-12, PHPStan **level max**.
- Namespace `GeekCo\LaravelMaxClient` (тесты `GeekCo\LaravelMaxClient\Tests`), PSR-4.
- Не добавлять комментарии без необходимости.
- Тесты обязательны для нового кода: unit на компоненты пакета; HTTP-слой — через `tests/Support/MockHttpClient`
  (PSR-18) или подмену `ClientInterface` в контейнере Testbench. Интеграционные — read-only, группа `integration`, без
  токена `markTestSkipped` (не падать).
- `composer.json` constraints на момент разработки: `php ^8.4`, `geekcodev/max-php-client ^1.1.6` (комментарии и
  разметка в ответах, 19 типов обновлений, DTO `Chat` с `dialog_with_user` — с v1.1.0 ядра; `WebAppDataValidator`
  появился в v1.0.1; `Update::$user` nullable и фолбэки `user`/`chat_id` — с v1.0.3; `ApiClient::create()` с
  `global_rate_limiter`, deprecated-права админов и пустое тело `sendAnswer` — с v1.0.6; Comments API (`getComments`/
  `sendComment`/`editComment`/`deleteComment`/`getComment`), `getUpdatesBatch`, `UploadResult` →
  `UploadedInfo`, `sendAnswer` с `notification`/`disableLinkPreview`, `Update::$comment` и события комментариев — с
  v1.1.6 — версия ниже не резолвит актуальные сигнатуры), `laravel/framework ^12.0|^13.0`, `guzzlehttp/guzzle ^7.15`
  (обязателен как PSR-18 по умолчанию), `illuminate/support`/`illuminate/queue`/`illuminate/routing` — через
  `laravel/framework`; dev — Testbench под поддерживаемую версию Laravel, phpunit ^11.5, phpstan ^2.0,
  friendsofphp/php-cs-fixer ^3.0. Точные версии Testbench сверить с совместимостью Laravel на момент реализации, версии
  ядра — динамически (раздел 1).

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
- **A06/A08** — актуальные зависимости: PHP ^8.4, ядро `^1.1.6` (фактическая версия — динамически, раздел 1),
  `composer audit` в Gate и CI; CI на push/PR.
- **A07** — все сравнения секретов — только `hash_equals` (ядро + middleware вебхука).
- **A09** — не логировать: access token, webhook secret, `vcf_info`, callback payload, тела запросов с токеном.
  Логировать статус/код/сообщение ошибки API (в коде ядра сообщения не содержат токенов). Тела логируются только при
  явном включении (`logging.log_request_body`/`logging.log_response_body`), секретные ключи маскируются (`***`).
- **A10** — URL подписок/загрузки только `https://` (ядро); при необходимости — allow-list хостов в конфиге
  (`webhook.allowed_hosts`), валидация домена до создания подписки.

## 6. Ключевые факты API MAX (источник — `docs/api-reference.md` ядра / `max-openapi`)

Здесь только факты, влияющие на адаптер. Полный справочник (спека, эндпоинты, enums, DTO, лимиты, вебхуки и раздел 9
«что делать при расхождении спека vs реальный API») — в `max-php-client/docs/api-reference.md`; дублировать его
содержимое здесь не нужно.

- Аутентификация: `Authorization: <access_token>` без `Bearer`; query-передача токена не поддерживается.

- Сервер: `https://platform-api2.max.ru` (домен **`platform-api2`**).
- Нужна цепочка сертификатов Минцифры (в локальных средах — кастомный CA).
- Вебхуки — только HTTPS :443, доверенный CA, полная цепочка; секрет 5–256 символов `[a-zA-Z0-9_-]`; ответ обязателен
  200 за 30 сек, иначе повторы 60с→150с→375с→… (10 попыток ~8ч), затем автоотписка.
- Активная webhook-подписка отключает Long Polling. Long Polling — только для разработки/тестов.
- Rate limits: отправка/редактирование/удаление сообщений и ответы на callback — **макс. 2/сек** на диалог/чат/канал
  (ядро: `RateLimiter`, token bucket); плюс **глобальный предохранитель 30 req/s** на весь API (применяется к каждому
  запросу, включая ретраи и загрузку медиа; при исчерпании ядро ждёт пополнения, а не бросает исключение).
- `sendAnswer()` без `message` шлёт тело `{}` (requestBody обязателен) — ответ на callback без обновления сообщения;
  deprecated-права админов (`post_edit_delete_message`/`edit_message`/`delete_message`) API возвращает, но выдавать
  нельзя (`addChatAdmin` кидает `InvalidArgumentException`).
- Загрузка медиа: `POST /uploads` с `type` в query; после загрузки **ждать** готовности вложения —
  `attachment.not.ready` ретраится автоматически. Домены загрузки: `https://fu.oneme.ru`,
  `https://iu.oneme.ru`, `https://vu.okcdn.ru`.
- `GET /chats` **deprecated** — хранить `chat_id` через подписку на `bot_added`/`bot_started`.
- `addChatMembers` **deprecated** — добавление участников недоступно ботам.
- Комментарии к постам в каналах (ядро с v1.1.6): `getComments`/`getComment`/`sendComment`/`editComment`/
  `deleteComment`, апдейты `comment_created`/`comment_edited`/`comment_removed` приходят в `Update::$comment`
  (DTO `CommentMessage`: `recipient` c `chat_id`/`chat_type`/`post_id`, `body` с `markup`). Права бота:
  `read_all_messages`; токен — админ канала с этими правами.
- `getUpdatesBatch` — батчевое получение апдейтов (`UpdatesResult`), полный набор 19 `UpdateType` включает
  `bot_admin_permissions_changed` и события комментариев.
- `type=photo` deprecated → `type=image`.
- Timestamp — Unix в **миллисекундах**, включая `join_time` (ядро: `Dto/ChatMember::$joinTime`).
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
- Ключевые детали workflow: `-e COMPOSER_ROOT_VERSION=dev-main` во всех шагах (обход отсутствия git-метаданных в
  volume), `-e XDEBUG_MODE=coverage` для генерации отчёта, кэш `vendor` по `composer.json`.
- **Релиз**: описание версии в `.agents/release/RELEASE_NOTES_vX.Y.Z.md` → merge PR `dev → main` →
  `git tag vX.Y.Z && git push origin vX.Y.Z` → GitHub Release из тега → Packagist (автообновление по webhook). Значимые
  пункты релиза продублировать в `README.md` (раздел «История изменений»).
- **Формат release-notes**: `Новое` / `Изменение (BC)` / `Затронутые сценарии` / `Качество` (тесты, покрытие, аудит).
  Файл не удаляется после релиза — вся история в `.agents/release/`, значимое в README и в GitHub Release.

## 9. Частые ошибки (gotchas)

1. `WebhookHandler::decode()` возвращает `Update|list<Update>` — **не** итерировать без `instanceof`-проверки.
2. Токен — без `Bearer`; только заголовок, не query (гарантирует ядро — не пробрасывать в query вручную).
3. `attachment.not.ready` — вложение ещё не готово: ядро ретраит само, не дублировать ретрай на уровне пакета.
4. `join_time` — **миллисекунды** (все timestamp API — Unix в миллисекундах).
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
16. Версии ядра в этом файле — ориентир, не источник истины: проверяй `composer show geekcodev/max-php-client` и теги
    ядра, иначе легко сослаться на устаревший constraint.
17. После содержательной сессии — файл в `.agents/journals/sessions/` и строка в `JOURNAL.md` (раздел 4.1), иначе
    решения и результаты Gate теряются вместе с локальным каталогом.

## 10. Чек-лист «production-grade» (самооценка при доработках)

- [ ] CI зелёный: lint 0, phpstan 0, phpunit зелёные, покрытие ≥95%, `composer audit` чист.
- [ ] Новый код покрыт unit-тестами (HTTP-слой — через MockHttpClient / подмену в контейнере).
- [ ] Публичный API не сломан: сигнатуры в patch-релизе не менялись.
- [ ] Секретов нет в коде, логах, коммитах; конфиг publishable с безопасными дефолтами.
- [ ] Входные данные валидируются (вебхук, middleware, параметры запросов).
- [ ] Вебхук-обработка асинхронная; 200 в окне 30с; fail-closed без секрета.
- [ ] Документация (README, .env.example, AGENTS.md) синхронна с реальным поведением кода и версиями ядра.
- [ ] Обновлены `.agents/journals/`, при необходимости — `.agents/plans/` и `.agents/release/`.
- [ ] Релиз оформлен: merge в main → тег → GitHub Release → Packagist.
