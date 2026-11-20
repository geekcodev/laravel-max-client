<?php

declare(strict_types=1);

namespace GeekCo\LaravelMaxClient\Tests\Unit\Listeners;

use GeekCo\LaravelMaxClient\MaxServiceProvider;
use GeekCo\LaravelMaxClient\Models\MaxUser;
use GeekCo\LaravelMaxClient\Tests\TestCase;
use GeekCo\LaravelMaxClient\WebApp\WebAppContactVerified;
use GeekCo\MaxPhpClient\Dto\WebAppContact;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;

final class PersistWebAppContactPhoneListenerTest extends TestCase
{
    use RefreshDatabase;

    private const string PHONE = '79250000000';

    private string $logPath = '';

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $this->logPath = sys_get_temp_dir() . '/max-webapp-contact-test-' . getmypid() . '.log';
        @unlink($this->logPath);

        $app['config']->set(MaxServiceProvider::CONFIG_KEY . '.logging.enabled', true);
        $app['config']->set(MaxServiceProvider::CONFIG_KEY . '.logging.channel', 'max_test');
        $app['config']->set('logging.channels.max_test', [
            'driver' => 'single',
            'path' => $this->logPath,
            'level' => 'debug',
        ]);
    }

    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(__DIR__ . '/../../../database/migrations');
    }

    protected function tearDown(): void
    {
        if ($this->logPath !== '' && is_file($this->logPath)) {
            @unlink($this->logPath);
        }

        parent::tearDown();
    }

    public function testPhoneAndTimestampAreWrittenForKnownUser(): void
    {
        $this->makeUser(111);

        $this->dispatch('Иван Петров');

        $user = MaxUser::query()->findOrFail(111);

        $this->assertSame(self::PHONE, $user->phone);
        $this->assertNotNull($user->phone_verified_at);
    }

    public function testUserRecordIsCreatedWithNameWhenMissing(): void
    {
        $this->dispatch('Иван Петров');

        $user = MaxUser::query()->findOrFail(111);

        $this->assertSame('Иван', $user->first_name);
        $this->assertSame('Петров', $user->last_name);
        $this->assertSame('Иван Петров', $user->name);
        $this->assertSame(self::PHONE, $user->phone);
        $this->assertNotNull($user->phone_verified_at);
    }

    public function testExistingNameIsNotOverwritten(): void
    {
        $this->makeUser(111, firstName: 'Пётр');

        $this->dispatch('Клиент');

        $this->assertSame('Пётр', MaxUser::query()->findOrFail(111)->first_name);
    }

    public function testDifferentPhoneReplacesStoredOne(): void
    {
        $this->makeUser(111, phone: '+79251112233');

        $this->dispatch();

        $user = MaxUser::query()->findOrFail(111);

        $this->assertSame(self::PHONE, $user->phone);
        $this->assertNotNull($user->phone_verified_at);
    }

    public function testKnownPhoneRefreshesVerificationTimestamp(): void
    {
        $checkedAt = now()->subDay();
        $this->makeUser(111, phone: self::PHONE);
        MaxUser::query()->where('user_id', 111)->update(['phone_verified_at' => $checkedAt]);

        $this->dispatch();

        $this->assertTrue(MaxUser::query()->findOrFail(111)->phone_verified_at->greaterThan($checkedAt));
    }

    public function testCaptureRequiresNoConfiguration(): void
    {
        $this->assertNull(config(MaxServiceProvider::CONFIG_KEY . '.users.capture_phone'));

        $this->makeUser(111);
        $this->dispatch();

        $this->assertSame(self::PHONE, MaxUser::query()->findOrFail(111)->phone);
    }

    public function testContactWithoutUserIdIsIgnored(): void
    {
        $this->dispatch(contact: new WebAppContact(userId: null, authDate: time(), phone: self::PHONE));

        $this->assertSame(0, MaxUser::query()->count());
    }

    public function testContactWithoutPhoneIsIgnored(): void
    {
        $this->dispatch(contact: new WebAppContact(userId: 111, authDate: time(), phone: null));

        $this->assertSame(0, MaxUser::query()->count());
    }

    public function testPhoneChangeIsLoggedWithoutPhoneValues(): void
    {
        $this->makeUser(111, phone: '+79251112233');

        $this->dispatch();
        Log::channel('max_test')->warning('flush');

        $log = is_file($this->logPath) ? (string) file_get_contents($this->logPath) : '';

        $this->assertStringContainsString('differs from stored value', $log);
        $this->assertStringNotContainsString('+79251112233', $log);
        $this->assertStringNotContainsString(self::PHONE, $log);
    }

    private function dispatch(string $name = 'Иван Петров', ?WebAppContact $contact = null): void
    {
        $contact ??= new WebAppContact(userId: 111, authDate: time(), phone: self::PHONE);

        $this->app->make(Dispatcher::class)->dispatch(new WebAppContactVerified($contact, $name));
    }

    private function makeUser(int $userId, ?string $phone = null, string $firstName = 'Иван'): MaxUser
    {
        return MaxUser::query()->create([
            'user_id' => $userId,
            'first_name' => $firstName,
            'phone' => $phone,
        ]);
    }
}
