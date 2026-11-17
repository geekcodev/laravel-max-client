<?php

declare(strict_types=1);

namespace GeekCo\LaravelMaxClient\Tests\Unit\Listeners;

use GeekCo\LaravelMaxClient\Enums\MaxChatStatus;
use GeekCo\LaravelMaxClient\MaxServiceProvider;
use GeekCo\LaravelMaxClient\Models\MaxChat;
use GeekCo\LaravelMaxClient\Models\MaxUser;
use GeekCo\LaravelMaxClient\Tests\TestCase;
use GeekCo\LaravelMaxClient\Webhook\MaxUpdateReceived;
use GeekCo\MaxPhpClient\Dto\Attachment;
use GeekCo\MaxPhpClient\Dto\Attachment\ContactAttachment;
use GeekCo\MaxPhpClient\Dto\Callback;
use GeekCo\MaxPhpClient\Dto\CommentMessage;
use GeekCo\MaxPhpClient\Dto\CommentMessageBody;
use GeekCo\MaxPhpClient\Dto\ContactAttachmentPayload;
use GeekCo\MaxPhpClient\Dto\Message;
use GeekCo\MaxPhpClient\Dto\MessageBody;
use GeekCo\MaxPhpClient\Dto\Recipient;
use GeekCo\MaxPhpClient\Dto\Update;
use GeekCo\MaxPhpClient\Dto\Update\BotAddedToChatUpdate;
use GeekCo\MaxPhpClient\Dto\Update\CommentCreatedUpdate;
use GeekCo\MaxPhpClient\Dto\Update\MessageCallbackUpdate;
use GeekCo\MaxPhpClient\Dto\Update\MessageCreatedUpdate;
use GeekCo\MaxPhpClient\Dto\User;
use GeekCo\MaxPhpClient\Enum\ChatType;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;

final class PersistMaxUserPhoneListenerTest extends TestCase
{
    use RefreshDatabase;

    private const string TOKEN = 'test-token';

    private const string PHONE = '79250000000';

    private string $logPath = '';

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $this->logPath = sys_get_temp_dir() . '/max-phone-test-' . getmypid() . '.log';
        @unlink($this->logPath);

        $app['config']->set(MaxServiceProvider::CONFIG_KEY . '.api_token', self::TOKEN);
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

        $this->dispatch($this->contactUpdate());

        $user = MaxUser::query()->findOrFail(111);

        $this->assertSame(self::PHONE, $user->phone);
        $this->assertNotNull($user->phone_verified_at);
    }

    public function testUserRecordIsCreatedWhenMissing(): void
    {
        $this->dispatch($this->contactUpdate());

        $user = MaxUser::query()->findOrFail(111);

        $this->assertSame(self::PHONE, $user->phone);
        $this->assertNotNull($user->phone_verified_at);
        $this->assertSame('Иван', $user->first_name);
    }

    public function testChatIsNotCreatedAndStatusUnchanged(): void
    {
        $this->makeUser(111);
        MaxChat::create([
            'user_id' => 111,
            'chat_id' => 222,
            'status' => MaxChatStatus::Stopped,
        ]);

        $this->dispatch($this->contactUpdate());

        $this->assertSame(1, MaxChat::query()->count());
        $this->assertSame(MaxChatStatus::Stopped, MaxChat::query()->sole()->status);
    }

    /**
     * Отдельного разрешения на захват нет: контакт request_contact формируется
     * платформой из номера аккаунта отправителя, поэтому телефон пишется всегда,
     * без каких-либо настроек.
     */
    public function testCaptureRequiresNoConfiguration(): void
    {
        $this->assertNull(config(MaxServiceProvider::CONFIG_KEY . '.users.capture_phone'));

        $this->makeUser(111);
        $this->dispatch($this->contactUpdate());

        $this->assertSame(self::PHONE, MaxUser::query()->findOrFail(111)->phone);
    }

    public function testUntrustedContactIsIgnored(): void
    {
        $this->makeUser(111);
        $this->dispatch($this->contactUpdate(hash: str_repeat('b', 64)));

        $this->assertNull(MaxUser::query()->findOrFail(111)->phone);
    }

    /**
     * Регрессия ядра v1.1.11: контакт без `hash` (поле nullable в спеке) больше
     * не роняет апдейт, но подпись не проходит, поэтому телефон не сохраняется.
     */
    public function testContactDecodedWithoutHashIsIgnored(): void
    {
        $this->makeUser(111);

        $vcf = "BEGIN:VCARD\r\nVERSION:3.0\r\nTEL;TYPE=cell:" . self::PHONE . "\r\nEND:VCARD\r\n";

        $payload = [
            'update_type' => 'message_created',
            'timestamp' => 1700000000000,
            'chat_id' => 222,
            'user' => $this->user()->toArray(),
            'message' => [
                'sender' => $this->user()->toArray(),
                'recipient' => ['chat_id' => 222, 'chat_type' => 'dialog'],
                'timestamp' => 1700000000000,
                'body' => [
                    'mid' => 'b1',
                    'seq' => 0,
                    'attachments' => [
                        [
                            'type' => 'contact',
                            'payload' => ['vcf_info' => $vcf],
                        ],
                    ],
                ],
            ],
        ];

        $this->dispatch(Update::fromArray($payload));

        $this->assertNull(MaxUser::query()->findOrFail(111)->phone);
    }

    public function testMessageWithoutContactIsIgnored(): void
    {
        $this->makeUser(111);

        $this->dispatch(new MessageCreatedUpdate(
            message: $this->message([]),
            timestamp: 1000,
        ));

        $this->assertNull(MaxUser::query()->findOrFail(111)->phone);
    }

    public function testCommentUpdateIsIgnored(): void
    {
        $this->makeUser(111);

        $this->dispatch(new CommentCreatedUpdate(
            message: new CommentMessage(
                recipient: new Recipient(chatId: 222, chatType: ChatType::Channel, postId: 'mid_post'),
                timestamp: 1000,
                body: new CommentMessageBody(mid: 'c1', seq: 0),
                sender: $this->user(),
            ),
            timestamp: 1000,
        ));

        $this->assertNull(MaxUser::query()->findOrFail(111)->phone);
    }

    public function testBotAddedIsIgnored(): void
    {
        $this->makeUser(111);

        $this->dispatch(new BotAddedToChatUpdate(
            chatId: 222,
            user: $this->user(),
            isChannel: false,
            timestamp: 1000,
        ));

        $this->assertNull(MaxUser::query()->findOrFail(111)->phone);
    }

    /**
     * Расхождение с сохранённым значением означает, что пользователь сменил номер
     * аккаунта (регистрация в MAX возможна на один номер), поэтому новое значение
     * побеждает — иначе он остался бы навсегда привязан к старому.
     */
    public function testDifferentPhoneReplacesStoredOne(): void
    {
        $this->makeUser(111, phone: '+79251112233');
        MaxUser::query()->where('user_id', 111)->update(['phone_verified_at' => now()->subDay()]);

        $this->dispatch($this->contactUpdate());

        $user = MaxUser::query()->findOrFail(111);

        $this->assertSame(self::PHONE, $user->phone);
        $this->assertNotNull($user->phone_verified_at);
    }

    /**
     * Отметка всегда описывает текущее сохранённое значение: пришедший контакт
     * подтверждает номер заново, поэтому отметка двигается вперёд.
     */
    public function testKnownPhoneRefreshesVerificationTimestamp(): void
    {
        $checkedAt = now()->subDay();
        $this->makeUser(111, phone: self::PHONE);
        MaxUser::query()->where('user_id', 111)->update(['phone_verified_at' => $checkedAt]);

        $this->dispatch($this->contactUpdate());

        $user = MaxUser::query()->findOrFail(111);

        $this->assertSame(self::PHONE, $user->phone);
        $this->assertTrue($user->phone_verified_at->greaterThan($checkedAt));
    }

    public function testCallbackUpdateCapturesPhone(): void
    {
        $this->makeUser(111);

        $this->dispatch(new MessageCallbackUpdate(
            callback: new Callback(callbackId: 'cb-1', user: $this->user()),
            message: $this->message([$this->contactAttachment()]),
            timestamp: 1000,
        ));

        $this->assertSame(self::PHONE, MaxUser::query()->findOrFail(111)->phone);
    }

    /**
     * A09: телефон — персональные данные и в логи не попадают ни при успешной
     * записи, ни при расхождении с уже известным значением.
     */
    public function testPhoneNeverReachesTheLog(): void
    {
        $this->makeUser(111, phone: '+79251112233');
        $this->dispatch($this->contactUpdate());

        $this->assertSame(self::PHONE, MaxUser::query()->findOrFail(111)->phone);

        Log::channel('max_test')->warning('flush');

        $log = is_file($this->logPath) ? (string) file_get_contents($this->logPath) : '';

        $this->assertStringContainsString('differs from stored value', $log);
        $this->assertStringContainsString('new value saved', $log);
        $this->assertStringNotContainsString(self::PHONE, $log);
        $this->assertStringNotContainsString('+79251112233', $log);
        $this->assertStringNotContainsString('BEGIN:VCARD', $log);
    }

    public function testVcfInfoIsNotWrittenToTheLogOnSuccessfulCapture(): void
    {
        $this->makeUser(111);
        $this->dispatch($this->contactUpdate());

        Log::channel('max_test')->warning('flush');

        $log = is_file($this->logPath) ? (string) file_get_contents($this->logPath) : '';

        $this->assertStringNotContainsString('BEGIN:VCARD', $log);
        $this->assertStringNotContainsString(self::PHONE, $log);
        $this->assertStringNotContainsString($this->hash($this->vcf()), $log);
    }

    private function vcf(string $phone = self::PHONE): string
    {
        return "BEGIN:VCARD\r\nVERSION:3.0\r\nPRODID:ez-vcard 0.10.3\r\n"
            . "TEL;TYPE=cell:{$phone}\r\nFN:Тест\r\nEND:VCARD\r\n";
    }

    private function hash(string $vcfInfo): string
    {
        return hash_hmac('sha256', $vcfInfo, self::TOKEN);
    }

    private function contactUpdate(?string $hash = null): MessageCreatedUpdate
    {
        return new MessageCreatedUpdate(
            message: $this->message([$this->contactAttachment($hash)]),
            timestamp: 1000,
        );
    }

    private function contactAttachment(?string $hash = null): ContactAttachment
    {
        $vcf = $this->vcf();

        return new ContactAttachment(new ContactAttachmentPayload(
            hash: $hash ?? $this->hash($vcf),
            vcfInfo: $vcf,
        ));
    }

    /**
     * @param list<Attachment> $attachments
     */
    private function message(array $attachments): Message
    {
        return new Message(
            sender: $this->user(),
            recipient: new Recipient(chatId: 222, chatType: ChatType::Dialog),
            timestamp: 1000,
            body: new MessageBody(mid: 'b1', seq: 0, attachments: $attachments),
        );
    }

    private function user(): User
    {
        return new User(
            userId: 111,
            firstName: 'Иван',
            lastName: null,
            username: null,
            isBot: false,
        );
    }

    private function makeUser(int $userId, ?string $phone = null): MaxUser
    {
        return MaxUser::query()->create([
            'user_id' => $userId,
            'first_name' => 'Иван',
            'phone' => $phone,
        ]);
    }

    private function dispatch(Update $update): void
    {
        $this->app->make(Dispatcher::class)->dispatch(new MaxUpdateReceived($update));
    }
}
