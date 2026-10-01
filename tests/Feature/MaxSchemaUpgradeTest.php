<?php

declare(strict_types=1);

namespace GeekCo\LaravelMaxClient\Tests\Feature;

use GeekCo\LaravelMaxClient\Tests\TestCase;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Сценарий обновления установки, у которой пакет стоял до v1.2.0.
 *
 * У такой установки файлы создания таблиц и аддитивные миграции прежних
 * релизов (add_phone_verified_at, add_chat_id_index, add_chat_metadata) уже
 * помечены выполненными, поэтому переписанные create-файлы до неё не доходят:
 * `php artisan migrate` их не перезапускает. Новая миграция
 * 0001_01_01_000003_create_max_chat_users_table видна как невыполненная (migrator
 * сопоставляет записи по полному имени файла, а не по номеру) и создаёт таблицу
 * связей. Дальше форму реестров переводит команда `max:upgrade` — перенос данных
 * вынесен из миграций намеренно, см. докблок MaxSchemaUpgrade.
 */
final class MaxSchemaUpgradeTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->artisan('migrate:install')->run();
    }

    /**
     * Порядок обновления: обычный migrate создаёт таблицу связей, команда
     * переводит реестры. Ничего кроме этих двух шагов не требуется.
     */
    public function testMigrateThenUpgradeBringsSchemaToNewShape(): void
    {
        $this->simulateExistingInstall();

        $this->assertTrue(Schema::hasColumn('max_chats', 'user_id'));
        $this->assertFalse(Schema::hasTable('max_chat_users'));

        $this->migrate();

        $this->assertTrue(Schema::hasTable('max_chat_users'));
        $this->assertTrue(Schema::hasColumn('max_chats', 'user_id'), 'migrate не должен переносить данные сам');

        $this->upgrade();

        $this->assertFalse(Schema::hasColumn('max_chats', 'user_id'));
        $this->assertTrue($this->hasIndexOn('max_chats', ['chat_id'], true));
        $this->assertTrue($this->hasIndexOn('max_chats', ['status'], false), 'Индекс по status не добавлен');
        $this->assertTrue($this->hasIndexOn('max_chats', ['last_activity_at'], false), 'Индекс по last_activity_at не добавлен');
    }

    /**
     * Отметка проверки метаданных переносится под новым именем: она относится ко
     * всем метаданным getChat, а не только к названию. Время должно пережить
     * перенос, иначе после обновления все чаты разом попадут в очередь на
     * перепроверку.
     */
    public function testCheckedAtColumnIsRenamedWithItsValue(): void
    {
        $this->simulateExistingInstall();

        $checkedAt = now()->subDays(2);

        DB::table('max_chats')->insert([
            'user_id' => 111,
            'chat_id' => 777,
            'status' => 'active',
            'title' => 'Рабочий чат',
            'title_checked_at' => $checkedAt,
        ]);

        $this->migrate();
        $this->upgrade();

        $this->assertTrue(Schema::hasColumn('max_chats', 'chat_checked_at'));
        $this->assertFalse(Schema::hasColumn('max_chats', 'title_checked_at'));
        $this->assertSame(
            $checkedAt->format('Y-m-d H:i:s'),
            DB::table('max_chats')->value('chat_checked_at'),
            'Отметка проверки метаданных потеряна при переносе',
        );
    }

    /**
     * Установка v1.1.3 и старше аддитивных миграций метаданных не получала, а
     * пакет их больше не поставляет: колонок метаданных и отметки в базе нет.
     * Пересборка читает только то, что есть, поэтому обновление проходит, а
     * чат попадает в очередь на перепроверку.
     */
    public function testLegacyInstallWithoutMetadataColumnsUpgrades(): void
    {
        $this->simulateExistingInstall();

        Schema::table('max_chats', static function (Blueprint $table): void {
            $table->dropColumn(['title', 'description', 'link', 'icon_url', 'title_checked_at']);
        });

        DB::table('max_chats')->insert([
            'user_id' => 111,
            'chat_id' => 777,
            'status' => 'active',
        ]);

        $this->migrate();
        $this->upgrade();

        $this->assertTrue(Schema::hasColumn('max_chats', 'chat_checked_at'));
        $this->assertSame(1, DB::table('max_chats')->count());
        $this->assertNull(DB::table('max_chats')->value('chat_checked_at'));
        $this->assertSame([111], DB::table('max_chat_users')->pluck('user_id')->all());
    }

    /**
     * Колонку отметки телефона создавала аддитивная миграция v1.1.4, а переписанные
     * create-файлы до установки не доходят. Тот, кто обновлялся с v1.1.3, её не
     * имеет, а phone_verified_at пишет слушатель безусловно: без возврата колонки
     * запись телефона падала бы с ошибкой «no such column».
     */
    public function testPhoneVerifiedAtIsRestoredWhenMissing(): void
    {
        $this->simulateExistingInstall();

        Schema::table('max_users', static function (Blueprint $table): void {
            $table->dropColumn('phone_verified_at');
        });

        DB::table('max_users')->insert(['user_id' => 111, 'first_name' => 'Иван']);

        $this->assertFalse(Schema::hasColumn('max_users', 'phone_verified_at'));

        $this->migrate();
        $this->upgrade();

        $this->assertTrue(Schema::hasColumn('max_users', 'phone_verified_at'));
        $this->assertNull(DB::table('max_users')->where('user_id', 111)->value('phone_verified_at'));
    }

    /**
     * Колонка, которую создал прежний пакет, пересоздавать нельзя: повторное
     * объявление упало бы с дублирующимся именем. Идемпотентность команды это
     * проверяет вторым проходом.
     */
    public function testPhoneVerifiedAtIsNotRecreatedWhenAlreadyPresent(): void
    {
        $this->simulateExistingInstall();
        DB::table('max_users')->insert([
            'user_id' => 111,
            'first_name' => 'Иван',
            'phone_verified_at' => '2026-01-02 03:04:05',
        ]);

        $this->migrate();
        $this->upgrade();
        $this->upgrade();

        $this->assertSame('2026-01-02 03:04:05', DB::table('max_users')->where('user_id', 111)->value('phone_verified_at'));
    }

    /**
     * Если отметка в прежней таблице всё-таки есть, перенос её не теряет: чат не
     * должен попадать в очередь на перепроверку повторно, а по группе берётся
     * максимум, как и для активности.
     */
    public function testCheckedAtSurvivesWhenLegacyTableAlreadyHasIt(): void
    {
        $this->simulateExistingInstall();

        Schema::table('max_chats', static function (Blueprint $table): void {
            $table->timestamp('chat_checked_at')->nullable();
        });

        $old = now()->subDays(2);
        $fresh = now()->subDay();

        DB::table('max_chats')->insert([
            'user_id' => 111,
            'chat_id' => 777,
            'status' => 'active',
            'chat_checked_at' => $old,
        ]);
        DB::table('max_chats')->insert([
            'user_id' => 222,
            'chat_id' => 777,
            'status' => 'active',
            'chat_checked_at' => $fresh,
        ]);

        $this->migrate();
        $this->upgrade();

        $this->assertSame(
            $fresh->format('Y-m-d H:i:s'),
            DB::table('max_chats')->value('chat_checked_at'),
            'Отметка проверки метаданных потеряна при слиянии чата',
        );
    }

    /**
     * Чужие колонки прежней схемы не мешают: пересборка берёт белый список
     * колонок, поэтому посторонняя молча исчезает вместе с прежней таблицей.
     */
    public function testUnknownLegacyColumnsAreDropped(): void
    {
        $this->simulateExistingInstall();

        Schema::table('max_chats', static function (Blueprint $table): void {
            $table->string('legacy_note', 64)->nullable();
        });

        DB::table('max_chats')->insert([
            'user_id' => 111,
            'chat_id' => 777,
            'status' => 'active',
            'legacy_note' => 'из прошлой версии',
        ]);

        $this->migrate();
        $this->upgrade();

        $this->assertFalse(Schema::hasColumn('max_chats', 'legacy_note'));
        $this->assertSame(1, DB::table('max_chats')->count());
    }

    public function testExistingDataSurvivesUpgrade(): void
    {
        $this->simulateExistingInstall();

        DB::table('max_users')->insert(['user_id' => 111, 'first_name' => 'Иван', 'is_bot' => false]);
        DB::table('max_chats')->insert([
            'user_id' => 111,
            'chat_id' => 222,
            'status' => 'active',
            'chat_type' => 'chat',
        ]);

        $this->migrate();
        $this->upgrade();

        $this->assertSame(1, DB::table('max_users')->count());
        $this->assertSame('Иван', DB::table('max_users')->value('first_name'));

        $this->assertSame(1, DB::table('max_chats')->count());
        $this->assertSame(222, DB::table('max_chats')->value('chat_id'));

        $this->assertSame(1, DB::table('max_chat_users')->count());
        $this->assertSame(222, (int) DB::table('max_chat_users')->value('chat_id'));
        $this->assertSame(111, (int) DB::table('max_chat_users')->value('user_id'));
    }

    /**
     * Дробление на пользователей схлопывается: группа из пяти участников была
     * пятью строками, после обновления строка на чат одна.
     */
    public function testDuplicatedChatsAreMergedIntoOneRow(): void
    {
        $this->simulateExistingInstall();

        foreach ([111, 222, 333, 444, 555] as $userId) {
            DB::table('max_chats')->insert([
                'user_id' => $userId,
                'chat_id' => 999,
                'status' => 'active',
            ]);
        }

        DB::table('max_chats')->insert([
            'user_id' => 111,
            'chat_id' => 1000,
            'status' => 'active',
        ]);

        $this->migrate();
        $this->upgrade();

        $this->assertSame(2, DB::table('max_chats')->count());
        $this->assertSame(
            [999, 1000],
            DB::table('max_chats')->orderBy('chat_id')->pluck('chat_id')->map(static fn (mixed $id): int => (int) $id)->all(),
        );

        // Каждый участник сохранился в связи, ни одна пара не потеряна.
        $this->assertSame(6, DB::table('max_chat_users')->count());
    }

    /**
     * removed важнее active независимо от времени: если участник нажал start
     * после удаления бота, чат всё равно удалён. Иначе activeChatIds() продолжит
     * возвращать его, а max:chats:refresh — опрашивать getChat бесконечно.
     */
    public function testRemovedStatusWinsOverActive(): void
    {
        $this->simulateExistingInstall();

        DB::table('max_chats')->insert([
            'user_id' => 111,
            'chat_id' => 777,
            'status' => 'active',
            'last_activity_at' => now()->addDay(),
        ]);
        DB::table('max_chats')->insert([
            'user_id' => 222,
            'chat_id' => 777,
            'status' => 'removed',
            'last_activity_at' => now()->subDay(),
        ]);

        $this->migrate();
        $this->upgrade();

        $this->assertSame(1, DB::table('max_chats')->where('chat_id', 777)->count());
        $this->assertSame('removed', DB::table('max_chats')->where('chat_id', 777)->value('status'));
    }

    public function testStoppedStatusWinsOverActive(): void
    {
        $this->simulateExistingInstall();

        DB::table('max_chats')->insert(['user_id' => 111, 'chat_id' => 777, 'status' => 'active']);
        DB::table('max_chats')->insert(['user_id' => 222, 'chat_id' => 777, 'status' => 'stopped']);

        $this->migrate();
        $this->upgrade();

        $this->assertSame('stopped', DB::table('max_chats')->where('chat_id', 777)->value('status'));
    }

    /**
     * Метаданные не теряются: в старой схеме они дописывались в каждую строку
     * чата, и после слияния должна остаться одна строка с этими значениями.
     */
    public function testChatMetadataSurvivesMerge(): void
    {
        $this->simulateExistingInstall();

        DB::table('max_chats')->insert(['user_id' => 111, 'chat_id' => 777, 'status' => 'active']);
        DB::table('max_chats')->insert([
            'user_id' => 222,
            'chat_id' => 777,
            'status' => 'active',
            'title' => 'Рабочий чат',
            'description' => 'Описание',
            'link' => 'https://max.ru/chat/777',
            'icon_url' => 'https://max.ru/icon.png',
            'chat_type' => 'chat',
            'title_checked_at' => now(),
        ]);

        $this->migrate();
        $this->upgrade();

        $chat = DB::table('max_chats')->where('chat_id', 777)->first();

        $this->assertNotNull($chat);
        $this->assertSame('Рабочий чат', $chat->title);
        $this->assertSame('Описание', $chat->description);
        $this->assertSame('https://max.ru/chat/777', $chat->link);
        $this->assertSame('https://max.ru/icon.png', $chat->icon_url);
        $this->assertSame('chat', $chat->chat_type);
        $this->assertNotNull($chat->chat_checked_at);
    }

    /**
     * Последняя активность чата — максимум по группе, а не значение случайной
     * строки: по нему MaxChatsRefreshCommand решает, какие чаты пора проверить.
     */
    public function testLastActivityKeepsMaximum(): void
    {
        $this->simulateExistingInstall();

        $old = now()->subDays(3);
        $fresh = now();

        DB::table('max_chats')->insert([
            'user_id' => 111,
            'chat_id' => 777,
            'status' => 'active',
            'last_activity_at' => $old,
        ]);
        DB::table('max_chats')->insert([
            'user_id' => 222,
            'chat_id' => 777,
            'status' => 'active',
            'last_activity_at' => $fresh,
        ]);

        $this->migrate();
        $this->upgrade();

        $stored = (string) DB::table('max_chats')->where('chat_id', 777)->value('last_activity_at');

        $this->assertSame(
            $fresh->format('Y-m-d H:i:s'),
            Carbon::parse($stored)->format('Y-m-d H:i:s'),
        );
    }

    /**
     * Команда идемпотентна: повторный запуск на уже новой схеме ничего не
     * меняет и завершается успешно.
     */
    public function testSecondUpgradeChangesNothing(): void
    {
        $this->simulateExistingInstall();

        DB::table('max_chats')->insert(['user_id' => 111, 'chat_id' => 777, 'status' => 'active']);

        $this->migrate();
        $this->upgrade();

        $afterFirst = DB::table('max_chat_users')->count();

        $this->upgrade();

        $this->assertSame($afterFirst, DB::table('max_chat_users')->count());
        $this->assertSame(1, DB::table('max_chats')->count());
    }

    /**
     * Прерванный перенос: таблица max_chat_users уже создана, часть пар уже
     * перенесена. insertOrIgnore должен отсечь повтор по уникальному индексу
     * (chat_id, user_id), а не упасть.
     */
    public function testDuplicatePairsDoNotBreakUpgrade(): void
    {
        $this->simulateExistingInstall();
        $this->simulateInterruptedUpgrade();

        DB::table('max_chat_users')->insert([
            'id' => (string) Str::uuid(),
            'chat_id' => 777,
            'user_id' => 111,
            'status' => 'active',
        ]);

        DB::table('max_chats')->insert(['user_id' => 111, 'chat_id' => 777, 'status' => 'active']);

        $this->upgrade();

        $this->assertSame(1, DB::table('max_chat_users')->count());
    }

    /**
     * После обновления у строки чата нет суррогатного id: первичным ключом
     * становится сам chat_id из MAX, а uuid остаётся только у строки связи,
     * где суррогатный ключ действительно нужен — по нему создаётся запись
     * без идентификаторов от MAX.
     */
    public function testChatKeyBecomesChatIdAndLinkKeyStaysUuid(): void
    {
        $this->simulateExistingInstall();

        DB::table('max_chats')->insert(['user_id' => 111, 'chat_id' => 777, 'status' => 'active']);

        $this->migrate();
        $this->upgrade();

        $this->assertFalse(Schema::hasColumn('max_chats', 'id'), 'У строки чата остался суррогатный id');
        $this->assertTrue($this->hasIndexOn('max_chats', ['chat_id'], true), 'chat_id не стал первичным ключом');
        $this->assertTrue(
            Str::isUuid((string) DB::table('max_chat_users')->value('id')),
            'id связи остался значением счётчика',
        );
    }

    /**
     * Пересборка таблицы переносит chat_id копированием — знак идентификатора
     * при этом должен сохраниться, иначе отрицательные идентификаторы групп и
     * каналов превратились бы в положительные или обнулились.
     */
    public function testNegativeChatIdSurvivesUpgradeAndRollback(): void
    {
        $this->simulateExistingInstall();

        DB::table('max_chats')->insert(['user_id' => 111, 'chat_id' => -79032376695376, 'status' => 'active']);

        $this->migrate();
        $this->upgrade();

        $this->assertSame(-79032376695376, (int) DB::table('max_chats')->value('chat_id'));
        $this->assertSame(-79032376695376, (int) DB::table('max_chat_users')->value('chat_id'));

        $this->rollback();

        $this->assertSame(-79032376695376, (int) DB::table('max_chats')->value('chat_id'));
    }

    /**
     * max_users у установки до v1.2.0 создана прежней create-миграцией с
     * unsigned-колонкой, а переписанный файл до неё не доходит. Расширяет её
     * команда: alter() меняет тип на месте, первичный ключ при этом должен
     * уцелеть — иначе перестала бы работать и updateOrCreate.
     */
    public function testMaxUsersUserIdIsWidenedOnUpgrade(): void
    {
        $this->simulateExistingInstall();

        DB::table('max_users')->insert(['user_id' => 123, 'first_name' => 'Иван']);

        $this->migrate();
        $this->upgrade();

        $this->assertFalse(Schema::hasColumn('max_users', 'id'), 'У max_users появился суррогатный ключ');
        $this->assertTrue($this->hasIndexOn('max_users', ['user_id'], true), 'Первичный ключ max_users потерян при смене типа колонки');
        $this->assertTrue($this->hasIndexOn('max_users', ['phone'], false), 'Индекс по phone потерян при смене типа колонки');
        $this->assertSame(123, (int) DB::table('max_users')->value('user_id'));
    }

    /**
     * Сузить колонку обратно нельзя, если в базе уже лежит отрицательный
     * идентификатор: MySQL отверг бы такой ALTER, а в нестрогом режиме молча
     * заменил бы значение нулём. Откат поэтому проверяет данные и пропускает шаг.
     */
    public function testRollbackNarrowsUserIdOnlyWhenNoNegativeValues(): void
    {
        $this->simulateExistingInstall();

        $this->migrate();
        $this->upgrade();

        DB::table('max_users')->insert(['user_id' => -79032376695376, 'first_name' => 'Тест']);

        $this->rollback();

        $this->assertSame(-79032376695376, (int) DB::table('max_users')->value('user_id'));
    }

    /**
     * Откат возвращает форму до v1.2.0 целиком: колонка user_id, строки по парам
     * и суррогатный счётчик id. Пересборка таблицы вместо alter — единственный
     * способ вернуть auto-increment: сменить первичный ключ на месте нельзя.
     */
    public function testRollbackRestoresLegacyShape(): void
    {
        $this->simulateExistingInstall();

        $checkedAt = now()->subDays(2);

        DB::table('max_chats')->insert([
            'user_id' => 111,
            'chat_id' => 777,
            'status' => 'active',
            'title_checked_at' => $checkedAt,
        ]);
        DB::table('max_chats')->insert(['user_id' => 222, 'chat_id' => 777, 'status' => 'active']);

        $this->migrate();
        $this->upgrade();
        $this->assertFalse(Schema::hasColumn('max_chats', 'user_id'));

        $this->rollback();

        $this->assertTrue(Schema::hasColumn('max_chats', 'user_id'));
        $this->assertTrue(Schema::hasColumn('max_chats', 'title_checked_at'), 'Прежнее имя отметки не восстановлено');
        $this->assertFalse(Schema::hasColumn('max_chats', 'chat_checked_at'));
        $this->assertSame(
            $checkedAt->format('Y-m-d H:i:s'),
            DB::table('max_chats')->where('user_id', 111)->value('title_checked_at'),
            'Отметка проверки потеряна при откате',
        );
        $this->assertSame(2, DB::table('max_chats')->count());
        $this->assertSame(
            [111, 222],
            DB::table('max_chats')->orderBy('id')->pluck('user_id')->map(static fn (mixed $id): int => (int) $id)->all(),
            'Пары не развернулись по своим пользователям',
        );
        $this->assertTrue($this->hasIndexOn('max_chats', ['user_id', 'chat_id'], true));
        $this->assertTrue($this->hasIndexOn('max_chats', ['chat_id'], false), 'Обычный индекс по chat_id не восстановлен');
        $this->assertFalse($this->hasIndexOn('max_chats', ['chat_id'], true));

        DB::table('max_chats')->insert(['user_id' => 333, 'chat_id' => 888, 'status' => 'active']);

        $this->assertTrue(
            $this->hasIndexOn('max_chats', ['id'], true),
            'После отката счётчик id должен быть первичным ключом с автоинкрементом',
        );
    }

    /**
     * Чужая уникальность по chat_id не должна помешать переносу: таблица всё
     * равно пересобирается, а прежние индексы уходят вместе с ней.
     */
    public function testExtraUniqueOnChatIdDoesNotBreakUpgrade(): void
    {
        $this->simulateExistingInstall();

        DB::table('max_chats')->insert(['user_id' => 111, 'chat_id' => 777, 'status' => 'active']);

        Schema::table('max_chats', static function (Blueprint $table): void {
            $table->dropIndex(['chat_id']);
        });

        Schema::table('max_chats', static function (Blueprint $table): void {
            $table->unique('chat_id');
        });

        $this->migrate();
        $this->upgrade();

        $this->assertFalse(Schema::hasColumn('max_chats', 'user_id'));
        $this->assertTrue($this->hasIndexOn('max_chats', ['chat_id'], true));
        $this->assertFalse($this->hasIndexOn('max_chats', ['chat_id'], false));
        $this->assertSame(1, DB::table('max_chats')->count());
    }

    /**
     * После отката повторный перенос снова работает: разворачивание чатов в пары
     * не оставляет схему в непереносимом состоянии.
     */
    public function testUpgradeAfterRollbackWorksAgain(): void
    {
        $this->simulateExistingInstall();

        DB::table('max_chats')->insert(['user_id' => 111, 'chat_id' => 777, 'status' => 'active']);

        $this->migrate();
        $this->upgrade();
        $this->rollback();
        $this->upgrade();

        $this->assertFalse(Schema::hasColumn('max_chats', 'user_id'));
        $this->assertSame(1, DB::table('max_chats')->count());
        $this->assertSame(1, DB::table('max_chat_users')->count());
    }

    /**
     * Без таблицы связей переносить некуда, и сказать об этом нужно до того,
     * как пользователь согласится на изменение схемы.
     */
    public function testUpgradeFailsWithActionableErrorWhenLinksTableIsMissing(): void
    {
        $this->simulateExistingInstall();

        $this->artisan('max:upgrade', ['--force' => true])
            ->expectsOutputToContain('php artisan migrate')
            ->assertExitCode(1);

        $this->assertTrue(Schema::hasColumn('max_chats', 'user_id'), 'Схема изменена, хотя перенос невозможен');
    }

    /**
     * --dry-run показывает объём и ничего не применяют: перенос трогает данные,
     * и план должен быть виден до подтверждения.
     */
    public function testDryRunReportsPlanWithoutApplying(): void
    {
        $this->simulateExistingInstall();

        DB::table('max_chats')->insert(['user_id' => 111, 'chat_id' => 777, 'status' => 'active']);
        DB::table('max_chats')->insert(['user_id' => 222, 'chat_id' => 777, 'status' => 'active']);

        $this->migrate();

        $this->artisan('max:upgrade', ['--dry-run' => true])
            ->expectsOutputToContain('--dry-run')
            ->assertExitCode(0);

        $this->assertTrue(Schema::hasColumn('max_chats', 'user_id'), 'Режим просмотра изменил схему');
        $this->assertSame(0, DB::table('max_chat_users')->count());
    }

    /**
     * Без подтверждения в неинтерактивном окружении (релизный скрипт,
     * cron) команда обязана отказаться, а не переписать данные молча.
     */
    public function testUpgradeRefusesToRunWithoutConfirmation(): void
    {
        $this->simulateExistingInstall();

        DB::table('max_chats')->insert(['user_id' => 111, 'chat_id' => 777, 'status' => 'active']);

        $this->migrate();

        $this->artisan('max:upgrade', ['--no-interaction' => true])
            ->expectsOutputToContain('--force')
            ->assertExitCode(1);

        $this->assertTrue(Schema::hasColumn('max_chats', 'user_id'), 'Схема изменена без подтверждения');
    }

    /**
     * Схема в состоянии версий до v1.2.0: ровно то, что создавали
     * create_max_users_table, create_max_chats_table и аддитивные миграции
     * прежних релизов. Все пять помечены выполненными — иначе migrate применил бы
     * их повторно поверх уже созданных колонок.
     */
    private function simulateExistingInstall(): void
    {
        Schema::create('max_users', function (Blueprint $table): void {
            $table->unsignedBigInteger('user_id')->primary();
            $table->string('first_name', 128);
            $table->string('last_name', 128)->nullable();
            $table->string('username', 128)->nullable();
            $table->boolean('is_bot')->default(false);
            $table->unsignedBigInteger('last_activity_time')->nullable();
            $table->string('name', 256)->nullable();
            $table->text('description')->nullable();
            $table->string('avatar_url', 512)->nullable();
            $table->string('full_avatar_url', 512)->nullable();
            $table->string('phone', 32)->nullable();
            $table->string('email', 256)->nullable();
            $table->timestamp('phone_verified_at')->nullable();
            $table->timestamp('profile_checked_at')->nullable();
            $table->timestamps();

            $table->index('phone');
            $table->index('profile_checked_at');
        });

        Schema::create('max_chats', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('chat_id');
            $table->string('status', 16)->default('active');
            $table->string('chat_type', 16)->nullable();
            $table->string('title', 256)->nullable();
            $table->text('description')->nullable();
            $table->string('link', 512)->nullable();
            $table->string('icon_url', 512)->nullable();
            $table->timestamp('title_checked_at')->nullable();
            $table->timestamp('last_activity_at')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'chat_id']);
            $table->index('chat_id');
        });

        foreach ([
            '0001_01_01_000001_create_max_users_table',
            '0001_01_01_000002_create_max_chats_table',
            '0001_01_01_000003_add_phone_verified_at_to_max_users_table',
            '0001_01_01_000004_add_chat_id_index_to_max_chats_table',
            '0001_01_01_000005_add_chat_metadata_to_max_chats_table',
        ] as $name) {
            DB::table('migrations')->insert(['migration' => $name, 'batch' => 1]);
        }
    }

    /**
     * Таблица связей создана миграцией, но перенос данных не выполнен.
     */
    private function simulateInterruptedUpgrade(): void
    {
        Schema::create('max_chat_users', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->bigInteger('chat_id');
            $table->bigInteger('user_id');
            $table->string('status', 16)->default('active');
            $table->timestamp('last_activity_at')->nullable();
            $table->timestamps();

            $table->unique(['chat_id', 'user_id']);
            $table->index('user_id');
        });

        DB::table('migrations')->insert([
            'migration' => '0001_01_01_000003_create_max_chat_users_table',
            'batch' => 2,
        ]);
    }

    private function migrate(): void
    {
        $this->artisan('migrate')->assertExitCode(0);
    }

    private function upgrade(): void
    {
        $this->artisan('max:upgrade', ['--force' => true])->assertExitCode(0);
    }

    private function rollback(): void
    {
        $this->artisan('max:upgrade', ['--force' => true, '--rollback' => true])->assertExitCode(0);
    }

    /**
     * @param list<string> $columns
     */
    private function hasIndexOn(string $table, array $columns, bool $unique): bool
    {
        foreach (Schema::getIndexes($table) as $index) {
            if (!is_array($index)) {
                continue;
            }

            if (($index['columns'] ?? null) === $columns && ($index['unique'] ?? false) === $unique) {
                return true;
            }
        }

        return false;
    }

    protected function tearDown(): void
    {
        Schema::dropAllTables();

        parent::tearDown();
    }
}
