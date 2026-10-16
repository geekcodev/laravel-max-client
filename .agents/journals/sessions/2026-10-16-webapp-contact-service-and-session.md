---
tags: [ webapp, contact, session, requestContact, core-1.1.10, config, provider, v1.2.2, gate ]
date: 2026-10-16
---

# WebAppContactService и WebAppSession (v1.2.2)

## Проблема

План `plans/2026-10-13-webapp-contact-service-and-session.md`: в адаптер не вынесены два прикладных класса, которые
integration-проект держит у себя, — обёртка проверки подписи контакта из моста `window.WebApp.requestContact()` и
read-сторона WebApp-сессии. Зависимость — ядро v1.1.10 (`Security\WebAppContactVerifier`, `Dto\WebAppContact`).

## Решение

- `composer.json`: ядро `^1.1.8` → `^1.1.10`, установлено v1.1.10. Заодно `symfony/yaml` v8.1.2 → v8.1.9 — в lock висел
  advisory CVE-2026-109413, без него не прошёл бы Gate по audit.
- `WebAppContactService` — по образцу `MaxContactService`: конструктор `(Config, Logger)`, внутри
  `new WebAppContactVerifier(token, max_age)`, `resolve(): ?WebAppContact` и `verify(): bool` (verify делегирует в
  resolve — один путь логирования). При отказе `warning` с фактом и `user_id` (null, если нечисловой), телефон и
  `hash` не логируются (A09).
- `WebAppSession` — `final readonly`: `userId()`/`chatId()` (ключи из `Config`), `contactConfirmed(int)` (строгое
  сравнение), `confirm()` — `session()->regenerate()` (anti-fixation) + запись флага, `forget()`.
- Конфиг: `webapp.session.contact_confirmed` (env `MAX_WEBAPP_SESSION_CONTACT_CONFIRMED`, default
  `contact_confirmed`) + `Config::webappSessionContactConfirmedKey()`; строка в `.env.example`.
- `MaxServiceProvider::register()`: singleton-биндинги ядрового `WebAppContactVerifier`, `WebAppContactService`,
  `WebAppSession`. П. 5 плана (опциональный `WebAppContext::verifyContact`) не добавлен — контекст не раздувается.
- Документация: README (требования, таблица переменных, два новых раздела, история изменений), AGENTS.md (§1, дерево,
  контракты WebApp, constraints, A06/A08), release notes v1.2.2, план завершён.

## Тесты

- `WebAppContactServiceTest` (16): успех с синтетической подписью (hex/base64/URL-safe base64), неверный `hash`, подмена
  `userId`/`phone`, нормализация форматированного номера, просроченный `authDate` и приём при `max_age=0`, пустые входы,
  `warning` без номера и `hash` в выводе, нечисловой `user_id`, отсутствие лога при успехе.
- `WebAppSessionTest` (8): чтение идентичности, нечисловое значение, кастомные ключи, регенерация session id при
  `confirm()`, сверка `user_id`, `forget()`, кастомный ключ флага.
- `MaxServiceProviderTest` (+3): биндинги как singleton; `ConfigTest` (+2): дефолт и настройка ключа флага.
- Личных данных нет: токен синтетический, номер тестовый, подписи считаются в тесте.

## Нюансы

- Сеть до packagist нестабильна (оба хоста таймают): update/audit гонялись через
  `docker run --network host` с ретраями. Итоговый `composer audit` CLI к концу сессии недоступен по сети, но
  advisory-проверка пройдена дважды: встроенный аудит последнего update («No security vulnerability advisories found») и
  прямой запрос базы advisory packagist.org по всем 137 locked-пакетам с проверкой affectedVersions — 0 затрагивающих
  наши версии (тот и нашёл `symfony/yaml` до обновления).
- Имя ключа флага новое: под старым именем приложения (`max.webapp.contact_confirmed`) сессии требуют повторной
  авторизации либо сохранения имени через env — в release notes.
- Мост `requestContact` и кнопка `request_contact` в чате независимы: телефон из моста в `max_users.phone` не пишется.

## Gate

`composer run lint` — 0 из 69 · PHPStan max — 0 ошибок · PHPUnit — 371 тест / 935 assertion, зелёные · coverage — 97.64%
строк (порог 95%) · `composer audit` — CLI недоступен по сети, проверено встроенным аудитом composer и базой advisory
packagist.org — 0 уязвимостей; см. «Нюансы».
