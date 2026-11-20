# v2.0.0

Синхронизация адаптера с ядром `geekcodev/max-php-client` **v2.0.0** (официальная схема MAX). Версия ломающая: ядро
перешло на типизированную модель событий, поэтому часть кода приложения, работавшего с плоским `Update`, требует правок.
Релиз выпускается после серверной проверки вебхуков в тестовом проекте; до неё это черновик.

## Новое

**Типизированные события.** `Update` в ядре — abstract-база с 19 подтипами (`MessageCreatedUpdate`,
`BotStartedUpdate`, `CommentCreatedUpdate`, …). Поля события лежат в подтипе: ветвление по классу через `instanceof`
вместо строки `update_type`. Адаптер получил `Support\UpdateInfo` — единую точку извлечения `chat_id`, `user`,
`user_id`, `chat_type` и `is_channel` из любого апдейта, поэтому реестр чатов и телефон не дублируют разбор.

**Реестр чатов** (`PersistMaxChatListener`) переведён на подтипы: активность (`message_created`/`message_edited`/
`message_callback`/`comment_created`/`comment_edited`) двигает `last_activity_at` и дозаполняет `chat_type`,
`chat_title_changed` пишет заголовок без запроса к API, lifecycle `bot_*` меняет статус. Поведение реестра сохранено.

**Телефон из контакта** (`PersistMaxUserPhoneListener`, `MaxContactService`): контакт читается как `ContactAttachment`,
номер — только из `vcf_info`; неподписанный контакт по-прежнему не подтверждается (fail-closed).

**Телефон из моста `requestContact`.** `WebAppContactService::resolve()` после проверки подписи диспатчит
`WebAppContactVerified`; `PersistWebAppContactPhoneListener` пишет `phone` и `phone_verified_at` в `max_users`, создаёт
запись при отсутствии. Имя в ответе моста не приходит — необязательный параметр `resolve(..., ?string $name)`
позволяет передать его из `initDataUnsafe.user` и заполнить `first_name`/`last_name`. `verify()` событие не диспатчит.

**Фасад** `Max` пересобран под `ApiClient` v2 (guard-тест сверяет набор методов и типы возврата).

**Права и Policy.** Добавлены `Enums\MaxUserPermission` / `Enums\MaxChatPermission` (строки `max_user.*` /
`max_chat.*`), Policy-классы `MaxUserPolicy` / `MaxChatPolicy` (общая база `PermissionPolicy`) и секция `permissions` в
конфиге (env
`MAX_PERMISSION_*`). Провайдер регистрирует `Gate::policy()` для моделей из `users.model` / `chats.model`; если хост уже
назначил свою политику, она не перетирается. Ability: `view.any`, `view`, `edit`, `delete`, `manage`; `manage` —
синхронизация (`refresh`/`sync`). Создание записей в контракт не входит: реестр наполняется вебхуками, ручное создание
пакетом не поддерживается.

**Локализация.** Добавлены `lang/ru` и `lang/en`: `MaxChatStatus::label()` больше не содержит жёстких русских строк, а
fallback `MaxChat::displayName()` возвращает «чат :id» / «chat :id» по текущей локали. Провайдер подключает переводы
namespace `laravel-max-client`; публикация — тег `laravel-max-client-lang`.

## Изменение (BC)

- Минимальная версия ядра: `geekcodev/max-php-client` `^2.0` (`composer update geekcodev/max-php-client`).
- **События.** `$update->updateType` — строка (`'message_created'`), enum `UpdateType` удалён; плоских полей
  `$update->chatId`, `$update->user`, `$update->message`, `$update->comment`, `$update->callback`, `$update->title`,
  `$update->isChannel` больше нет — используйте подтип или `Support\UpdateInfo::from($update)`.
- **Методы ядра.** `sendBotAction` → `sendChatAction`, `sendAnswer` → `answerCallback`, `getComment` → `getCommentById`,
  `getVideoInfo` → `getVideoAttachmentDetails`; `getChats()` и `getUpdatesBatch()` удалены, `getUpdates()` возвращает
  `UpdateList` (`$batch->updates`).
- **DTO.** `SuccessResponse` → `SimpleQueryResult`, `ErrorResponse` → `Error`, `ChatMembersResult` → `ChatMembersList`,
  `AddChatMembersResult` → `ModifyMembersResult`, `BotCommandsResult` → `BotCommandsInfo`, `EditChatBody` → `ChatPatch`,
  `VideoInfo` → `VideoAttachmentDetails`, `UploadResult` → `UploadEndpoint`; `AttachmentType`, `ButtonType` удалены.
- **Контакт.** `ContactAttachmentPayload::$vcfPhone` удалён: номер берётся только из `vcf_info`.
- **Callback.** `Callback::$message` удалён — сообщение нажатой кнопки в `MessageCallbackUpdate::$message`.
- Ломающих изменений публичного API пакета нет: существующие ключи конфига, миграции, команды, middleware и событие
  `MaxUpdateReceived` остались прежними; добавлены секция `permissions`, Policy-классы, enum-ы прав и переводы.

## Затронутые сценарии

- **Вебхук.** Payload разбирается в типизированный апдейт. Payload без обязательных полей события (`chat_id`, `user`
  для lifecycle, `message` для `message_created`, `callback` для `message_callback`) отклоняется разбором — контроллер
  отвечает `400`, как и раньше для невалидных данных.
- **Long Polling** (`max:listen --once`): пачка приходит как `UpdateList`; маркер и постановка джобов не изменились.
- **Подписка** (`max:subscribe`): список типов по умолчанию — те же 13 значений, теперь строки; пересоздавать подписку
  после обновления не требуется, если она уже включала эти типы.
- **Реестр чатов и профили**: `getChat`, `getChatMembers`, `dialog_with_user` работают как раньше; `chat_type`
  приходит типизированным enum `ChatType`.
- **Контакт без `hash`**: апдейт доставляется, телефон не подтверждается.

## Качество

Lint 0/80, PHPStan level max 0, PHPUnit 393/393 (1019 assertions), покрытие 96.97% строк (1407/1451), `composer audit`
0. Серверная проверка вебхуков в тестовом проекте — обязательное условие выпуска.
