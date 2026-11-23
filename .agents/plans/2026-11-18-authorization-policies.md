# План: права и Policy в адаптере (v2.0.0)

Статус: завершён (2026-11-18), ветка `new`.

## Цель

Перенести авторизацию локальных записей MAX из `filament-max-users` в адаптер, чтобы права были единым источником для
всех MAX-пакетов и хоста, а плагин отвечал только за привязку ability к UI.

## Решения

- Код ресурса — префикс permission-строки: `max_user` / `max_chat`; ability `view.any`, `view`, `edit`, `delete`,
  `manage`.
- `manage` — синхронизация (`refresh`/`sync`), `edit`/`delete` — локальные операции.
- `create` в контракт не входит: реестр наполняется вебхуками, ручное создание пакетом не поддерживается.
- Permission-строки и их дефолты — в конфиге адаптера (env `MAX_PERMISSION_*`), ключ `view_any` из-за точки в
  `config()`.
- Политики регистрируются в `boot()` для `users.model` / `chats.model`; политику хоста пакет не перетирает.

## Этапы

1. [x] Enum-ы `MaxUserPermission`, `MaxChatPermission`.
2. [x] `Policies\PermissionPolicy` + `MaxUserPolicy` + `MaxChatPolicy`.
3. [x] Секция `permissions` в конфиге, env-переменные в `.env.example`.
4. [x] Регистрация `Gate::policy()` в `MaxServiceProvider` с guard по `Gate::getPolicyFor()`.
5. [x] Тесты: enum-значения, все ability, deny, guest, override permission-строки, факт регистрации политик.
6. [x] Документация: README (раздел «Права и Policy»), AGENTS.md (структура, контракт, gotcha 22), release notes v2.0.0.
7. [x] Gate: lint, PHPStan, PHPUnit, покрытие, audit.

## Нюансы

- Внутри Policy permission-строка проверяется через `Gate::forUser(...)->allows()`, поэтому совместимы spatie и обычные
  `Gate::define`.
- `Gate::getPolicyFor()` может вернуть политику по авто-обнаружению имени; guard всё равно не перетирает решение хоста.
- Плагин после переноса берёт модели из `users.model` / `chats.model`; собственные ключи моделей и прав удалены.
