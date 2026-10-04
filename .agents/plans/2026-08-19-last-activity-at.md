# План: добавить `last_activity_at` в `max_bot_chats`

## Суть

Поле `last_activity_at` хранит время последней активности в чате (создание/обновление записи). Используется
потребителями пакета для сортировки диалогов по свежести. Сейчас это кастомное поле, которое каждый потребитель
добавляет сам — переносим в пакет.

## Файлы пакета для изменения

### 1. Миграция `database/migrations/0001_01_01_000002_create_max_bot_chats_table.php`

Добавить после `status`:

```php
$table->timestamp('last_activity_at')->nullable()->comment('Время последней активности в чате');
```

### 2. Модель `src/Models/BotChat.php`

- Добавить `'last_activity_at'` в `$fillable`
- Добавить `'last_activity_at' => 'datetime'` в `casts()`

### 3. Слушатель `src/Listeners/PersistBotChatListener.php`

В `handle()` — добавить `'last_activity_at' => now()` в массив данных для `updateOrCreate` чата:

```php
$chatModel::query()->updateOrCreate(
    [
        'user_id' => $userId,
        'chat_id' => $update->chatId,
    ],
    [
        'status' => $status,
        'last_activity_at' => now(),
    ],
);
```

### 4. Тесты `tests/Unit/Listeners/PersistBotChatListenerTest.php`

Добавить assertion что `last_activity_at` устанавливается при bot_added/bot_started:

```php
public function testBotAddedSetsLastActivityAt(): void
{
    $this->dispatch(UpdateType::BotAdded);

    $chat = BotChat::query()->sole();
    $this->assertNotNull($chat->last_activity_at);
}
```

## Обратная совместимость

- Миграция `nullable` — существующие записи не ломаются
- Потребители, которые добавляли поле сами (как мы), при обновлении пакета получат дублирование колонки — нужно будет
  убрать свою миграцию и кастомный код
- Default `null` — не влияет на тех, кто не использует поле

## Версия

`v1.0.9` (MINOR — обратно совместимое изменение)
