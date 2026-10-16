---
tags: plan, webhook, subscribe, update-types, config, release-1.2.2
date: 2026-10-16
---

# План 2026-10-15: полный список типов апдейтов в webhook-подписке

## Проблема

`max:subscribe` жёстко прописывал подписке шесть типов: реестр чатов жил на `message_created`/`message_callback`/
`bot_*`, а `message_edited`, `message_removed`, `dialog_cleared`, `dialog_removed`, `chat_title_changed`,
`user_added`, `user_removed` не доставлялись — «тихая рассинхронизация» без единой ошибки в логах. Список был закопан в
приватном методе команды, у хоста не было способа его переопределить.

## Решение

- `Config::DEFAULT_WEBHOOK_UPDATE_TYPES` — 13 потребляемых адаптером типов, единственное место правды (кейсы
  `UpdateType` ядра). `comment_*` и `bot_admin_permissions_changed` не добавлены — потребителей нет.
- `Config::webhookUpdateTypes()` — переопределение ключом `webhook.update_types` (env `MAX_WEBHOOK_UPDATE_TYPES`):
  строка через запятую или массив в конфиге, обрезка пробелов, дедупликация, отброс нестрок; пустой результат → дефолт,
  подписка «на ничего» невозможна.
- `MaxSubscribeCommand` берёт список из `Config`; приватный `updateTypes()` удалён (второе место правды), импорт
  `UpdateType` убран.
- Решения владельца этой сессией: 13 типов, включая `user_added`/`user_removed` под план
  `2026-10-02-laravel-max-client-chat-member-sync.md` (перенесён из `filament-max-chat`); оба плана выходят одним
  релизом v1.2.2 (patch — явное решение, зафиксировано в release notes).
- Открытый вопрос 1 закрыт по официальной схеме `max-messenger/api-schema` (v0.0.33): `update_types`
  в `SubscriptionRequestBody` опционален, но поведение MAX при отсутствии поля схема не описывает → список остаётся
  явным, самовольного `null` не делаем.
- Открытый вопрос 2 закрыт эмпирики: GET до (одна подписка, 6 типов) → POST `createSubscription` с тем же URL, 13 типами
  и секретом из `.env` (в журнал/логи не попадал) → GET после (одна подписка, 13 типов). Дозапись подтверждена — хостам
  достаточно одного `max:subscribe`, пара «отписать → подписать» не нужна; результат вписан в README, AGENTS.md и
  release notes.
- Документация: README (раздел «Подписки (webhook)», сноска Long Polling, changelog v1.2.2), AGENTS.md (контракт
  «Подписки»), `.env.example`, release notes v1.2.2 дополнены обоими планами.

## Тесты

- `tests/Unit/Support/ConfigTest.php`: дефолт = ровно 13 типов; парс строки через запятую с обрезкой пробелов и
  переводов строки; массив с дедупликацией и отбросом нестрок; пустые значения (пустая строка, « , , », пустой массив,
  null) → дефолт.
- `tests/Unit/Console/MaxSubscribeCommandTest.php`: подписка уходит с 13 типами; переопределение списка строкой из
  конфига; пустое переопределение → непустой дефолт (регресс отписки «на ничего»).

## Нюансы

- `public const list<string>` — невалидный синтаксис: `list` не является нативным типом, первая реализация падала
  ParseError на строке константы. Правильная форма для PHP 8.4 — `public const array` с `@var list<string>` в docblock.
- «Фильтр только доставки» обязательно документировать: `max:listen` типы не фильтрует, иначе хост ждёт обратного.
- Скопированный план `2026-10-02-laravel-max-client-chat-member-sync.md` синхронизировать в `filament-max-chat`
  (там действуют правила ведения `.agents` того репозитория).

## Gate

`composer run lint` — 0 из 69 файлов; `vendor/bin/phpstan analyse` (level max) — 0 ошибок; `composer run coverage` — 377
тестов, 949 assertions, зелёные, покрытие строк 97.64% (порог 95%); `composer audit` — 0 уязвимостей (прогон прошёл
после нестабильной сети прошлой сессии). Markdown-правки (README/AGENTS/release notes/планы/журнал) код не меняли.
