# План: `WebAppContactService` и `WebAppSession` в адаптере

Статус: завершён (2026-10-16). Целевая версия: **v1.2.2** (patch). Зависит от ядра
`geekcodev/max-php-client` **v1.1.10** — там появляется `Security\WebAppContactVerifier` и `Dto\WebAppContact`, на
которых строится сервис. Сопутствующие планы:
`max-php-client/.agents/plans/2026-09-30-webapp-bridge-phone-verification.md` и
`i2tech.local/.agents/plans/2026-10-13-webapp-cabinet-package-extraction.md`.

## Цель

В integration-проекте (i2tech) личный кабинет мини-приложения работает на двух прикладных классах, которые дублируют
инфраструктуру адаптера:

- `App\Services\WebAppContactVerifier` — проверка подписи контакта из `window.WebApp.requestContact()` (сам алгоритм
  переезжает в ядро); приложение держит у себя только обёртку, читающую токен и `max_age` из конфига.
- `App\Support\WebAppSession` — чтение `user_id` из сессии и флага подтверждения контакта. Это read-сторона к
  `WebApp\ResolveWebAppIdentity`, который эти ключи пишет: адаптеру логично отдавать обе стороны симметрично.

Нужно вынести обёртку и session-хелпер в адаптер, чтобы потребители не копировали их у себя.

## Что уже есть в адаптере

- `WebApp\WebAppContext` — тонкая обёртка над ядровым `WebAppDataValidator` (верификация `initData`).
- `WebApp\ResolveWebAppIdentity` (алиас `max.webapp`) — пишет `user_id`/`chat_id` в сессию (ключи
  `webapp.session.*` из `Support\Config`) и в атрибут `ResolveWebAppIdentity::REQUEST_ATTRIBUTE`.
- `Services\MaxContactService` — эталон для нового сервиса: сам строит ядровой `ContactVerifier` из
  `Config::apiToken()`, отдаёт телефон или `null`, при несовпадении логирует `chat_id`/`user_id` без номера (A09).
- `Support\Config` — `apiToken()`, `webappMaxAge()`, `webappStrict()`, `webappSessionUserIdKey()`,
  `webappSessionChatIdKey()` уже есть.

## Реализация

1. `src/WebApp/WebAppContactService.php` — по образцу `MaxContactService`:
    - конструктор `(Config $config, Logger $logger)`; внутри `new WebAppContactVerifier($config->apiToken(),
      $config->webappMaxAge())` (ядро v1.1.10);
    - `verify(string $authDate, string $phone, string $userId, string $hash): bool` — делегирует ядру;
    - `resolve(string $authDate, string $phone, string $userId, string $hash): ?WebAppContact` — подтверждённые значения
      или `null`;
    - при несовпадении — `Logger::log('warning', ...)` с `user_id` и фактом, **без номера и payload** (OWASP A09), как в
      `MaxContactService::verify()`.
2. `src/WebApp/WebAppSession.php` — `final readonly`, методы:
    - `userId(Request $request): ?int`, `chatId(Request $request): ?int` — читают ключи из `Config`
      (`webappSessionUserIdKey()`/`webappSessionChatIdKey()`);
    - `contactConfirmed(Request $request, int $userId): bool` — сверяет сохранённый `user_id` с переданным;
    - `confirm(Request $request, int $userId): void` — `session()->regenerate()` (защита от session fixation — как
      сейчас в `WebAppAuthController`) и запись флага;
    - `forget(Request $request): void` — снимает флаг.
3. Конфиг (`config/laravel-max-client.php`, секция `webapp`):
    - `webapp.session.contact_confirmed` — env `MAX_WEBAPP_SESSION_CONTACT_CONFIRMED`, default `contact_confirmed`
      (текущее имя в приложении — `max.webapp.contact_confirmed`; при переносе значение можно сохранить через env,
      решение зафиксировать в release notes);
    - `Support\Config::webappSessionContactConfirmedKey()`.
4. `MaxServiceProvider.php` — зарегистрировать `WebAppContactVerifier` (ядро), `WebApp\WebAppContactService` и
   `WebApp\WebAppSession` в контейнере (bind/alias), по образцу существующих биндингов `WebAppDataValidator`/
   `WebAppContext`.
5. Опционально (решить при реализации): `WebAppContext::verifyContact(...)` как фасад к сервису — только если не
   раздувает контекст; по умолчанию не добавлять.

## Обратная совместимость

Только добавление публичных классов и одного ключа конфига; существующие сигнатуры и поведение `max.webapp` не меняются.
Patch (v1.2.2). Имя ключа флага по умолчанию новое — сессии, подтверждённые под старым именем, потребуют повторной
авторизации; в приложении это допустимо (осознанно).

## Тесты (testbench)

- `WebAppContactServiceTest`: успех (совпадающая синтетически вычисленная подпись), неверный `hash`, подмена `userId`,
  подмена `phone`, просроченный `authDate`, пустые входы; проверка, что при несовпадении уходит `warning` без номера.
- `WebAppSessionTest`: `userId`/`chatId` из сессии; `confirm()` меняет session id и ставит флаг; `contactConfirmed`
  true только при совпадении `user_id`; `forget()` снимает флаг.
- Персональные данные в тестах не используются: подписи вычисляются с синтетическим токеном, номер — тестовый.

## Нюансы и риски

- **Зависимость от релиза ядра.** Без `WebAppContactVerifier` v1.1.10 сервис не собрать; constraint поднять до
  `^1.1.10` в `composer.json` этого релиза.
- **PII.** Номер, `authDate`-payload и `hash` не логируются (A09); логируется только факт отказа с `user_id`.
- **Сессия в WebView.** Ключи сессии уже используются `max.webapp`; read-хелпер лишь читает те же ключи, нового риска
  нет (проверка на живом MAX — забота интегратора, см. план i2tech).
- **Границы.** Доменные сущности (заявки, статусы) адаптер не знает и знать не должен — сервис отдаёт только
  подтверждённый контакт.

## Gate

`composer lint` (php-cs-fixer) · `composer analyse` (PHPStan) · `composer test` (PHPUnit, testbench) · `composer audit`
— 0. Затем release notes `.agents/release/RELEASE_NOTES_v1.2.2.md`, merge `dev → main`, тег `v1.2.2`.
