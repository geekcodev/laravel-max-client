---
tags: [ release, v2, release-notes, verification, gate ]
date: 2026-11-23
---

## Проблема

Перед тегом v2.0.0 требовалась полная проверка `.agents/release/RELEASE_NOTES_v2.0.0.md` и готовности ветки `new`
к релизу.

## Решение

- Сверены все заявления release notes с кодом: 19 подтипов `Update`, строковый `updateType`, `UpdateInfo::from()`,
  переименования методов и DTO ядра, удаление `getChats`/`getUpdatesBatch`, `Callback::$message` →
  `MessageCallbackUpdate::$message`, 13 строковых типов подписки, 400 при невалидном payload, guard-тест фасада,
  Policy/permissions, `lang/{ru,en}`, `WebAppContactService::resolve(..., ?string $name)`. Расхождений нет.
- Ядро v2.0.0 есть на Packagist; тег `v2.0.0` указывает на коммит `545583c` (не `76c137b`), lock ссылается на него же;
  `repositories` в `composer.json` нет, constraint `^2.0`.
- Исправлено: release notes — снята пометка «черновик до серверной проверки» (приёмка пройдена 2026-11-23), добавлены
  клиентские проверки ядра (4000 символов, пустой `callback_id`, интервал polling ≥300 мс) и результат смоука;
  `UPGRADE_PLAN.md` — коммит тега `545583c`; план синхронизации — убрано противоречие про path-repository в статусе
  «завершён»; README — дата v2.0.0 на 2026-11-23, в строку добавлены локализация и телефон из моста `requestContact`.

## Тесты

PHPUnit 393/393 (1019 assertions), покрытие 96.97% (1407/1451); интеграционный смоук (read-only, реальный API,
`--network host`): 1 тест / 2 утверждения — зелёный.

## Нюансы

- AGENTS.md и UPGRADE_PLAN.md описывают v2.0.0 как выпущенный — это станет фактом после установки тега; правки
  коммитятся вместе с релизом.
- Релизные шаги (коммит, merge, тег, GitHub Release, Packagist) выполняет пользователь.

## Gate

Lint 0/80, PHPStan level max 0, PHPUnit 393/393, покрытие 96.97% (порог 95%), `composer audit` 0, интеграционный смоук
зелёный.