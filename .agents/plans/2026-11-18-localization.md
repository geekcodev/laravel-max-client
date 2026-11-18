# План: локализация бриджа

Статус: завершён (2026-11-18), ветка `new`.

## Цель

Убрать жёсткие русские строки из enum-подписей и fallback-названия чата, чтобы адаптер следовал текущей локали
приложения и не заставлял плагин дублировать переводы.

## Этапы

1. [x] `lang/ru` и `lang/en`: `enums.chat_status.*`, `chat.fallback`, `chat.unknown`.
2. [x] `MaxChatStatus::label()` через `__('laravel-max-client::enums.chat_status.*')`.
3. [x] `MaxChat::displayName()` fallback через `laravel-max-client::chat.fallback`.
4. [x] `loadTranslationsFrom` + публикация `laravel-max-client-lang` в провайдере.
5. [x] Тесты: ru/en подписи статусов, fallback на обоих языках, загрузка переводов, паритет ключей.
6. [x] Документация: README («Локализация»), AGENTS.md, release notes v2.0.0.
7. [x] Gate: lint, PHPStan, PHPUnit, покрытие, audit.

## Нюансы

- `__()` возвращает `array|string`; в типизированных методах результат проверяется на `is_string()`, иначе используется
  безопасный fallback.
- Публикация переводов необязательна: без неё работают встроенные `lang/ru` и `lang/en` пакета.
