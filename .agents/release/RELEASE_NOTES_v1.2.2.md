# v1.2.2

Релиз объединяет два плана. Адаптер получает два класса для мини-приложения: подтверждение контакта из моста
`window.WebApp.requestContact()` и read-сторону WebApp-сессии; webhook-подписка по умолчанию расширяется с шести до 13
типов апдейтов с переопределяемым списком `webhook.update_types`. Constraint ядра поднят до `^1.1.10`.

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

**Типы апдейтов webhook-подписки.** `max:subscribe` подписывал только на шесть типов (`message_created`,
`message_callback`, `bot_added`, `bot_started`, `bot_stopped`, `bot_removed`): реестр чатов жил, а `message_edited`,
`message_removed`, `dialog_cleared`, `dialog_removed`, `chat_title_changed`, `user_added`, `user_removed` доставлены не
были — «тихая рассинхронизация», при которой правки, удаления и очистки теряются без единой ошибки. Дефолт расширен до
13 потребляемых адаптером типов; место правды — `Config::DEFAULT_WEBHOOK_UPDATE_TYPES`. Список переопределяется ключом
конфига `webhook.update_types` (env `MAX_WEBHOOK_UPDATE_TYPES`, строка через запятую либо массив в конфиге): пустое
значение не переопределяет дефолт, пробелы и дубли вычищаются, полностью пустой список деградирует в дефолт — подписка
«на ничего» невозможна. `comment_*` и `bot_admin_permissions_changed` в дефолт не вошли: их не потребляет ни реестр
чатов, ни профили, ни телефон. `update_types` — фильтр **доставки подписки**:
Long Polling (`max:listen`) типы не фильтрует. Типы `user_added`/`user_removed` включены заранее под план
`2026-10-02-laravel-max-client-chat-member-sync.md` (перенесён в этот репозиторий): `PersistMaxChatListener` на оба типа
сейчас отвечает `default => null → return`, то есть игнор с определённым поведением.

## Изменение (BC)

- Минимальная версия ядра: `geekcodev/max-php-client` `^1.1.8` → `^1.1.10` (`composer update geekcodev/max-php-client`).
  Существующий код без изменений — ядро совместимо по публичному API.
- Публичные сигнатуры не менялись: новые классы, метод `Support\Config::webhookUpdateTypes()` и два ключа конфига с
  дефолтами (`webapp.session.contact_confirmed`, `webhook.update_types`).
- **Смена дефолтного списка типов подписки — поведенческое изменение в patch-релизе** (по решению владельца оба плана
  выходят одним v1.2.2): пакет сам существующие подписки не трогает, новые типы начнут приходить только после
  переподписки (шаг в разделе «Затронутые сценарии»). После переподписки слушатели хостов начнут получать правки,
  удаления, очистки, переименования чатов и события состава — убедитесь, что обработчики к этому готовы; с другой
  стороны, `user_added`/`user_removed` сейчас игнорируются `PersistMaxChatListener` (`default => null → return`).
- Имя ключа флага по умолчанию новое (`contact_confirmed`). Если интегратор переносит готовый код и держал флаг под
  старым именем (в i2tech это `max.webapp.contact_confirmed`), старое имя можно сохранить через
  `MAX_WEBAPP_SESSION_CONTACT_CONFIRMED=max.webapp.contact_confirmed`; иначе подтверждённые ранее сессии потребуют
  повторной авторизации (в приложении это допустимо, решение осознанное).
- Опциональный фасад `WebAppContext::verifyContact(...)` из плана при реализации не добавлен — контекст не раздувается,
  обращение идёт напрямую к `WebAppContactService`.

## Затронутые сценарии

- **Обязательный шаг после обновления с версии старше v1.2.2**: `php artisan max:subscribe <url>` заново — одного вызова
  достаточно, POST на уже существующий URL обновляет подписку. Это проверено эмпирически 2026-10-16 на реальной боевой
  подписке: GET `getSubscriptions()` до/после показал 6 → 13 типов при одном URL и одной подписке. Затем сверьте
  `php artisan max:subscriptions`. Без этого шага обновление пакета не приведёт к доставке недостающих типов, а симптом
  «обновился, а правки не приходят» выглядит как баг релиза.
- Чистая установка: `max:subscribe` сразу подписывает на все 13 типов, шага переподписки нет.
- Хосты с явно переопределённым `webhook.update_types`: их список не меняется (переопределение побеждает дефолт).
- Long Polling (`max:listen`): не затронут — он типы не фильтрует и не читает `webhook.update_types`.

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
- `tests/Unit/Support/ConfigTest.php` (подписки): дефолт `webhookUpdateTypes()` — ровно 13 потребляемых типов; парс
  строки через запятую с обрезкой пробелов и переводов строки; приём массива с дедупликацией и отбросом нестроковых
  значений; пустые значения (пустая строка, « , , », пустой массив, null) → дефолт.
- `tests/Unit/Console/MaxSubscribeCommandTest.php`: подписка уходит с 13 типами; переопределение списка из конфига
  строкой; пустое переопределение → непустой дефолт (регресс отписки «на ничего»).
- Открытые вопросы плана `2026-10-15-webhook-update-types.md` закрыты: вопрос 1 — по официальной схеме
  `max-messenger/api-schema` (v0.0.33): `update_types` в `SubscriptionRequestBody` опционален, но поведение MAX при
  отсутствии поля схема не описывает, поэтому список остаётся явным; вопрос 2 — эмпирики на реальной подписке:
  `POST /subscriptions` на существующий URL обновляет её (одна подписка, 6 → 13 типов).
- Gate: PHP CS Fixer — 0 из 69; PHPStan level max — 0 ошибок; PHPUnit — 377 тестов, 949 assertions, зелёные; покрытие
  строк 97.64% (порог 95%); `composer audit` — 0 уязвимостей.
