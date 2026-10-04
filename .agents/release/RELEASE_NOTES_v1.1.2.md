# v1.1.2 — Профиль для диалогов (dialog_with_user)

## Новое

- **Профили в диалогах**: `MaxUserProfileService` теперь обрабатывает диалоги (`chat_type = dialog`), где
  `getChatMembers` недоступен («Method is not available for dialogs»). Для диалога источник полного профиля с аватаром —
  `getChat()` → `dialog_with_user` (собеседник); профиль пишется в `max_users` через тот же
  `upsertFromMember()`. Запись сохраняется только если собеседник входит в запрошенный список `userIds`.
- **Резолв типа чата** (`chatTypeFor()`): тип берётся из реестра `max_chats.chat_type`; при неизвестном —
  `getChat()` с записью в реестр (`whereNull('chat_type')`, без перетирания известного значения). Работает и в
  `refresh()`, и в `ensureAvatar()` без явного `chatId`.
- **Кэш чатов** (`chatFor()`): один вызов `getChat()` на chat_id в рамках прохода сервиса (`chatCache`), ошибки
  логируются в `max.log` (code + message, без чувствительных данных).
- **Зависимость ядра**: `geekcodev/max-php-client` повышен `^1.0.6` → `^1.1.0` (DTO `Chat` с `dialog_with_user`,
  `UserWithPhoto`).
- **PHPDoc-контракт `MaxChat`**: задокументированы `@property` — `user_id`, `chat_id`, `status`, `chat_type`,
  `last_activity_at`.

## Изменение (BC)

- Нет. Новое поведение затрагивает только диалоги; группы/каналы используют прежний путь через `getChatMembers`.

## Затронутые сценарии

- `refresh()` / `ensureAvatar()` для диалога: вместо `getChatMembers` — `getChat()` + `dialog_with_user`; для
  неизвестного `chat_type` тип резолвится автоматически и сохраняется в реестр чатов.

## Качество

- PHP 8.4, PSR-12, PHPStan level max — 0 ошибок.
- PHPUnit зелёные (191 тест / 455 assertions); покрытие строк 98.20%, порог ≥ 95%.
- `composer audit` — без уязвимостей.