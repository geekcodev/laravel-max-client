# geekcodev/laravel-max-client

Тонкий Laravel-адаптер для **MAX Messenger Bot API** поверх framework-agnostic ядра
[`geekcodev/max-php-client`](https://github.com/geekcodev/max-php-client).

Пакет отвечает только за «Laravel-клей»: конфиг, DI, фасад, вебхук-роутинг, очередь. Вся бизнес-логика API (DTO,
эндпоинты, ретраи, rate limit, безопасность, загрузка медиа) живёт в ядре — см. его документацию и OpenAPI-спецификацию
`max-openapi`.

## Требования

- PHP ^8.4
- Laravel ^12.0|^13.0
- `geekcodev/max-php-client` ^1.1.6 (Comments API, `getUpdatesBatch`, `UploadedInfo`)

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

| Переменная                                 | По умолчанию                                 | Описание                                                                                                                                                        |
|--------------------------------------------|----------------------------------------------|-----------------------------------------------------------------------------------------------------------------------------------------------------------------|
| `MAX_API_TOKEN`                            | —                                            | Токен бота (заголовок `Authorization`)                                                                                                                          |
| `MAX_BASE_URI`                             | `https://platform-api2.max.ru`               | Базовый URI API (домен `platform-api2`)                                                                                                                         |
| `MAX_WEBHOOK_ENABLED`                      | `false`                                      | Регистрировать вебхук-роут                                                                                                                                      |
| `MAX_WEBHOOK_SECRET`                       | —                                            | Секрет вебхука (без него роут не включается)                                                                                                                    |
| `MAX_WEBHOOK_QUEUE`                        | `default`                                    | Очередь для джобов обработки Update                                                                                                                             |
| `MAX_WEBHOOK_PATH`                         | `/max/webhook`                               | Путь вебхук-роута                                                                                                                                               |
| `MAX_RETRY_*`                              | 3 / 1 / 30 / 2 / false                       | Ретраи (попытки/базовая/макс. задержка/фактор/не-идемпотентные)                                                                                                 |
| `MAX_RATE_LIMIT_*`                         | 2.0 / 2.0                                    | Token bucket на диалог/чат/канал: токенов в секунду / максимум                                                                                                  |
| `MAX_GLOBAL_RATE_LIMIT_*`                  | 30.0 / 30.0                                  | Глобальный token bucket на весь API (ожидание, не ошибка)                                                                                                       |
| `MAX_WEBAPP_MAX_AGE`                       | `86400`                                      | Срок жизни `auth_date` мини-приложения, сек (0 — не проверять)                                                                                                  |
| `MAX_WEBAPP_STRICT`                        | `false`                                      | `max.webapp` возвращает 403 без валидного WebAppData                                                                                                            |
| `MAX_WEBAPP_SESSION_USER_ID`               | `user_id`                                    | Ключ сессии для user_id (middleware `max.webapp`)                                                                                                               |
| `MAX_WEBAPP_SESSION_CHAT_ID`               | `chat_id`                                    | Ключ сессии для chat_id (middleware `max.webapp`)                                                                                                               |
| `MAX_WEBAPP_CSP_ENABLED`                   | `true`                                       | Добавлять `frame-ancestors` в CSP (middleware `max.csp`)                                                                                                        |
| `MAX_WEBAPP_FRAME_ANCESTORS`               | `https://max.ru,https://web.max.ru`          | Хосты, которым разрешено встраивать мини-приложение (через запятую)                                                                                             |
| `MAX_CHATS_ENABLED`                        | `false`                                      | Включает реестр чатов `max_chats` (слушатель `PersistMaxChatListener`)                                                                                          |
| `MAX_CHATS_MODEL`                          | `GeekCo\LaravelMaxClient\Models\MaxChat`     | Модель реестра чатов (для переопределения)                                                                                                                      |
| `MAX_CHAT_USERS_MODEL`                     | `GeekCo\LaravelMaxClient\Models\MaxChatUser` | Модель связей чата с пользователями (для переопределения)                                                                                                       |
| `MAX_CHATS_FETCH_METADATA`                 | `true`                                       | Дозаполнять метаданные чата через `getChat`, пока название неизвестно                                                                                           |
| `MAX_CHATS_CHAT_CHECK_INTERVAL`            | `0`                                          | Периодичность перепроверки метаданных для `max:chats:refresh`, сек (0 — всегда); прежнее имя `MAX_CHATS_TITLE_CHECK_INTERVAL` учитывается, пока не задано новое |
| `MAX_USERS_MODEL`                          | `GeekCo\LaravelMaxClient\Models\MaxUser`     | Модель реестра пользователей (для переопределения)                                                                                                              |
| `MAX_USERS_PROFILE_FROM_ACTIVE_CHATS`      | `true`                                       | `MaxUserProfileService`: резолвить chat_id из активных `max_chats`                                                                                              |
| `MAX_USERS_PROFILE_BATCH_SIZE`             | `50`                                         | Лимит userIds на один вызов `getChatMembers` (батчинг)                                                                                                          |
| `MAX_USERS_PROFILE_CHECK_INTERVAL`         | `86400`                                      | Периодичность перепроверки профиля в `ensureAvatar`, сек (0 — только при пустом аватаре)                                                                        |
| `MAX_LOGGING_ENABLED`                      | `false`                                      | Включает логирование (middleware `max.log`)                                                                                                                     |
| `MAX_LOGGING_CHANNEL`                      | `stack`                                      | Канал Laravel для логов                                                                                                                                         |
| `MAX_LOGGING_FALLBACK_CHANNEL`             | `laravel-max-client`                         | Запасной канал, если основной не определён                                                                                                                      |
| `MAX_LOGGING_LOG_REQUEST_BODY`             | `false`                                      | Логировать тело запроса (секреты маскируются)                                                                                                                   |
| `MAX_LOGGING_LOG_RESPONSE_BODY`            | `false`                                      | Логировать тело ответа                                                                                                                                          |
| `MAX_LOGGING_LOG_RESPONSE_BODY_MAX_LENGTH` | `1000`                                       | Макс. длина не-JSON тела ответа в логе                                                                                                                          |

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

## Реестр чатов (max_chats)

Реализация документированной практики MAX: `getChats` deprecated, `chat_id` хранить через подписку на
`bot_added`/`bot_started`. Пакет даёт готовые модели, миграции и слушателя, обновляющего реестр по апдейтам
`bot_added`/`bot_started`/`bot_stopped`/`bot_removed`.

Схема реестра (начиная с v1.2.0):

| Таблица          | Одна строка на            | Содержит                                                           |
|------------------|---------------------------|--------------------------------------------------------------------|
| `max_chats`      | чат                       | `status`, `chat_type`, метаданные из `getChat`, `last_activity_at` |
| `max_chat_users` | пару «чат + пользователь» | `status` и `last_activity_at` взаимодействия пользователя с ботом  |
| `max_users`      | пользователя              | профиль, телефон, аватар                                           |

`max_chat_users` — это реестр зафиксированных взаимодействий с ботом, а не полный состав группы или канала: строки
появляются только из апдейтов, в которых пришёл конкретный `user_id`.

Первичный ключ строки `max_chats` — сам `chat_id` из MAX: строка реестра одна на чат, суррогатного счётчика нет. Колонки
идентификаторов (`chat_id`, `user_id`) объявлены знаковым `BIGINT`: в MAX это `int64`, и у групп и каналов
`chat_id` отрицательный (например, `-79032376695376`). `unsigned`-колонка такой идентификатор не сохранила бы. У
`max_chat_users` и `max_users` первичные ключи натуральные (`chat_id` + `user_id` в паре против `user_id`), а
собственный суррогатный `id` есть только у строки связи `max_chat_users` — `uuid` (uuid7, значение выдаёт модель через
`HasUuids`), потому что идентификаторов от MAX у неё нет. Обращаться к реестрам стоит через модели `MaxChat` и
`MaxChatUser`; голый `DB::table('max_chat_users')->insert()` без `id` не сработает — колонка не имеет значения по
умолчанию на стороне базы.

События `bot_*` — это события о боте в чате, а не о пользователе, поэтому `user` в них может не прийти. Состояние чата
при этом известно: слушатель обновляет строку чата по `chat_id` — `status`, `last_activity_at`, `chat_type` — даже если
пользователя в апдейте не было. Благодаря этому `bot_removed` без пользователя больше не теряется и чат не остаётся
`active` навсегда. Связь с пользователем при этом не создаётся: без `user_id` неизвестно, о ком речь.

1. Выполните миграцию:

   ```bash
   php artisan migrate
   ```

   Миграции пакета подгружаются провайдером из каталога `database/migrations` — публиковать их не нужно. Чистая
   установка на этом шаге и заканчивается: таблицы создаются в нужной форме. При обновлении проекта, который стоял на
   v1.1.x, дополнительно выполните `php artisan max:upgrade` (см. ниже).

2. Включите реестр:

   ```dotenv
   MAX_CHATS_ENABLED=true
   ```

Пакет регистрирует `PersistMaxChatListener` на событие `MaxUpdateReceived` (таблицы `max_chats` и `max_chat_users`,
статусы `active`/`stopped`/`removed`). Модели можно переопределить через `MAX_CHATS_MODEL` и `MAX_CHAT_USERS_MODEL`
(классы-наследники `GeekCo\LaravelMaxClient\Models\MaxChat` и `GeekCo\LaravelMaxClient\Models\MaxChatUser`).

Связи и подсказка имени чата:

```php
$chat->maxUsers();    // HasManyThrough<MaxUser> — пользователи, чьи апдейты бот получал в чате
$chat->chatUsers();   // HasMany<MaxChatUser> — строки связей с их статусом и активностью
$user->maxChats();    // HasManyThrough<MaxChat> — чаты пользователя (сохранено с v1.1.x)
$user->chatLinks();   // HasMany<MaxChatUser>
$chat->displayName(); // title, а при пустом title — имя пользователя
```

В выборках по связям указывайте таблицу явно: колонка `user_id` есть и в `max_users`, и в `max_chat_users`.

```php
$chat->maxUsers()->pluck('max_users.user_id');
```

Связи нет у пользователя, если апдейт пришёл без объекта `user` — только с идентификатором.

### Метаданные чата (название, описание, иконка)

Название группы или канала приходит **только** из `getChat()`: апдейты `bot_added`/`bot_started` содержат лишь
`chat_id`, `user` и `is_channel`, а само название приходит отдельным событием `chat_title_changed`.
`MaxChatProfileService` (singleton) дозаполняет метаданные при регистрации чата, а слушатель пишет `title`
из `chat_title_changed` в строку чата — без запроса к API.

Пустой ответ не затирает уже известное значение: публичный канал без названия — обычное дело. В реестре строка чата
одна, поэтому `getChat` вызывается один раз на чат независимо от числа участников: на 50 участников приходится один
запрос, а не пятьдесят.

```dotenv
MAX_CHATS_FETCH_METADATA=true      # дозаполнять метаданные при bot_added/bot_started
MAX_CHATS_CHAT_CHECK_INTERVAL=0     # периодическая перепроверка для max:chats:refresh, сек (0 — всегда)
```

Дозаполнить записи, созданные до появления названия, и перепроверять его по расписанию:

```bash
php artisan max:chats:refresh                 # все активные чаты реестра
php artisan max:chats:refresh --chat=123      # конкретные chat_id (через запятую или повторно)
php artisan max:chats:refresh --all           # то же, что без флагов
```

При `MAX_CHATS_CHAT_CHECK_INTERVAL` чаты с более свежим `chat_checked_at` пропускаются — команда периодически
переспрашивает только устаревшее. Команда печатает, сколько чатов к проверке и сколько пропущено, чтобы из расписания
было видно, был ли запрос вообще. Прежние имена остаются рабочими, пока не задано новое: переменная
`MAX_CHATS_TITLE_CHECK_INTERVAL`
(действовала в v1.1.4) и ключ `chats.title_check_interval` в конфиге, опубликованном до v1.2.0, — заданный ранее
интервал не теряется. Явный `0` в новом имени, наоборот, важнее прежнего: им переопределяют старый интервал (пустая
переменная — это «не задано», а не ноль).

Для диалогов поведение не меняется: `chat_type = dialog`, название не запрашивается. Название для UI удобно брать через
`MaxChat::displayName()` — это `title`, а при пустом `title` имя собеседника.

### Обновление после обновления пакета

Изменения схемы приезжают **отдельными аддитивными миграциями**, поэтому после обновления пакета достаточно обычного
`php artisan migrate` — он увидит ещё не выполненные миграции. Перенос данных на новую форму реестра чатов выполняется
командой `php artisan max:upgrade`. Уже выполненные миграции пакета никогда не переписываются:
если бы `create_max_chats_table` изменили на месте, у установленных проектов он числился бы выполненным, и `migrate`
молча ничего не сделал бы.

### Переход на структуру «одна строка на чат» (v1.2.0)

До v1.2.0 в `max_chats` лежала строка на пару «пользователь + чат» с уникальным ключом `(user_id, chat_id)`. В v1.2.0
`max_chats` хранит одну строку на чат, а связи вынесены в `max_chat_users`.

Обновление установленного проекта — два шага:

```bash
php artisan migrate        # создаёт таблицу max_chat_users
php artisan max:upgrade    # переносит данные на новую структуру
```

Перенос данных сделан консольной командой, а не миграцией: перед изменением схемы можно посмотреть объём
(`php artisan max:upgrade --dry-run`), а результат — вернуть обратно (`--rollback`). Команда идемпотентна, на уже новой
структуре ничего не делает.

Что делает команда:

- пары «чат + пользователь» переносятся в `max_chat_users` с сохранением статуса и `last_activity_at`;
- дубли одного чата сливаются в одну строку: метаданные берутся из первых непустых, `last_activity_at` — максимум,
  `created_at` — минимум, статус определяется по приоритету `removed > stopped > active`;
- после этого `user_id` и суррогатный `id` удаляются, а `chat_id` становится первичным ключом `max_chats`;
- строки связей в `max_chat_users` получают суррогатный `id` типа `uuid`;
- `max_chats.title_checked_at` переименовывается в `chat_checked_at` с сохранением значения времени;
- `max_users.user_id` расширяется до знакового `BIGINT`;
- `max_users.phone_verified_at` возвращается, если колонки нет: её создавала аддитивная миграция v1.1.4, поэтому у
  установки v1.1.3 её нет, а `PersistMaxUserPhoneListener` пишет отметку безусловно.

Переносится только то, что есть в текущей форме таблицы, поэтому посторонние колонки прежней схемы пересборка
отбрасывает. Установка v1.1.3 и старше аддитивных миграций метаданных не получала: там нет ни колонок метаданных, ни
отметки проверки, и после обновления такие чаты один раз попадут в очередь `max:chats:refresh`.

Обновление заодно исправляет знаковость идентификаторов: до v1.2.0 `chat_id` и `user_id` объявлялись как `unsigned`, и
на MySQL отрицательный идентификатор в реестры не попадал. `chat_id` становится знаковым вместе с пересборкой, а
`max_users` расширяется тем же запуском — отдельной миграции ради смены типа нет. При откате колонка сужается обратно
только если в базе нет отрицательных значений.

**Пока команда не выполнена, схема неполна.** Между `migrate` и `max:upgrade` таблица `max_chats` ещё в прежней форме, а
модель `MaxChat` уже ждёт новую — до завершения обновления бот работать не должен.

Смена первичного ключа выполняется пересборкой таблицы (новая временная таблица в целевой форме, копирование строк одним
`insert … select`, переименование), а не `alter`: сменить первичный ключ на месте нельзя ни на одной поддерживаемой
СУБД — SQLite отклоняет удаление колонки ключа, MySQL требует отдельных команд. Индексы пересобранной таблицы
переименовываются в канонические имена.

Откат (`php artisan max:upgrade --rollback`) возвращает прежнюю форму целиком: `user_id`, строку на каждую пару и
суррогатный счётчик `id`. Пересборка работает в обе стороны, поэтому значения прежних ключей не восстанавливаются как
числа (они не сохранялись), но сама форма таблицы возвращается, и повторный запуск команды снова приводит реестр к новой
форме. `user_id` при откате становится nullable: чат без зафиксированных пар получает одну строку с `NULL`, терять сам
факт чата хуже, чем падать на откате.

### Если миграции пакета публиковались раньше

До v1.1.4 миграции нужно было копировать вручную: `php artisan vendor:publish --tag=laravel-max-client-migrations`.
Публикация убрана, миграции подгружаются провайдером, но **копии, уже лежащие в приложении, никуда не делись и
продолжают перекрывать файлы пакета**. Причина: `Migrator::getMigrationFiles()` собирает файлы через `keyBy()` по имени
миграции, а пути пакета идут раньше пути приложения, поэтому последнее вхождение имени — это файл из
`database/migrations`. Правка пакета до такого потребителя не доходит молча.

Проверьте наличие копий:

```bash
ls database/migrations/0001_01_01_00000{1,2}_create_max_{users,chats}_table.php
```

Если файлы есть, удалите их:

```bash
rm database/migrations/0001_01_01_000001_create_max_users_table.php
rm database/migrations/0001_01_01_000002_create_max_chats_table.php
php artisan migrate
```

Строки в таблице `migrations` удалять не нужно. Файлы пакета сейчас называются `0000_00_000001_create_max_users_table`
и `0000_00_000002_create_max_chats_table`, поэтому после удаления копий они числятся невыполненными и отработают
повторно при уже существующих таблицах. Это безопасно: в `up()` каждой create-миграции стоит
`Schema::hasTable()` с выходом по `return`. Строки со старыми именами останутся в таблице `migrations` как
неиспользуемые — на работу они не влияют, но при желании их можно удалить вручную.

Если копии не только первых двух, но и последующих миграций (`add_phone_verified_at`, `add_chat_id_index`,
`add_chat_metadata`), удалите их тоже: они больше не поставляются пакетом и остались бы навсегда в базе проекта.
Отсутствие файла при уже выполненной миграции не мешает — повторно она не запустится.

### Полоса миграций и порядок установки

Laravel сортирует миграции приложения и всех пакетов по имени файла и применяет сверху вниз, поэтому внешний ключ
допустим только на таблицу, чья миграция имеет меньшее имя. Полоса в имени миграции — это позиция пакета в общем
порядке, а не отдельный именованный диапазон. Раскладка полос стека i2tech:

```
0000_00 — laravel-max-client: max_users, max_chats, max_chat_users
0000_01 — приложение, системные таблицы (users и прочие)
0000_02 — filament-max-chat
0000_03 — filament-max-broadcasts
0000_04 — приложение, доменные таблицы
```

Пакет занимает начало порядка из-за `users.max_user_id → max_users.user_id`: FK в `users` указывает на таблицу этого
пакета, поэтому `users` обязана применяться позже `max_users`. Полоса зафиксирована тестом
`tests/Feature/MaxMigrationsTest.php`; сквозную проверку по миграциям приложения и всех пакетов выполняет
`MigrationOrderTest` в приложении — на SQLite нарушение порядка не воспроизводится, поэтому `php artisan migrate` его не
покажет.

### max:upgrade и ручные правки схемы

Команда `php artisan max:upgrade` в v1.2.0 делает перенос реестров на новую структуру (ранее она только дописывала
отсутствующие колонки). Схему, правившую руками, она всё равно не закроет: приведите её к форме пакета вручную либо
откатитесь на v1.1.4.

## Телефон из контакта (request_contact)

Апдейт с вложением-контактом (`request_contact`) содержит телефон пользователя, но в `max_users` он не попадает.
Слушатель `PersistMaxUserPhoneListener` сохраняет его в `max_users.phone` и проставляет
`phone_verified_at`. Если записи пользователя ещё нет — она создаётся.

Отдельной настройки не требуется: контакт приходит только через кнопку `request_contact` и формируется платформой из
номера аккаунта отправителя. Регистрация в MAX возможна на один номер, поэтому это номер самого пользователя, а не
произвольное значение из пересланной карточки — пересланный контакт приходит файлом, а не вложением типа `contact`, и в
этот путь не попадает.

Проверка подписи и разбор vCard делаются ядром (`ContactVerifier`, `ContactPhoneExtractor`) — телефон и
`vcf_info` не логируются. Если пришёл номер, отличный от сохранённого, значит пользователь сменил номер аккаунта: новое
значение сохраняется, а сам факт смены пишется в лог без значений. Уже сохранённый номер, пришедший повторно, обновляет
только отметку `phone_verified_at`.

Значение сохраняется как есть — ядро его не нормализует. Если номер используется как ключ (сверка с CRM, поиск дублей),
канонизацию под свой формат сделайте в своём слое, одним местом: `vcf_info` даёт номер без `+` (`79250000000`), импорт
или форма могут дать тот же номер с `+`, и значения разойдутся.

## Профиль пользователя (MaxUserProfileService)

В апдейтах MAX аватар не приходит — источник истины полноценного профиля (имя, описание, аватар) участники чата
(`getChatMembers`). Пакет предоставляет `MaxUserProfileService` (singleton из контейнера) для заполнения полей
`max_users`: `avatar_url`, `full_avatar_url`, `description` и др. «Когда вызывать» — решает приложение.

```php
use GeekCo\LaravelMaxClient\Services\MaxUserProfileService;

$profile = app(MaxUserProfileService::class);

// Подтянуть профили: chat_id берётся из активных max_chats (бот добавлен).
$profile->refresh(111);                // один пользователь
$profile->refresh([111, 222, 333]);    // группа

// Сохранить профиль из DTO ChatMember (getChatMembers / getChatAdmins).
$profile->upsertFromMember($member);

// Дозаполнить аватар, если пуст. chatId — явное указание (без реестра).
$profile->ensureAvatar($user);
$profile->ensureAvatar($user, chatId: 222);
```

- `refresh()` группирует userIds по активным чатам: `chat_id` берётся из `max_chats`, пользователи — из связей
  `max_chat_users`, и батчит их по `users.profile_batch_size`
  (`MAX_USERS_PROFILE_BATCH_SIZE`, по умолчанию 50) на вызов `getChatMembers`. Возвращает false, если активных чатов нет
  или профили не обновились.
- `users.profile_from_active_chats` (`MAX_USERS_PROFILE_FROM_ACTIVE_CHATS`, по умолчанию true) — искать chat_id в
  реестре. При `false` `refresh()` пропускается, но явный `chatId` в `ensureAvatar()` работает всегда.
- `ensureAvatar()` по умолчанию перепроверяет профиль раз в сутки (`users.profile_check_interval`,
  `MAX_USERS_PROFILE_CHECK_INTERVAL`, по умолчанию `86400` = раз в сутки): пропуск, только пока `profile_checked_at`
  свежее интервала. `0` — отключить периодичность (обновлять только при пустом аватаре).

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

## История изменений

Актуальную версию смотрите по тегу (`git tag --sort=-v:refname | head -1`) и
в [GitHub Releases](https://github.com/geekcodev/laravel-max-client/releases); полные описания — в
`.agents/release/RELEASE_NOTES_*.md`
репозитория. Версия берётся из git-тегов, в `composer.json` поле `version` не указывается.

| Версия | Дата       | Основное                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                     |
|--------|------------|----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------|
| v1.2.0 | 2026-09-30 | `max_chats` хранит одну строку на чат с первичным ключом `chat_id`, связи с пользователями вынесены в `max_chat_users` (`MaxChatUser`, `MAX_CHAT_USERS_MODEL`) с суррогатным `id` типа `uuid`; удалён `MaxChat::maxUser()`; перенос данных с установки v1.1.x выполняет команда `max:upgrade` (`--dry-run`, `--force`, `--rollback`); колонки идентификаторов объявлены знаковым `BIGINT` — идентификаторы групп и каналов в MAX отрицательные; `title_checked_at` → `chat_checked_at` (значение переносит `max:upgrade`), `chats.title_check_interval` → `chats.chat_check_interval` (прежние имена читаются); метаданные чата запрашиваются один раз на чат независимо от числа участников |
| v1.1.4 | 2026-09-30 | Метаданные чатов (`title`, `description`, `link`, `icon_url`) одним запросом `getChat` на чат, телефон из подтверждённого `request_contact`, `max:chats:refresh` и `max:upgrade`, аддитивные миграции вместо правки create-миграций; исправлены потеря `bot_removed` и незаполненные метаданные у поздних участников чата                                                                                                                                                                                                                                                                                                                                                                    |
| v1.1.2 | 2026-09-17 | Профили в диалогах через `dialog_with_user` (`getChatMembers` для диалогов недоступен), резолв типа чата, кэш чатов, ядро `^1.1.0`                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                           |
| v1.1.1 | 2026-09-04 | Поле `chat_type` в `max_chats` (dialog/chat/channel) и его дозаполнение из lifecycle- и message/callback-апдейтов                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                            |
| v1.1.0 | 2026-08-28 | `MaxUserProfileService` (аватар и профиль через `getChatMembers`), реестр чатов переименован в `MaxChat` / `max_chats`                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                       |
| v1.0.9 | 2026-08-19 | Реестр пользователей `max_users`, поле `last_activity_at`, переименование `bot_chats` → `max_bot_chats`                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                      |
| v1.0.7 | 2026-08-15 | Ядро `^1.0.6`: глобальный rate limit 30 req/s (`global_rate_limit`), алиас middleware `max_bot.log` → `max.log`, доработка `max.csp`                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                         |
| v1.0.6 | 2026-08-14 | Верификация WebAppData из строки (фрагмент URL `#WebAppData`) через `WebAppContext`                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                          |
| v1.0.5 | 2026-08-13 | Настраиваемое логирование запросов и апдейтов (middleware `max.log`), синхронизация с ядром v1.0.3                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                           |
| v1.0.3 | 2026-08-12 | Поддержка мини-приложений, middleware `max.webapp`, реестр чатов, команды `max:subscribe` / `max:unsubscribe`                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                |
| v1.0.0 | 2026-08-08 | Первая версия адаптера: провайдер, publishable-конфиг, фасад, вебхук `POST /max/webhook` с очередью, Long Polling (`max:listen`)                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                             |

Промежуточные патч-версии (v1.0.1, v1.0.2, v1.0.4, v1.0.8, v1.1.3) — см. GitHub Releases.

## Лицензия

MIT (c) 2026 Evgeny Semenov. См. `LICENSE`.
