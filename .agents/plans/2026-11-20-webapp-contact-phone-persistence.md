# План: сохранение телефона из моста requestContact

Статус: завершён (2026-11-20), ветка `new`.

## Цель

Перенести запись подтверждённого телефона из `window.WebApp.requestContact()` в `max_users` из приложения в
`laravel-max-client`, по аналогии с `PersistMaxUserPhoneListener` для кнопки `request_contact`.

## Решения

- `WebAppContactService::resolve(..., ?string $name = null)` после успешной проверки подписи диспатчит
  `WebAppContactVerified`.
- `PersistWebAppContactPhoneListener` пишет `phone`/`phone_verified_at` в `max_users`; при отсутствии пользователя
  создаёт запись, имя из `initDataUnsafe.user` разбивает на `first_name`/`last_name`.
- Отдельного флага включения нет: контакт уже проверен подписью, как и у `request_contact`.
- `verify()` остаётся чистой проверкой — без события и записи в реестр.
- Существующее имя пользователя не перетирается; смена номера логируется без значений (A09).

## Этапы

1. [x] Добавить событие `WebAppContactVerified` (contact + необязательное имя).
2. [x] Диспатчить событие из `resolve()`, не добавлять его в `verify()`.
3. [x] Добавить `PersistWebAppContactPhoneListener` с созданием/обновлением `max_users`.
4. [x] Зарегистрировать слушатель в `MaxServiceProvider::boot()`.
5. [x] Обновить комментарий конфига, README, AGENTS.md и черновик release notes v2.0.0.
6. [x] Тесты: событие из `resolve`, отсутствие события в `verify`, создание/обновление пользователя, имя, смена номера,
   отсутствие телефона в логах.
7. [x] Gate: lint, PHPStan, PHPUnit, покрытие, audit.

## Нюансы

- `requestContact()` возвращает только `phone`, `authDate`, `hash`; имя приходит из `initDataUnsafe.user` и передаётся в
  `resolve()` необязательным параметром.
- Пакет уже владеет `max_users` (миграции и модель), поэтому запись в реестр — его существующая зона ответственности.
- Имя записывается только при создании записи; при обновлении существующего пользователя меняются только телефон и
  отметка подтверждения.
