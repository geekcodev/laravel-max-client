# v1.2.2

Адаптер получает два класса для мини-приложения: подтверждение контакта из моста `window.WebApp.requestContact()` и
read-сторону WebApp-сессии. Constraint ядра поднят до `^1.1.10`.

## Новое

**`WebApp\WebAppContactService`** — подтверждение контакта, полученного мини-приложением через
`window.WebApp.requestContact()`. Это другой механизм, чем кнопка `request_contact` в чате (там `vcf_info` и HMAC по
токену): сервер MAX подписывает три поля payload (`authDate`, `phone`, `userId`; `phone` — без `+`), сравнение
константное, свежесть `auth_date` — по `webapp.max_age` (replay-защита). Адаптер — тонкая обёртка над ядровым
`Security\WebAppContactVerifier` (появился в ядре v1.1.10): сам алгоритм не дублируется. Методы
`resolve(authDate, phone, userId, hash): ?WebAppContact` (подтверждённый контакт: `userId`, `authDate`, нормализованный
`phone` — либо `null`) и `verify(...): bool`. При отказе логируется только факт и `user_id` (`warning`) — телефон и
`hash` в логи не попадают (OWASP A09). Нормализация номера (удаление `+` и разделителей до цифр) — на стороне ядра:
фронт может прислать и «сырой» формат, подпись считается по цифрам.

**`WebApp\WebAppSession`** — `final readonly`-хелпер, read-сторона ключей сессии, которые пишет middleware `max.webapp`
(`ResolveWebAppIdentity`): `userId(Request): ?int`, `chatId(Request): ?int`, `contactConfirmed(Request, int): bool`,
`confirm(Request, int): void` — регенерирует session id (защита от session fixation, как в `WebAppAuthController`
integration-проекта) и ставит флаг подтверждения, `forget(Request): void` — снимает флаг.

**Конфиг.** Новый ключ `webapp.session.contact_confirmed` (env `MAX_WEBAPP_SESSION_CONTACT_CONFIRMED`, default
`contact_confirmed`) — ключ флага подтверждённого контакта; значение флага — `user_id`, которому контакт подтверждён.
`Support\Config::webappSessionContactConfirmedKey()`.

**Контейнер.** Биндинги (singleton, по образцу `WebAppDataValidator`/`WebAppContext`): ядровой
`Security\WebAppContactVerifier`, `WebApp\WebAppContactService`, `WebApp\WebAppSession`.

## Изменение (BC)

- Минимальная версия ядра: `geekcodev/max-php-client` `^1.1.8` → `^1.1.10` (`composer update geekcodev/max-php-client`).
  Существующий код без изменений — ядро совместимо по публичному API.
- Публичные сигнатуры не менялись: только новые классы и новый ключ конфига с дефолтом.
- Имя ключа флага по умолчанию новое (`contact_confirmed`). Если интегратор переносит готовый код и держал флаг под
  старым именем (в i2tech это `max.webapp.contact_confirmed`), старое имя можно сохранить через
  `MAX_WEBAPP_SESSION_CONTACT_CONFIRMED=max.webapp.contact_confirmed`; иначе подтверждённые ранее сессии потребуют
  повторной авторизации (в приложении это допустимо, решение осознанное).
- Опциональный фасад `WebAppContext::verifyContact(...)` из плана при реализации не добавлен — контекст не раздувается,
  обращение идёт напрямую к `WebAppContactService`.

## Затронутые сценарии

- Чистая установка и обновление без мини-приложения: ничего не меняется, новых миграций нет.
- Личный кабинет мини-приложения: вместо локальных `App\Services\WebAppContactVerifier` / `App\Support\WebAppSession`
  подключаются классы адаптера; переход — замена имён в использовании, поведение то же (см. план
  `.agents/plans/2026-09-30-webapp-bridge-phone-verification.md` в ядре и план i2tech).
- Контакт из моста `requestContact` и кнопки `request_contact` в чате — независимые механизмы: телефон из моста в
  `max_users.phone` не пишется (это делает `PersistMaxUserPhoneListener` только для вложения `request_contact`).

## Качество

- `tests/Unit/WebApp/WebAppContactServiceTest.php`: успех (синтетически вычисленная подпись), hex/base64/URL-safe base64
  подпись, неверный `hash`, подмена `userId`, подмена `phone`, нормализация форматированного номера, просроченный
  `authDate` (и его приём при `max_age=0`), пустые входы; `warning` без номера и `hash` в выводе; ветка нечислового
  `user_id`; отсутствие лога при успехе.
- `tests/Unit/WebApp/WebAppSessionTest.php`: чтение `userId`/`chatId` (в т.ч. кастомные ключи, нечисловое значение),
  `confirm()` регенерирует session id и ставит флаг, `contactConfirmed` true только для совпадающего `user_id`,
  `forget()` снимает флаг, кастомный ключ флага.
- `MaxServiceProviderTest`: биндинги новых классов как singleton; `ConfigTest`: дефолт и настройка ключа флага.
- Gate: PHP CS Fixer — 0 из 69; PHPStan level max — 0 ошибок; PHPUnit — 371 тест, 935 assertions, зелёные; покрытие
  строк 97.64% (порог 95%); `composer audit` — 0 уязвимостей (проверено встроенным аудитом composer и напрямую по базе
  advisory packagist.org для всех 137 locked-пакетов: затрагивающих версии нет).
