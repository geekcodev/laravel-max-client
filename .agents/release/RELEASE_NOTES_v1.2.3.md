# v1.2.3

Синхронизация с ядром `geekcodev/max-php-client` **v1.1.11**. Правок логики адаптера не потребовалось, но есть
поведенческое улучшение, ради которого релиз и делается: контакт, отправленный пользователем без подписи (`hash`
отсутствует или `null` — поле объявлено nullable в спеке MAX), больше не теряет апдейт. Constraint ядра поднят до
`^1.1.11`.

## Новое

**Апдейт с контактом без подписи больше не теряется.** До v1.1.10 ядра вложение-контакт без `hash` роняло разбор DTO
(`Field "hash" must be a string.`), контроллер вебхука отвечал `400 invalid_payload`, и апдейт терялся целиком. Ядро
v1.1.11 разбирает такую форму в пустой `hash`. Адаптер менять логику не пришлось: `MaxContactService` и раньше считал
неподписанный контакт неподтверждённым (fail-closed), поэтому телефон из него по-прежнему не записывается — в лог уходит
только предупреждение без значений (OWASP A09). Изменилось ровно одно: обработчик получает апдейт вместо
`400`.

**Внимание потребителям ядра** (не касается классов адаптера, но затрагивает общий апгрейд): в ядре v1.1.11 появилось
значение `ChatStatus::Suspended` — исчерпывающий `match (ChatStatus)` в коде приложения требует ветки `default`.
Собственный enum реестра чатов адаптера (`Enums\MaxChatStatus`) с ним не пересекается.

## Изменение (BC)

- Минимальная версия ядра: `geekcodev/max-php-client` `^1.1.10` → `^1.1.11`
  (`composer update geekcodev/max-php-client`). Публичные сигнатуры ядра не менялись, правок кода адаптера не требуется.
- Публичный API пакета не менялся: изменён только текст PHPDoc у `Facades\Max::addChatMembers()` — снята устаревшая
  пометка `deprecated в ядре с v1.1.6`. По release-notes v1.1.11 метод присутствует в спеке и официальной
  `schema.yaml`, с 9 сентября 2026 ограничен со стороны MAX, пометки об удалении нет.
- Новых классов, методов, ключей конфига и миграций нет.

## Затронутые сценарии

- **Вебхук `message_created` с контактом без `hash`**: апдейт доставляется в очередь и доходит до слушателей; раньше
  контроллер отвечал `400 invalid_payload`, и MAX повторял доставку до исчерпания попыток, после чего апдейт терялся.
- **Захват телефона** (`PersistMaxUserPhoneListener`): поведение прежнее — телефон берётся только из подписанного
  контакта. Контакт без подписи не заполняет `max_users.phone` и не меняет `phone_verified_at`.
- **Прочие изменения ядра v1.1.11 наследуются без правок кода**: `Attachment::$filename` и метаданные вложений (`size`,
  `width`/`height`/`thumbnail`, `transcription`), rendition-URL видео (`mp4_*`, `hls`), `Subscription::$time`,
  `Callback::$timestamp`/`$user` (и фолбэк `callback.user` в `Update::$user`), новые формы `FailedUserDetails`. Адаптер
  эти поля не хранит и не перечисляет — они доступны потребителям через DTO ядра.
- **Исходящие запросы** (`max:subscribe`, `max:unsubscribe`, `max:subscriptions`, `max:chats:refresh`) не затронуты;
  переподписка после обновления не требуется.
- Чистая установка и обновление с любой версии: новых миграций нет, дополнительных шагов нет.

## Качество

- Регрессионные тесты на путь «контакт без подписи»:
  `MaxContactServiceTest::testContactWithoutHashYieldsNoPhone` (пустой `hash` → нет телефона и `isTrusted() === false`),
  `testContactDecodedWithoutHashYieldsNoPhone` (вложение разбирается из payload вебхука через `Update::fromArray()`),
  `testUnsignedContactIsNotTrusted`;
  `PersistMaxUserPhoneListenerTest::testContactDecodedWithoutHashIsIgnored` (payload без `hash` доходит до слушателя,
  `max_users.phone` остаётся пустым);
  `MaxWebhookControllerTest::testDecodesContactAttachmentWithoutHash` (полный HTTP-запрос отвечает 200 и ставит job
  вместо прежнего `400`).
- Gate: PHP CS Fixer — 0 из 69 файлов; PHPStan level max — 0 ошибок; PHPUnit — 382 теста, 959 утверждений, зелёные;
  покрытие строк 97.64% (порог 95%); `composer audit` — 0 уязвимостей. Интеграционный смоук (`--group integration`)
  не запускался — в окружении нет `MAX_API_TOKEN`.
