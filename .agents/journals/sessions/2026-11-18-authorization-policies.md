---
tags: [ policies, permissions, gate, enums, config, tests, gate ]
date: 2026-11-18
---

# Права и Policy в адаптере

## Проблема

Права на локальные записи MAX и Policy-классы жили в `filament-max-users`. Любой другой MAX-пакет или хост-приложение
были вынуждены дублировать имена прав, а модели плагина могли разойтись с моделями адаптера — Policy и ресурс смотрели
бы на разные классы.

## Решение

Авторизация перенесена в адаптер. Добавлены enum-ы `MaxUserPermission` / `MaxChatPermission` со строками `max_user.*` /
`max_chat.*`, Policy-классы `MaxUserPolicy` / `MaxChatPolicy` (база `PermissionPolicy`) и секция `permissions` в
`config/laravel-max-client.php` (env `MAX_PERMISSION_*`). Провайдер в `boot()` регистрирует `Gate::policy()` для моделей
`users.model` / `chats.model`; если хост уже назначил свою политику (`Gate::getPolicyFor()`), пакет её не перетирает.

Ability: `view.any`, `view`, `edit`, `delete`, `manage`. `manage` закрывает синхронизацию, `edit`/`delete` — локальные
операции. `create` в контракт не входит: реестр наполняется вебхуками, ручное создание записей пакетом не
поддерживается.

## Тесты

- Значения enum-ов (`max_user.view.any` и т.д.).
- Все ability для обеих политик: allow при выданном праве, deny без него и для гостя.
- Переопределение permission-строки через конфиг.
- Регистрация политик для моделей из конфига и дефолты секции `permissions`.

## Нюансы

- Permission-строка внутри Policy проверяется через `Gate::forUser(...)->allows()`, поэтому работают spatie и обычные
  `Gate::define`.
- Ключ `view_any` в конфиге: точка в ключе массива ломает `config()`; сама строка остаётся `max_user.view.any`.
- Плагин после переноса использует модели адаптера и больше не публикует права/модели.

## Gate

Lint 0/76, PHPStan level max 0, PHPUnit 379/379 (985 assertions), покрытие 96.87% строк (1360/1404), `composer audit` 0.
