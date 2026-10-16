<?php

declare(strict_types=1);

namespace GeekCo\LaravelMaxClient\Tests\Unit\WebApp;

use GeekCo\LaravelMaxClient\MaxServiceProvider;
use GeekCo\LaravelMaxClient\Tests\TestCase;
use GeekCo\LaravelMaxClient\WebApp\WebAppContactService;
use GeekCo\LaravelMaxClient\Support\Config;
use GeekCo\LaravelMaxClient\Support\Logger;
use GeekCo\MaxPhpClient\Dto\WebAppContact;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Log;

final class WebAppContactServiceTest extends TestCase
{
    private const string TOKEN = 'test-token';

    private const string PHONE = '79250000000';

    private const string USER_ID = '67890';

    /**
     * Синтетическая подпись по схеме моста requestContact:
     * HMAC-SHA256("authDate=<v>\nphone=<v>\nuserId=<v>", bot_token).
     * Номер в подписи — без «+» и разделителей (нормализация до цифр).
     */
    private function hash(string $authDate, string $phone = self::PHONE, string $userId = self::USER_ID): string
    {
        $payload = "authDate={$authDate}\nphone={$phone}\nuserId={$userId}";

        return hash_hmac('sha256', $payload, self::TOKEN);
    }

    public function testResolveReturnsConfirmedContact(): void
    {
        $authDate = (string) time();

        $contact = $this->service()->resolve($authDate, self::PHONE, self::USER_ID, $this->hash($authDate));

        $this->assertInstanceOf(WebAppContact::class, $contact);
        $this->assertSame(67890, $contact->userId);
        $this->assertSame(self::PHONE, $contact->phone);
        $this->assertSame((int) $authDate, $contact->authDate);
    }

    public function testVerifyReturnsTrueForValidSignature(): void
    {
        $authDate = (string) time();

        $this->assertTrue(
            $this->service()->verify($authDate, self::PHONE, self::USER_ID, $this->hash($authDate)),
        );
    }

    public function testBase64HashIsAccepted(): void
    {
        $authDate = (string) time();
        $payload = "authDate={$authDate}\nphone=" . self::PHONE . "\nuserId=" . self::USER_ID;
        $hash = base64_encode(hash_hmac('sha256', $payload, self::TOKEN, true));

        $this->assertTrue($this->service()->verify($authDate, self::PHONE, self::USER_ID, $hash));
    }

    public function testUrlSafeBase64HashIsAccepted(): void
    {
        $authDate = (string) time();
        $payload = "authDate={$authDate}\nphone=" . self::PHONE . "\nuserId=" . self::USER_ID;
        $hash = rtrim(strtr(base64_encode(hash_hmac('sha256', $payload, self::TOKEN, true)), '+/', '-_'), '=');

        $this->assertTrue($this->service()->verify($authDate, self::PHONE, self::USER_ID, $hash));
    }

    public function testTamperedHashIsRejected(): void
    {
        $authDate = (string) time();

        $this->assertNull(
            $this->service()->resolve($authDate, self::PHONE, self::USER_ID, str_repeat('a', 64)),
        );
    }

    public function testSubstitutedUserIdIsRejected(): void
    {
        $authDate = (string) time();

        $this->assertNull(
            $this->service()->resolve($authDate, self::PHONE, self::USER_ID, $this->hash($authDate, userId: '11111')),
        );
    }

    public function testSubstitutedPhoneIsRejected(): void
    {
        $authDate = (string) time();

        $this->assertNull(
            $this->service()->resolve($authDate, '79250000001', self::USER_ID, $this->hash($authDate)),
        );
    }

    /**
     * Фронт может прислать номер с «+» и разделителями: подпись считается по
     * цифрам, адаптер эту нормализацию делегирует ядру.
     */
    public function testFormattedPhoneIsNormalizedBeforeSignatureCheck(): void
    {
        $authDate = (string) time();

        $contact = $this->service()->resolve($authDate, '+7 925 000-00-00', self::USER_ID, $this->hash($authDate));

        $this->assertInstanceOf(WebAppContact::class, $contact);
        $this->assertSame(self::PHONE, $contact->phone);
    }

    public function testStaleAuthDateIsRejected(): void
    {
        $authDate = (string) (time() - 90000);

        $this->assertNull(
            $this->service()->resolve($authDate, self::PHONE, self::USER_ID, $this->hash($authDate)),
        );
    }

    public function testStaleAuthDateIsAcceptedWhenMaxAgeIsZero(): void
    {
        $authDate = (string) (time() - 90000);

        $this->assertTrue(
            $this->service(maxAge: 0)->verify($authDate, self::PHONE, self::USER_ID, $this->hash($authDate)),
        );
    }

    public function testEmptyInputsAreRejected(): void
    {
        $authDate = (string) time();
        $service = $this->service();
        $hash = $this->hash($authDate);

        $this->assertNull($service->resolve('', self::PHONE, self::USER_ID, $hash));
        $this->assertNull($service->resolve($authDate, '', self::USER_ID, $hash));
        $this->assertNull($service->resolve($authDate, self::PHONE, '', $hash));
        $this->assertNull($service->resolve($authDate, self::PHONE, self::USER_ID, ''));
    }

    public function testFailureLogsWithoutPhoneAndHash(): void
    {
        $this->app['config']->set(MaxServiceProvider::CONFIG_KEY . '.logging.enabled', true);
        $logged = [];
        Log::listen(static function (MessageLogged $event) use (&$logged): void {
            $logged[] = $event;
        });

        $authDate = (string) time();
        $hash = str_repeat('a', 64);
        $contact = $this->service()->resolve($authDate, self::PHONE, self::USER_ID, $hash);

        $this->assertNull($contact);
        $this->assertCount(1, $logged);
        $this->assertSame('warning', $logged[0]->level);
        $this->assertSame(67890, $logged[0]->context['user_id']);

        $emitted = json_encode([$logged[0]->message, $logged[0]->context], JSON_UNESCAPED_UNICODE);
        $this->assertIsString($emitted);
        $this->assertStringNotContainsString(self::PHONE, $emitted);
        $this->assertStringNotContainsString($hash, $emitted);
    }

    public function testFailureWithNonNumericUserIdLogsNullUserId(): void
    {
        $this->app['config']->set(MaxServiceProvider::CONFIG_KEY . '.logging.enabled', true);
        $logged = [];
        Log::listen(static function (MessageLogged $event) use (&$logged): void {
            $logged[] = $event;
        });

        $authDate = (string) time();
        $this->service()->resolve($authDate, self::PHONE, 'not-a-number', $this->hash($authDate));

        $this->assertCount(1, $logged);
        $this->assertNull($logged[0]->context['user_id']);
    }

    public function testSuccessDoesNotLog(): void
    {
        $this->app['config']->set(MaxServiceProvider::CONFIG_KEY . '.logging.enabled', true);
        $logged = [];
        Log::listen(static function (MessageLogged $event) use (&$logged): void {
            $logged[] = $event;
        });

        $authDate = (string) time();
        $this->service()->verify($authDate, self::PHONE, self::USER_ID, $this->hash($authDate));

        $this->assertSame([], $logged);
    }

    private function service(?int $maxAge = null): WebAppContactService
    {
        config()->set(MaxServiceProvider::CONFIG_KEY . '.api_token', self::TOKEN);

        if ($maxAge !== null) {
            config()->set(MaxServiceProvider::CONFIG_KEY . '.webapp.max_age', $maxAge);
        }

        return new WebAppContactService(
            $this->app->make(Config::class),
            $this->app->make(Logger::class),
        );
    }
}
