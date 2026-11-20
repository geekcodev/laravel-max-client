---
tags: [ webapp, requestContact, contact, max_users, phone, listener, tests, gate ]
---

# Телефон из моста requestContact сохраняется в max_users

## Задача

Перенести запись подтверждённого телефона из `window.WebApp.requestContact()` в `max_users` из приложения в адаптер, по
аналогии с `PersistMaxUserPhoneListener` для кнопки `request_contact`.

## Решения

- `WebAppContactService::resolve(..., ?string $name = null)` после успешной проверки подписи диспатчит
  `WebAppContactVerified`.
- `PersistWebAppContactPhoneListener` пишет `phone`/`phone_verified_at` в `max_users`; при отсутствии пользователя
  создаёт запись и разбивает необязательное имя на `first_name`/`last_name`.
- Существующее имя не перетирается; смена номера логируется без значений (A09). Отдельного флага нет — контакт уже
  проверен подписью.
- `verify()` событие не диспатчит, поэтому проверка подписи не имеет побочных эффектов.
- Слушатель зарегистрирован в `MaxServiceProvider::boot()`.

## Проверки

- `vendor/bin/phpunit` — 393 теста / 1019 утверждений.
- `composer run coverage` — 96.97% строк (1407/1451), порог 95%.
- `composer run lint` — 0 из 80 файлов.
- `composer run analyse` — PHPStan level max, 0 ошибок.
- `composer audit` — уязвимостей нет.

## Находки

- `resolve()` в тестах без БД теперь запускает слушатель; в unit-тестах верификатора событие подменяется
  `Event::fake()`, а реальная запись проверяется отдельным тестом слушателя с `RefreshDatabase`.
- Имя из `requestContact()` не приходит — оно остаётся необязательным параметром `resolve()` из `initDataUnsafe.user`.
- Запись в `max_users` обновляет только телефон и `phone_verified_at`; профиль пользователя не перезаписывается.

## Gate

Lint 0/80, PHPStan max 0, PHPUnit 393/1019, покрытие 96.97%, `composer audit` 0.
