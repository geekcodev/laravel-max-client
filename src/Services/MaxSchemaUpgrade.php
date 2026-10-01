<?php

declare(strict_types=1);

namespace GeekCo\LaravelMaxClient\Services;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Перевод реестров, созданных версиями до v1.2.0, на одну строку max_chats
 * на чат с первичным ключом chat_id.
 *
 * Почему это команда, а не миграция. Create-миграции переписаны, но у
 * потребителя, который их уже выполнял, имена записаны в таблице migrations и
 * новые файлы повторно не запускаются — миграция не дошла бы. Обратная сторона
 * миграции: её нельзя показать заранее и нельзя откатить вручную, а перенос
 * трогает данные. Команда приезжает вместе с кодом пакета, видна в
 * `artisan list`, умеет показать план (`--dry-run`) и спросить подтверждение,
 * а идемпотентна в обе стороны.
 *
 * Порядок шагов обязателен и продиктован ограничениями СУБД:
 *   1. пары пользователей снимаются ДО удаления user_id;
 *   2. дубли чатов схлопываются ДО снятия уникального ключа — иначе на
 *      (user_id, chat_id) остались бы строки одного чата;
 *   3. пересборка таблицы идёт последней: пока есть колонка id и прежние
 *      индексы, работает обычная выборка, а в готовой форме её уже нет.
 *
 * Первичный ключ сменить на месте нельзя ни на одной поддерживаемой СУБД:
 * SQLite отклоняет удаление колонки первичного ключа («cannot drop PRIMARY KEY
 * column») и молча игнорирует primary() вне create, а MySQL при смене ключа
 * требует отдельной пары команд. Поэтому таблица пересобирается: создаётся
 * временная в целевой форме, строки копируются одним insert…select, временная
 * таблица занимает место старой и переименовывается. Одинаково на SQLite, MySQL
 * и PostgreSQL.
 *
 * Откат возвращает форму до v1.2.0 целиком: суррогатный id, колонка user_id и
 * unique(user_id, chat_id). user_id делается nullable — в прежней схеме он был
 * NOT NULL, но чат без зафиксированных пар терять хуже, и без NULL откат
 * падал бы на таком чате.
 */
final class MaxSchemaUpgrade
{
    /** Сколько пар переносить за одну вставку. */
    private const COPY_CHUNK = 500;

    /** Сколько чатов обрабатывать за один проход. */
    private const MERGE_CHUNK = 200;

    /** Промежуточное имя таблицы при пересборке. */
    private const TMP_TABLE = 'max_chats_rebuild';

    /** Статус важнее: removed > stopped > active. */
    private const STATUS_PRIORITY = ['removed' => 3, 'stopped' => 2, 'active' => 1];

    /** Колонки max_chats, значения которых переносятся в keeper из первых непустых. */
    private const METADATA_COLUMNS = ['title', 'description', 'link', 'icon_url'];

    /** Отметка проверки метаданных в форме до v1.2.0. */
    private const LEGACY_CHECKED_AT = 'title_checked_at';

    /** То же самое в форме v1.2.0. */
    private const CHECKED_AT = 'chat_checked_at';

    /**
     * Реестр ещё в форме до v1.2.0.
     *
     * Проверка по колонке user_id: на чистой установке её нет, и переносить
     * нечего — команда завершается сразу, ничего не создавая и не удаляя.
     */
    public function isLegacy(): bool
    {
        return Schema::hasColumn('max_chats', 'user_id');
    }

    /**
     * Реестр пар создан миграцией; без него переносить некуда.
     */
    public function linksTableExists(): bool
    {
        return Schema::hasTable('max_chat_users');
    }

    /**
     * Что именно будет сделано, без применения.
     *
     * @return array{chats: int, pairs: int, duplicateChats: int}
     */
    public function inspect(): array
    {
        if (!$this->isLegacy()) {
            return ['chats' => 0, 'pairs' => 0, 'duplicateChats' => 0];
        }

        return [
            'chats' => DB::table('max_chats')->distinct()->count('chat_id'),
            'pairs' => DB::table('max_chats')->whereNotNull('user_id')->count(),
            'duplicateChats' => DB::table('max_chats')
                ->select('chat_id', DB::raw('COUNT(*) as pairs_count'))
                ->groupBy('chat_id')
                ->having('pairs_count', '>', 1)
                ->get()
                ->count(),
        ];
    }

    /**
     * Перевести реестры на форму v1.2.0.
     *
     * @return array{chats: int, pairs: int, duplicateChats: int}
     *
     * @throws RuntimeException если таблица связей ещё не создана миграцией
     */
    public function upgrade(): array
    {
        if (!$this->isLegacy()) {
            return $this->inspect();
        }

        if (!$this->linksTableExists()) {
            throw new RuntimeException(
                'Нет таблицы max_chat_users. Сначала выполните `php artisan migrate`, '
                .'она создаёт реестр пар «чат + пользователь».',
            );
        }

        $plan = $this->inspect();

        $this->widenMaxUsersUserId();
        $this->restorePhoneVerifiedAt();
        $this->renameCheckedAtColumn();
        $this->copyUserPairs();
        $this->mergeChatDuplicates();
        $this->rebuildChatsTable();

        return $plan;
    }

    /**
     * Вернуть форму до v1.2.0.
     *
     * @return array{chats: int, pairs: int}
     */
    public function rollback(): array
    {
        if ($this->isLegacy()) {
            return ['chats' => 0, 'pairs' => 0];
        }

        $result = [
            'chats' => DB::table('max_chats')->count(),
            'pairs' => Schema::hasTable('max_chat_users')
                ? DB::table('max_chat_users')->count()
                : 0,
        ];

        $this->narrowMaxUsersUserId();
        $this->rebuildChatsTableLegacy();

        return $result;
    }

    /**
     * Расширить max_users.user_id до знакового BIGINT.
     *
     * Идентификаторы в MAX — знаковые int64, и у групп и каналов chat_id
     * отрицательный, поэтому unsigned-колонка на MySQL такой идентификатор не
     * сохранила бы. max_users у установки создана прежней create-миграцией, а
     * переписанный файл до неё не доходит; chat_id чинит пересборка max_chats
     * здесь же, а max_users приходится править на месте — в отличие от смены
     * ключа, смена типа форму таблицы не меняет. Расширение ничего не жертвует:
     * знаковая колонка принимает всё, что принимала unsigned.
     *
     * Первичный ключ не переобъявляется: MODIFY COLUMN в MySQL индексы не
     * снимает, а повторное объявление PRIMARY KEY в ALTER там лишнее.
     */
    private function widenMaxUsersUserId(): void
    {
        Schema::table('max_users', static function (Blueprint $table): void {
            $table->bigInteger('user_id')
                ->comment('Идентификатор пользователя в MAX (int64)')
                ->change();
        });
    }

    /**
     * Вернуть колонку отметки телефона, если её нет.
     *
     * Колонку создавала аддитивная миграция v1.1.4, а переписанные create-файлы
     * до установки не доходят: у той, кто обновляется с v1.1.3 и миновал не
     * запускал, её не будет, а phone_verified_at пишет PersistMaxUserPhoneListener
     * безусловно. Миграции больше нет — возвращать колонку приходится здесь,
     * тем же приёмом, что и знаковость.
     */
    private function restorePhoneVerifiedAt(): void
    {
        if (Schema::hasColumn('max_users', 'phone_verified_at')) {
            return;
        }

        Schema::table('max_users', static function (Blueprint $table): void {
            $table->timestamp('phone_verified_at')
                ->nullable()
                ->comment('Время получения подтверждённого телефона');
        });
    }

    /**
     * Сузить колонку обратно только если отрицательных значений в базе нет:
     * MySQL отверг бы такой ALTER, а в нестрогом режиме молча заменил бы
     * значения нулём, то есть потерял бы данные.
     */
    private function narrowMaxUsersUserId(): void
    {
        if (DB::table('max_users')->where('user_id', '<', 0)->exists()) {
            return;
        }

        Schema::table('max_users', static function (Blueprint $table): void {
            $table->unsignedBigInteger('user_id')
                ->comment('Идентификатор пользователя в MAX (int64)')
                ->change();
        });
    }

    /**
     * Пересобрать max_chats в форме v1.2.0: без суррогатного id, с первичным
     * ключом chat_id и индексами выборок реестра.
     */
    private function rebuildChatsTable(): void
    {
        Schema::dropIfExists(self::TMP_TABLE);

        Schema::create(self::TMP_TABLE, function (Blueprint $table): void {
            $table->bigInteger('chat_id')->primary()->comment('Идентификатор чата в MAX (int64, у групп и каналов отрицательный)');
            $table->string('status', 16)->default('active')->comment('Статус чата');
            $table->string('chat_type', 16)->nullable()->comment('Тип чата');
            $table->string('title', 256)->nullable()->comment('Название группы или канала');
            $table->text('description')->nullable()->comment('Описание чата');
            $table->string('link', 512)->nullable()->comment('Публичная ссылка на чат');
            $table->string('icon_url', 512)->nullable()->comment('URL иконки чата');
            $table->timestamp(self::CHECKED_AT)
                ->nullable()
                ->comment('Время последней проверки метаданных через getChat');
            $table->timestamp('last_activity_at')->nullable()->comment('Время последней активности в чате');
            $table->timestamps();

            $table->index('status');
            $table->index('last_activity_at');
        });

        $columns = $this->presentChatColumns(self::CHECKED_AT);

        DB::table(self::TMP_TABLE)->insertUsing(
            $columns,
            DB::table('max_chats')->select($columns),
        );

        Schema::drop('max_chats');
        Schema::rename(self::TMP_TABLE, 'max_chats');

        $this->renameRebuiltIndexes(['_status_index', '_last_activity_at_index']);
    }

    /**
     * Пересобрать max_chats в форме до v1.2.0: строка на пару «пользователь +
     * чат» с суррогатным счётчиком id.
     */
    private function rebuildChatsTableLegacy(): void
    {
        Schema::dropIfExists(self::TMP_TABLE);

        Schema::create(self::TMP_TABLE, function (Blueprint $table): void {
            $table->id();
            $table->bigInteger('user_id')->nullable()->comment('Идентификатор пользователя MAX (int64)');
            $table->bigInteger('chat_id')->comment('Идентификатор чата в MAX (int64, у групп и каналов отрицательный)');
            $table->string('status', 16)->default('active')->comment('Статус чата');
            $table->string('chat_type', 16)->nullable()->comment('Тип чата');
            $table->string('title', 256)->nullable()->comment('Название группы или канала');
            $table->text('description')->nullable()->comment('Описание чата');
            $table->string('link', 512)->nullable()->comment('Публичная ссылка на чат');
            $table->string('icon_url', 512)->nullable()->comment('URL иконки чата');
            $table->timestamp(self::LEGACY_CHECKED_AT)
                ->nullable()
                ->comment('Время последней проверки метаданных через getChat');
            $table->timestamp('last_activity_at')->nullable()->comment('Время последней активности в чате');
            $table->timestamps();

            $table->index('chat_id');
            $table->unique(['user_id', 'chat_id']);
        });

        $this->fillLegacyRows();

        Schema::drop('max_chats');
        Schema::rename(self::TMP_TABLE, 'max_chats');

        $this->renameRebuiltIndexes(['_chat_id_index', '_user_id_chat_id_unique']);
    }

    /**
     * Развернуть каждый чат обратно в строки по парам из max_chat_users.
     * user_id привязывается к каждой имеющейся паре; чат без пар получает одну
     * строку с NULL — колонка в форме до v1.2.0 была NOT NULL, но терять сам
     * факт чата хуже.
     *
     * Источник — форма v1.2.0 (отметка chat_checked_at), цель — форма до v1.2.0
     * (title_checked_at), поэтому отметка переименовывается прямо здесь, а не
     * отдельным alter: таблица всё равно пересобирается.
     */
    private function fillLegacyRows(): void
    {
        $columns = $this->chatColumns(self::CHECKED_AT);
        $chatIds = DB::table('max_chats')
            ->orderBy('chat_id')
            ->pluck('chat_id')
            ->all();

        $hasLinks = Schema::hasTable('max_chat_users');

        foreach (array_chunk($chatIds, self::MERGE_CHUNK) as $chatIdChunk) {
            $chats = DB::table('max_chats')
                ->select($columns)
                ->whereIn('chat_id', $chatIdChunk)
                ->orderBy('chat_id')
                ->get();

            $payload = [];

            foreach ($chats as $chat) {
                $userIds = $hasLinks
                    ? DB::table('max_chat_users')
                        ->where('chat_id', $chat->chat_id)
                        ->orderBy('user_id')
                        ->pluck('user_id')
                        ->all()
                    : [];

                if ($userIds === []) {
                    $userIds = [null];
                }

                $attributes = (array) $chat;
                $attributes[self::LEGACY_CHECKED_AT] = $attributes[self::CHECKED_AT] ?? null;
                unset($attributes[self::CHECKED_AT]);

                foreach ($userIds as $userId) {
                    $payload[] = $attributes + ['user_id' => $userId];
                }
            }

            if ($payload !== []) {
                DB::table(self::TMP_TABLE)->insert($payload);
            }
        }
    }

    /**
     * Индексы пересобранной таблицы названы по промежуточному имени и после
     * переименования таблицы сохраняют его: на SQLite имена индексов часть
     * схемы, на MySQL и PostgreSQL переименование таблицы их тоже не трогает.
     * Без переименования в базе остаются имена вида max_chats_rebuild_status_index.
     *
     * @param list<string> $suffixes окончания имён индексов
     */
    private function renameRebuiltIndexes(array $suffixes): void
    {
        $existing = [];

        foreach (Schema::getIndexes('max_chats') as $index) {
            if (is_array($index) && is_string($index['name'] ?? null)) {
                $existing[] = $index['name'];
            }
        }

        Schema::table('max_chats', static function (Blueprint $table) use ($suffixes, $existing): void {
            foreach ($suffixes as $suffix) {
                $from = self::TMP_TABLE . $suffix;

                if (in_array($from, $existing, true)) {
                    $table->renameIndex($from, 'max_chats' . $suffix);
                }
            }
        });
    }

    /**
     * Перенести пары «чат + пользователь» из max_chats в max_chat_users.
     *
     * insertOrIgnore, а не insert: пара может повториться, если у потребителя
     * схема уже частично переведена. chunkById требует колонку id в выборке,
     * поэтому она выбирается явно — без неё он падает с «the [id] column is
     * not present in the query result».
     */
    private function copyUserPairs(): void
    {
        $now = now();

        DB::table('max_chats')
            ->select(['id', 'chat_id', 'user_id', 'status', 'last_activity_at'])
            ->whereNotNull('user_id')
            ->orderBy('id')
            ->chunkById(self::COPY_CHUNK, static function ($rows) use ($now): void {
                $payload = [];

                foreach ($rows as $row) {
                    $payload[] = [
                        'id' => (string) Str::uuid(),
                        'chat_id' => $row->chat_id,
                        'user_id' => $row->user_id,
                        'status' => $row->status,
                        'last_activity_at' => $row->last_activity_at,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                }

                if ($payload !== []) {
                    DB::table('max_chat_users')->insertOrIgnore($payload);
                }
            });
    }

    /**
     * Свернуть строки одного чата в одну.
     *
     * Метаданные берутся из первых непустых в порядке id: после слияния останется
     * одна строка, и какое значение в ней будет, то и увидит приложение.
     * last_activity_at — максимум, по нему MaxChatsRefreshCommand решает, какие
     * чаты пора проверить. created_at — минимум, чтобы не терять дату появления
     * чата в реестре.
     *
     * Работает по прежней колонке id: пересборка таблицы идёт после слияния.
     */
    private function mergeChatDuplicates(): void
    {
        /** @var list<int|string> $chatIds */
        $chatIds = DB::table('max_chats')
            ->select('chat_id')
            ->distinct()
            ->orderBy('chat_id')
            ->pluck('chat_id')
            ->all();

        foreach (array_chunk($chatIds, self::MERGE_CHUNK) as $chatIdChunk) {
            foreach ($this->chatRows($chatIdChunk) as $group) {
                $this->mergeChatGroup($group);
            }
        }
    }

    /**
     * Строки чатов заданной выборки, сгруппированные по chat_id.
     *
     * Ключ приводится к строке явно: идентификаторы MAX — int64, и в разных драйверах
     * приходят то int, то строкой, а ключ массива должен совпадать в обоих случаях.
     *
     * @param list<int|string> $chatIds
     *
     * @return array<string, list<array<string, mixed>>>
     */
    private function chatRows(array $chatIds): array
    {
        $rows = DB::table('max_chats')
            ->select(array_merge(['id'], $this->presentChatColumns(self::CHECKED_AT)))
            ->whereIn('chat_id', $chatIds)
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();

        /** @var array<string, list<array<string, mixed>>> $grouped */
        $grouped = [];

        foreach ($rows as $row) {
            /** @var array<string, mixed> $values */
            $values = get_object_vars($row);
            $chatId = $values['chat_id'] ?? null;

            if (!is_int($chatId) && !is_string($chatId)) {
                continue;
            }

            $grouped[(string) $chatId][] = $values;
        }

        return $grouped;
    }

    /**
     * @param list<array<string, mixed>> $group строки одного чата в порядке id
     */
    private function mergeChatGroup(array $group): void
    {
        $keeper = $group[0]['id'];

        $update = [];

        foreach (self::METADATA_COLUMNS as $column) {
            $value = $this->firstNonEmpty($group, $column);

            if ($value !== null) {
                $update[$column] = $value;
            }
        }

        $chatType = $this->firstNonEmpty($group, 'chat_type');
        if ($chatType !== null) {
            $update['chat_type'] = $chatType;
        }

        $checkedAt = $this->extreme($group, self::CHECKED_AT, true);
        if ($checkedAt !== null) {
            $update[self::CHECKED_AT] = $checkedAt;
        }

        $lastActivityAt = $this->extreme($group, 'last_activity_at', true);
        if ($lastActivityAt !== null) {
            $update['last_activity_at'] = $lastActivityAt;
        }

        $createdAt = $this->extreme($group, 'created_at', false);
        if ($createdAt !== null) {
            $update['created_at'] = $createdAt;
        }

        $statuses = array_map(
            static fn (array $row): ?string => is_string($row['status'] ?? null) ? $row['status'] : null,
            $group,
        );

        $status = $this->dominantStatus($statuses);
        if ($status !== null) {
            $update['status'] = $status;
        }

        DB::table('max_chats')->where('id', $keeper)->update($update);

        $extraIds = array_values(array_filter(
            array_column($group, 'id'),
            static fn (mixed $id): bool => $id !== $keeper,
        ));

        if ($extraIds !== []) {
            DB::table('max_chats')->whereIn('id', $extraIds)->delete();
        }
    }

    /**
     * removed > stopped > active.
     *
     * «Последний по времени» здесь не годится: событие, оставившее в группе
     * active, могло прийти позже удаления. Потеря removed означает, что
     * activeChatIds() продолжит возвращать удалённый чат, а max:chats:refresh
     * будет опрашивать getChat по нему бесконечно.
     *
     * @param list<string|null> $statuses
     */
    private function dominantStatus(array $statuses): ?string
    {
        $best = null;
        $bestPriority = 0;

        foreach ($statuses as $status) {
            if (!is_string($status)) {
                continue;
            }

            $priority = self::STATUS_PRIORITY[$status] ?? 0;

            if ($priority > $bestPriority) {
                $bestPriority = $priority;
                $best = $status;
            }
        }

        return $best;
    }

    /**
     * @param list<array<string, mixed>> $group
     */
    private function firstNonEmpty(array $group, string $column): mixed
    {
        foreach ($group as $row) {
            $value = $row[$column] ?? null;

            if ($value !== null && $value !== '') {
                return $value;
            }
        }

        return null;
    }

    /**
     * @param list<array<string, mixed>> $group
     */
    private function extreme(array $group, string $column, bool $max): mixed
    {
        $values = [];

        foreach ($group as $row) {
            $value = $row[$column] ?? null;

            if ($value !== null) {
                $values[] = $value;
            }
        }

        if ($values === []) {
            return null;
        }

        return $max ? max($values) : min($values);
    }

    /**
     * Колонки max_chats. Имя отметки проверки метаданных отличается в форме до
     * v1.2.0 (title_checked_at) и в новой (chat_checked_at).
     *
     * @return list<string>
     */
    private function chatColumns(string $checkedAt): array
    {
        return [
            'chat_id', 'status', 'chat_type', 'title', 'description', 'link',
            'icon_url', $checkedAt, 'last_activity_at', 'created_at', 'updated_at',
        ];
    }

    /**
     * Из перечисленных колонок те, что есть в текущей таблице.
     *
     * Форма до v1.2.0 приходит из нескольких версий: у установки v1.1.3 и
     * старше есть колонки метаданных, у более ранней — нет, и аддитивные
     * миграции, которые их создавали, пакет больше не поставляет. Читать
     * отсутствующую колонку нельзя, а пропущенная при пересборке просто
     * получит значение по умолчанию. Заодно это делает пересборку терпимой к
     * любым посторонним колонкам прежней схемы.
     *
     * @return list<string>
     */
    private function presentChatColumns(string $checkedAt): array
    {
        return array_values(array_filter(
            $this->chatColumns($checkedAt),
            static fn (string $column): bool => Schema::hasColumn('max_chats', $column),
        ));
    }

    /**
     * Переименовать прежнее имя отметки проверки в новое.
     *
     * Отметка ставится один раз на проверку getChat, то есть относится ко всем
     * метаданным сразу (название, описание, ссылка, иконка), а не только к
     * одному названию, — прежнее имя вводило в заблуждение.
     *
     * Переименование идёт до переноса пар и слияния: дальше колонка нужна уже
     * под новым именем. Повторный запуск безопасен — если целевой колонки нет,
     * шаг пропускается.
     */
    private function renameCheckedAtColumn(): void
    {
        if (!Schema::hasColumn('max_chats', self::LEGACY_CHECKED_AT)
            || Schema::hasColumn('max_chats', self::CHECKED_AT)) {
            return;
        }

        Schema::table('max_chats', static function (Blueprint $table): void {
            $table->renameColumn(self::LEGACY_CHECKED_AT, self::CHECKED_AT);
        });
    }
}
