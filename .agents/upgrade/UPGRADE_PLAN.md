# План обновления laravel-max-client под новые версии ядра max-php-client

> Рабочий документ для будущих сессий: как обновлять этот Laravel-адаптер, когда выходит новая версия
> ядра [`geekcodev/max-php-client`](https://github.com/geekcodev/max-php-client) или меняется `max-openapi`.
> Держать актуальным после каждого обновления.
>
> **Версии проверять динамически**, не по этому файлу: `composer show geekcodev/max-php-client` и
> `git -C ../max-php-client tag --sort=-v:refname | head -1`. API-факты — `docs/api-reference.md` ядра,
> раздел 9 «что делать при расхождении спека vs реальный API».

## Текущее состояние (2026-08-15, состояние на момент последней синхронизации)

- Ядро: **`geekcodev/max-php-client` v1.0.6**, constraint в `composer.json` — `^1.0.6`.
- Адаптер: `src/`, `tests/` синхронизированы с v1.0.6; Gate зелёный (lint 0, phpstan 0, phpunit 140/140, coverage ≥95%,
  `composer audit` — 0 уязвимостей); интеграционный смоук прогнан против реального API — OK.
- Сделано в последней сессии (ядро v1.0.5 → v1.0.6, подготовка адаптера к релизу v1.0.7):
    1. `composer.json`: `^1.0.5` → `^1.0.6`; `composer.lock` пересобран (`composer update geekcodev/max-php-client`).
    2. `src/Facades/Max.php`: PHPDoc `@method` `addChatAdmin()` расширен до `?int $marker = null` (v1.0.6 ядра).
    3. **Глобальный rate limit**: в `config/laravel-max-client.php` добавлен блок `global_rate_limit`
       (env `MAX_GLOBAL_RATE_LIMIT_*`, дефолт 30/30 rps); `Support\Config` — accessors; `MaxServiceProvider` передаёт
       `globalRateLimiter` в `ApiClient::create()` (в ядре с v1.0.6 глобальный предохранитель 30 req/s применяется ко
       всем запросам, включая ретраи и загрузку медиа).
    4. Тесты: `ConfigTest` (дефолты и кастомные значения global_rate_limit),
       `MaxServiceProviderTest::testGlobalRateLimitConfigIsApplied` (reflection до `HttpClient::$globalRateLimiter`).
    5. **Алиас middleware логирования** `max_bot.log` → `max.log` (единый нейминг с `max.webapp`/`max.csp`; BC для
       потребителей, задокументирован в релиз-нотах v1.0.7).
    6. Документация синхронизирована (`README.md`, `AGENTS.md`, `.env.example`, `UPGRADE_PLAN.md`).
- Не сделано (на усмотрение владельца): коммит/релиз адаптера. Изменения пока не закоммичены и не запушены.

## Точки контакта адаптера с ядром (что проверять при каждом обновлении)

| Место в адаптере                           | Использует из ядра                                                                                                | Риск при ломающих изменениях ядра                       |
|--------------------------------------------|-------------------------------------------------------------------------------------------------------------------|---------------------------------------------------------|
| `src/MaxServiceProvider.php`               | `ApiClient::create`, `RetryStrategy`, `RateLimiter` (per-chat/global), `LongPollingRunner`, `WebAppDataValidator` | сигнатуры конструкторов/фабрик                          |
| `src/Webhook/MaxWebhookController.php`     | `WebhookHandler::verify()/decode()`, `Dto\Update`, `InvalidResponseException`                                     | форма `decode()` ответа, поля `Update`                  |
| `src/Webhook/HandleMaxUpdateJob.php`       | `Dto\Update` (тип свойства)                                                                                       | nullable/новые поля `Update`                            |
| `src/Webhook/MaxUpdateReceived.php`        | `Dto\Update`                                                                                                      | тип свойства                                            |
| `src/Listeners/PersistMaxChatListener.php` | `Update::$updateType/$user/$chatId`, `Enum\UpdateType`                                                            | **nullable-поля `Update` (был прецедент v1.0.3)**       |
| `src/Console/Max*Command.php`              | `ApiClient::createSubscription/getSubscriptions/deleteSubscription`, `UpdateType`, `MaxApiException`              | возвращаемые типы (прецедент: v1.0.2 `SuccessResponse`) |
| `src/Console/MaxListenCommand.php`         | `LongPollingRunner`, `Update`                                                                                     | сигнатура runner/handler                                |
| `src/WebApp/*`                             | `WebAppDataValidator`, `Dto\WebAppIdentity`                                                                       | сигнатуры валидатора                                    |
| `src/Facades/Max.php`                      | `ApiClient` (PHPDoc `@method`)                                                                                    | переименования методов ядра                             |
| `src/Http/HttpClientFactory.php`           | PSR-18/17 (без прямых классов ядра)                                                                               | низкий                                                  |

## Процедура обновления при новом релизе ядра (чек-лист)

1. **Узнать, что изменилось.** Читаем релиз-ноты ядра (`https://api.github.com/repos/geekcodev/max-php-client/releases`)
   и diff спецификации `max-openapi`. Обращать внимание на раздел «Ломающие изменения».
2. **Сохранить бэкап текущего ядра** для сравнения (в вендоре нет git-истории):
   ```bash
   mkdir -p /tmp/opencode/max-core-previous
   cp -r vendor/geekcodev/max-php-client/src /tmp/opencode/max-core-previous/
   ```
3. **Поднять constraint** в `composer.json` (`geekcodev/max-php-client` → новая версия) и пересобрать lock:
   ```bash
   docker compose run --rm app composer update geekcodev/max-php-client
   ```
4. **Сравнить diff**: `diff -rq /tmp/opencode/max-core-previous/src vendor/geekcodev/max-php-client/src`
   и `diff -u` по изменившимся файлам. Особое внимание — `Dto/Update`, `ApiClient`, классы, которые есть в таблице
   «точек контакта».
5. **Пройтись по точкам контакта** (таблица выше): не изменились ли сигнатуры, не стали ли поля nullable, не поменялись
   ли возвращаемые типы. Проверить тесты — не используют ли старые сигнатуры.
6. **Внести правки** (по образцу этой сессии: null-guard, обновлённые ассерты, новые тесты). Не переписывать логику
   ядра — только делегировать.
7. **Прогнать обязательный Gate** (раздел 7 AGENTS.md):
   ```bash
   docker compose run --rm app composer run lint
   docker compose run --rm app vendor/bin/phpstan analyse
   docker compose run --rm app vendor/bin/phpunit
   docker compose run --rm app composer run coverage
   docker compose run --rm app composer audit
   ```
   Порог покрытия — ≥95% строк.
8. **Синхронизировать документацию**: `README.md` (Требования, «История изменений»), `AGENTS.md` (constraints),
   релиз-ноты в `.agents/release/` (`RELEASE_NOTES_vX.Y.Z.md` в формате `Новое` / `Изменение (BC)` /
   `Затронутые сценарии` /
   `Качество`; файл не удаляется после релиза, значимое дублируется в README и GitHub Release). Проверить, что в тексте
   не осталось упоминаний старой версии ядра.
9. **Обновить этот файл**: состояние, что изменилось, дата.
10. **Коммит/релиз** — только по явному запросу владельца: PR `dev → main` → тег → GitHub Release → Packagist.

## Что ломалось в прошлых версиях ядра (история прецедентов)

- **v1.0.2**: `ApiClient::createSubscription()` стал возвращать `SuccessResponse` вместо `Subscription` — адаптер
  перестал обращаться к `$subscription->url` (фикс + новая команда `max:subscriptions`).
- **v1.0.3**: `Update::$user` стал `?User` (nullable) — в `PersistBotChatListener` потребовался null-guard; фолбэки
  `user`/`chat_id` из `message.sender`/`recipient` и `callback.message`; добавлены поля
  `user_locale`/`title`/`payload`/`muted_until`/`message_id`/`user_id`/`inviter_id`/`admin_id`.
- **v1.0.6**: `ApiClient::create()` получил опцию `global_rate_limiter` (глобальный предохранитель 30 req/s) — адаптер
  добавил конфиг `global_rate_limit.*`; `addChatAdmin()` получил `?int $marker` — синхронизирован PHPDoc фасада;
  deprecated-права админов и `sendAnswer` с телом `{}` не требуют правок адаптера (delegation, только документированы).
- **v1.1.0**: комментарии и разметка в ответах, 19 типов обновлений, DTO `Chat` с `dialog_with_user`,
  `UserWithPhoto` в диалогах — constraint адаптера повышен до `^1.1.0` (релиз адаптера v1.1.2: профили диалогов через
  `dialog_with_user`).
- **v1.1.1–v1.1.5**: исправления ядра (CRLF, base64, raw `vcf_info` в верификации контакта, строковый
  `message.link.sender`) — правок адаптера не требуют, наследуются обновлением зависимости.
- **v1.1.6**: Comments API (`getComments`/`getComment`/`sendComment`/`editComment`/`deleteComment`),
  `getUpdatesBatch`, `UploadedInfo` вместо `UploadResult`, `sendAnswer` с `notification`/`disableLinkPreview`,
  `Update::$comment` и события `comment_created`/`comment_edited`/`comment_removed`, `BotCommand::$description`
  nullable, `Recipient::$postId`, депозиция `getChats`/`addChatMembers` в v2.0.0. Constraint адаптера повышен до
  `^1.1.6`; синхронизирован PHPDoc `src/Facades/Max.php`, `PersistMaxChatListener` учитывает `Update::$comment`
  (дозаполнение `chat_type` из `CommentMessage::$recipient`), добавлен guard-тест
  `MaxFacadeTest::testFacadeDocumentsEveryPublicApiClientMethodWithItsReturnType` (ловит расхождение фасада с ядром).
  Набор update types в `MaxSubscribeCommand` намеренно не расширен (BC и лимит трафика) — новые типы добавляются
  приложением через `createSubscription` самостоятельно.

## Прочие заметки

- Версии ядра и адаптера **не совпадают**: адаптер v1.1.2 ↔ ядро v1.1.6 при constraint `^1.1.6`. Сверять версии по
  `composer.lock` и release-нотам, а не по номеру.
- Релиз-ноты адаптера: `RELEASE_NOTES_vX.Y.Z.md` на каждую версию в `.agents/release/` (gitignored), не удаляются;
  значимые пункты дублируются в README («История изменений») и в тело GitHub Release.
- `Update::$user` в ядре теперь nullable: везде в адаптере обращаться через `?->` или null-guard — PHPStan level max это
  обязательно проверяет.
- Интеграционный смоук (`--group integration`) — только `--network host` и с `MAX_API_TOKEN` из `.env`
  (см. раздел 7 AGENTS.md); без токена тесты пропускаются.
