---
tags: [ localization, lang, enums, model, tests, gate ]
date: 2026-11-18
---

# Локализация бриджа

## Проблема

`MaxChatStatus::label()` возвращал жёсткие русские строки, а `MaxChat::displayName()` — английский fallback `chat <id>`.
В английской локали статусы оставались русскими, в русской fallback — английским; плагин не мог это исправить, потому
что строки жили в enum и модели адаптера.

## Решение

Добавлены `lang/ru` и `lang/en` (`enums.php`, `chat.php`). `MaxChatStatus::label()` берёт подпись из
`laravel-max-client::enums.chat_status.*`, `MaxChat::displayName()` — fallback из `laravel-max-client::chat.fallback`.
Провайдер делает `loadTranslationsFrom` и публикует переводы тегом `laravel-max-client-lang`; встроенные переводы
работают без публикации. Так как `__()` типизирован как `array|string`, результат проверяется через `is_string()` с
безопасным fallback.

## Тесты

- Статусы на ru и en: `Активен`/`Active`, `Остановлен`/`Stopped`, `Удалён`/`Removed`.
- Fallback названия чата на ru и en: `чат 222` / `chat 222`.
- Загрузка namespace `laravel-max-client` и публикуемых ключей.
- Паритет ключей `lang/ru` ↔ `lang/en`.

## Нюансы

- Публикация переводов необязательна; без неё используются встроенные файлы пакета.
- Жёсткие строки в enum/модели не остаются: единственный источник — lang-файлы.

## Gate

Lint 0/77, PHPStan level max 0, PHPUnit 381/381 (995 assertions), покрытие 96.87% строк (1362/1406), `composer audit` 0.
