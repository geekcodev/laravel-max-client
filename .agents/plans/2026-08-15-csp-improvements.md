# План: доработка middleware `max.csp` (`SetMaxFrameAncestors`)

> Источник требований: черновик ТЗ по итогам миграции «Чисто-Сервиса» на v1.0.3 — поглощён этим планом
> и удалён. Согласовано с владельцем (2026-08-15): включены пункты 3.1, 3.2 (опция 1), 3.3, 6.

## Решения

| Пункт документа             | Решение                                                                                                                                                          |
|-----------------------------|------------------------------------------------------------------------------------------------------------------------------------------------------------------|
| 3.1 Слепой аппенд           | **Реализовать слияние**: вливать недостающие хосты MAX в существующую директиву `frame-ancestors` на её исходном месте, без дублей                               |
| 3.2 Голый CSP               | **Опция 1**: если CSP-заголовка у ответа нет — не создавать. Middleware становится «дополняющим» (**BC**)                                                        |
| 3.3 Границы ответственности | **Документировать**: пакет — только `frame-ancestors`, приложение — полная политика                                                                              |
| 6 Единый источник хостов    | **Только заметка в доках**: дефолт `https://max.ru,https://web.max.ru` уже в пакете (config + `Config::webappFrameAncestors()`), приложению дублировать не нужно |

## Изменения по файлам

### 1. `src/Http/Middleware/SetMaxFrameAncestors.php`

Логика `handle()`:

1. `$response = $next($request)`; если `webappCspEnabled()` false — вернуть без изменений.
2. Взять `Content-Security-Policy`. Если `null`/пусто — **вернуть без изменений** (опция 1, BC).
3. Разобрать заголовок на директивы: сплит по `;`, каждая директива — `name` + значения через пробел.
4. Найти **первую** директиву `frame-ancestors` (по спецификации браузер учитывает только первую):
    - если есть — добавить недостающие хосты пакета в её значения (без дублей, сравнение case-insensitive по
      `strtolower`), оставив директиву на исходной позиции; `'self'` и прочие токены приложения не трогать;
    - если нет — дописать `frame-ancestors 'self' <hosts>` в конец.
5. Сериализовать обратно (`'; '` между директивами, одиночные пробелы между токенами) и `headers->set`.

Хелперы (private):

- `parse(string $csp): list<list<string>>` — разбор на токены;
- `appendMissingHosts(non-empty-list<string> $tokens): list<string>` — слияние без дублей.

Осознанные ограничения (зафиксировать в комментарии/доке):

- парсер работает по `;`-сплиту — значение с `;` внутри (например `data:image/png;base64,...`) разобьётся некорректно;
  для scope `frame-ancestors` приемлемо (KISS);
- при нескольких `frame-ancestors` у приложения мерджится первая, остальные не трогаются (первая и так решающая);
- дедупликация hosts — case-insensitive; дубли внутри существующей директивы приложения не убираются (не наш scope).

### 2. `tests/Unit/Http/SetMaxFrameAncestorsTest.php`

Переписать под критерии приёмки (раздел 4 документа) + регрессии:

1. CSP `frame-ancestors 'self' https://example.com; default-src 'self'` →
   `frame-ancestors 'self' https://example.com https://max.ru https://web.max.ru; default-src 'self'`
   (одна директива, без второй `frame-ancestors`).
2. CSP `frame-ancestors 'self' https://max.ru; default-src 'self'` → нет дубля `https://max.ru`, добавляется
   `https://web.max.ru`. Отдельный кейс: hosts сконфигурированы `['https://max.ru']` → директива не меняется.
3. CSP `default-src 'self'` (без `frame-ancestors`) → `frame-ancestors` дописывается в конец.
4. Без CSP-заголовка → заголовок **не создаётся** (было: создавался — BC).
5. `webapp.frame_ancestors.enabled=false` → заголовок не трогается.
6. Хосты из конфига (несколько) корректно объединяются (`testUsesConfiguredHosts` переписать:
   теперь только с существующим CSP).

### 3. Документация

- **`README.md`** (раздел `Middleware max.csp`): описать «дополняющий» характер — сливает хосты в существующий
  `frame-ancestors`, дописывает, если его нет, и **ничего не делает**, если CSP отсутствует; границы ответственности
  (3.3); заметку про единый источник хостов (6).
- **`AGENTS.md`** (контракт `SetMaxFrameAncestors` в разделе 5): актуализировать описание поведения.
- **`config/laravel-max-client.php`** (комментарий блока `webapp.frame_ancestors`): новое поведение.
- **`.env.example`**: при необходимости уточнить комментарии `MAX_WEBAPP_CSP_ENABLED` /
  `MAX_WEBAPP_FRAME_ANCESTORS`.
- **Релиз-ноты**: подготовить `RELEASE_NOTES_v1.0.8.md` в `.agents/release/` (BC: middleware больше не создаёт
  CSP-заголовок с нуля; слияние вместо аппенда).

## Критерии приёмки

Все пункты раздела 4 документа + сохранение регрессий (флаг `enabled`, кастомные hosts). Покрытие новых веток
middleware — unit-тесты; строка покрытия ≥95% после прогона.

## Gate (обязателен после правок)

```bash
docker compose run --rm app composer run lint
docker compose run --rm app composer run format      # только при правках lint
docker compose run --rm app vendor/bin/phpstan analyse
docker compose run --rm app vendor/bin/phpunit
docker compose run --rm app composer run coverage
docker compose run --rm app composer audit
```

## Затрагиваемые файлы

- `src/Http/Middleware/SetMaxFrameAncestors.php` — логика слияния (ядро изменения);
- `tests/Unit/Http/SetMaxFrameAncestorsTest.php` — переписать тесты;
- `README.md`, `AGENTS.md`, `config/laravel-max-client.php`, `.env.example` — документация;
- `.agents/release/RELEASE_NOTES_v1.0.8.md` — релиз-ноты.

Без изменений: `src/Support/Config.php` (accessors уже есть), `config` значения по умолчанию.
