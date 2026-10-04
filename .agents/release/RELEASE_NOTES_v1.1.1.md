# v1.1.1 — chat_type в реестре чатов

## Новое

- **`chat_type`** (nullable `string(16)`) — колонка в `max_chats`, тип чата: `dialog` / `chat` / `channel`. Каст в
  ядерный `GeekCo\MaxPhpClient\Enum\ChatType`.
- **Lifecycle-события** (`bot_added`/`bot_started`) заполняют `chat_type`: `isChannel` → `Channel`, иначе — fallback
  `getChat()` API. При сбое значение остаётся `null`.
- **Входящие message/callback** дозаполняют `chat_type` у **существующего** чата (`whereNull`), не создавая запись и не
  меняя `status`. SRP сохранён: реестр отслеживает добавление бота, дозаполнение — молчаливая операция.
- Publishable-миграция: `0001_01_01_000002_create_max_chats_table.php` добавлена колонка `chat_type`.

## Изменение (BC)

- Нет. Колонка nullable, существующие записи не затронуты.

## Качество

- PHP 8.4, PSR-12, PHPStan level max — 0 ошибок.
- PHPUnit зелёные (187 тестов / 436 assertions); покрытие строк 99.36%, порог ≥ 95%.
- `composer audit` — без уязвимостей.
