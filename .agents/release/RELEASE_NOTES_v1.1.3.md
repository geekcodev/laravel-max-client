# v1.1.3 — Синхронизация с ядром v1.1.6 (Comments API, getUpdatesBatch)

## Новое

- **Зависимость ядра**: `geekcodev/max-php-client` повышен `^1.1.0` → `^1.1.6`. Через фасад `Max` доступны новые методы
  ядра: Comments API (`getComments`, `getComment`, `sendComment`, `editComment`, `deleteComment`) и
  `getUpdatesBatch` (батчевое получение апдейтов, `UpdatesResult`).
- **`chat_type` из comment-апдейтов**: `PersistMaxChatListener` дозаполняет `max_chats.chat_type` из
  `Update::$comment->recipient->chatType` (ядро с v1.1.6 присылает события `comment_created`/`comment_edited`/
  `comment_removed` с `recipient` — `chat_id`, `chat_type`, `post_id`). Как и для message/callback-апдейтов, статус не
  меняется и чат не создаётся; неизвестный `chat_type` не перетирается.
- **Guard-тест фасад ↔ ядро**: `MaxFacadeTest::testFacadeDocumentsEveryPublicApiClientMethodWithItsReturnType`
  сверяет PHPDoc `Facades\Max` со всеми публичными методами `ApiClient` ядра (имя + тип возврата) — расхождение больше
  не проходит тесты молча при обновлении зависимости.
- **Логирование отклонённого вебхука**: в `max.log` при 400 по невалидному payload добавлено `message` ошибки API рядом
  с `exception` и `code` (без payload, токена и секрета).
- **Документация синхронизирована с ядром**: `README.md` (требование `geekcodev/max-php-client ^1.1.6`, заполнение
  `chat_type`), `AGENTS.md` (constraint `^1.1.6`, факты Comments API и `getUpdatesBatch`, отсылка к guard-тесту).

## Изменение (BC)

- Нет. Сигнатуры публичного API пакета не менялись, изменён только PHPDoc фасада (новые `@method`).
- Уточнены типы возврата в PHPDoc фасада по ядру v1.1.6: `uploadMedia()` → `UploadedInfo` (вместо `UploadResult`),
  `sendAnswer()` — с параметрами `notification` и `disableLinkPreview`.
- Помечены deprecated `getChats()` и `addChatMembers()` (депозиция в ядре, удаление в v2.0.0) — методы по-прежнему
  работают, адаптер их не вызывает.

## Затронутые сценарии

- Комментарии к постам в каналах: чтение/правка/удаление через `Max::getComments()`, `Max::getComment()`,
  `Max::sendComment()`, `Max::editComment()`, `Max::deleteComment()`; права бота — `read_all_messages`
  (токен админа канала).
- Обработка `comment_created`/`comment_edited`/`comment_removed` в `MaxUpdateReceived`: если чат уже в реестре, у него
  проставляется `chat_type`; статус и состав реестра не меняются.
- Батчевое чтение апдейтов вместо цикла `Max::getUpdates()`.
- Диагностика вебхука: в лог попадает сообщение ошибки API при отклонении payload.

## Качество

- PHP 8.4, PSR-12 (lint — 0 файлов с правками), PHPStan level max — 0 ошибок.
- PHPUnit зелёные: 196 тестов / 463 assertions; покрытие строк 98.20% (655/667), порог ≥ 95%.
- `composer audit` — без уязвимостей.
- Интеграционные смоук-тесты (`--group integration`) не запускались: нет `MAX_API_TOKEN`.
