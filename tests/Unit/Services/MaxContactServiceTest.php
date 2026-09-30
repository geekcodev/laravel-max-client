<?php

declare(strict_types=1);

namespace GeekCo\LaravelMaxClient\Tests\Unit\Services;

use GeekCo\LaravelMaxClient\MaxServiceProvider;
use GeekCo\LaravelMaxClient\Services\MaxContactService;
use GeekCo\LaravelMaxClient\Support\Config;
use GeekCo\LaravelMaxClient\Support\Logger;
use GeekCo\LaravelMaxClient\Tests\TestCase;
use GeekCo\MaxPhpClient\Dto\Attachment;
use GeekCo\MaxPhpClient\Dto\Callback;
use GeekCo\MaxPhpClient\Dto\ContactAttachmentPayload;
use GeekCo\MaxPhpClient\Dto\Message;
use GeekCo\MaxPhpClient\Dto\MessageBody;
use GeekCo\MaxPhpClient\Dto\Recipient;
use GeekCo\MaxPhpClient\Dto\Update;
use GeekCo\MaxPhpClient\Dto\User;
use GeekCo\MaxPhpClient\Enum\AttachmentType;
use GeekCo\MaxPhpClient\Enum\UpdateType;
use GeekCo\MaxPhpClient\Security\ContactPhoneExtractor;

final class MaxContactServiceTest extends TestCase
{
    private const string TOKEN = 'test-token';

    private const string PHONE = '79250000000';

    /**
     * vCard в форме реального захвата Android-клиента: TEL заглавными, значение
     * параметра TYPE строчными, номер без +, переводы строк настоящие.
     */
    private function vcf(string $phone = self::PHONE): string
    {
        return "BEGIN:VCARD\r\nVERSION:3.0\r\nPRODID:ez-vcard 0.10.3\r\n"
            . "TEL;TYPE=cell:{$phone}\r\nFN:Тест\r\nEND:VCARD\r\n";
    }

    private function hash(string $vcfInfo): string
    {
        return hash_hmac('sha256', $vcfInfo, self::TOKEN);
    }

    public function testPhoneIsExtractedFromVcfInfo(): void
    {
        $this->assertSame(
            self::PHONE,
            $this->service()->phoneFromUpdate($this->contactUpdate($this->vcf())),
        );
    }

    public function testPhoneIsExtractedWhenTransportLeftEscapedLineBreaks(): void
    {
        $vcf = str_replace("\r\n", '\\r\\n', $this->vcf());

        $this->assertSame(self::PHONE, $this->service()->phoneFromUpdate($this->contactUpdate($vcf)));
    }

    public function testPhoneIsExtractedFromFoldedTelLine(): void
    {
        $vcf = "BEGIN:VCARD\r\nVERSION:3.0\r\nTEL;TYPE=cell:7925000\r\n 0000\r\nEND:VCARD\r\n";

        $this->assertSame('79250000000', $this->service()->phoneFromUpdate($this->contactUpdate($vcf)));
    }

    public function testVcfPhoneFromPayloadTakesPrecedence(): void
    {
        $update = $this->contactUpdate($this->vcf(), vcfPhone: '+7 925 000 00 00');

        $this->assertSame('+7 925 000 00 00', $this->service()->phoneFromUpdate($update));
    }

    public function testBrokenSignatureYieldsNoPhone(): void
    {
        $update = $this->contactUpdate($this->vcf(), hash: str_repeat('a', 64));

        $this->assertNull($this->service()->phoneFromUpdate($update));
    }

    public function testMissingContactAttachmentYieldsNoPhone(): void
    {
        $update = new Update(
            updateType: UpdateType::MessageCreated,
            timestamp: 1000,
            user: $this->user(),
            chatId: 222,
            message: $this->message([]),
        );

        $this->assertNull($this->service()->phoneFromUpdate($update));
    }

    public function testAttachmentOfAnotherTypeIsIgnored(): void
    {
        $update = new Update(
            updateType: UpdateType::MessageCreated,
            timestamp: 1000,
            user: $this->user(),
            chatId: 222,
            message: $this->message([
                new Attachment(type: AttachmentType::Location, latitude: 1.0, longitude: 2.0),
            ]),
        );

        $this->assertNull($this->service()->phoneFromUpdate($update));
    }

    public function testVcfWithoutTelYieldsNoPhone(): void
    {
        $vcf = "BEGIN:VCARD\r\nVERSION:3.0\r\nFN:Тест\r\nEND:VCARD\r\n";

        $this->assertNull($this->service()->phoneFromUpdate($this->contactUpdate($vcf)));
    }

    public function testVcfWithXTelDoesNotMatchCustomProperty(): void
    {
        $vcf = "BEGIN:VCARD\r\nVERSION:3.0\r\nX-TEL:79250000000\r\nEND:VCARD\r\n";

        $this->assertNull($this->service()->phoneFromUpdate($this->contactUpdate($vcf)));
    }

    public function testNonMessageUpdateIsIgnored(): void
    {
        $vcf = $this->vcf();
        $update = new Update(
            updateType: UpdateType::BotAdded,
            timestamp: 1000,
            user: $this->user(),
            chatId: 222,
            message: $this->message([$this->attachment($vcf)]),
        );

        $this->assertNull($this->service()->phoneFromUpdate($update));
        $this->assertFalse($this->service()->isTrusted($update));
    }

    public function testIsTrustedChecksSignatureOnly(): void
    {
        $vcf = "BEGIN:VCARD\r\nVERSION:3.0\r\nEND:VCARD\r\n";

        $this->assertTrue($this->service()->isTrusted($this->contactUpdate($vcf)));
        $this->assertFalse($this->service()->isTrusted($this->contactUpdate($vcf, hash: 'deadbeef')));
    }

    public function testIsTrustedWorksForCallbackUpdate(): void
    {
        $vcf = $this->vcf();
        $message = $this->message([$this->attachment($vcf)]);

        $update = new Update(
            updateType: UpdateType::MessageCallback,
            timestamp: 1000,
            user: $this->user(),
            chatId: 222,
            callback: new Callback(callbackId: 'cb-1', message: $message),
        );

        $this->assertTrue($this->service()->isTrusted($update));
        $this->assertSame(self::PHONE, $this->service()->phoneFromUpdate($update));
    }

    public function testAttachmentWithNonContactPayloadIsSkipped(): void
    {
        $update = new Update(
            updateType: UpdateType::MessageCreated,
            timestamp: 1000,
            user: $this->user(),
            chatId: 222,
            message: $this->message([
                new Attachment(type: AttachmentType::Contact, payload: ['hash' => 'x']),
            ]),
        );

        $this->assertNull($this->service()->phoneFromUpdate($update));
    }

    /**
     * Расхождение с ядром зафиксировано как тест: если ядро начнёт принимать
     * номер иначе, тест напомнит об этом адаптеру.
     */
    public function testCoreExtractorIsTheSingleSourceOfTheNumber(): void
    {
        $this->assertSame(
            ContactPhoneExtractor::fromVcf($this->vcf()),
            $this->service()->phoneFromUpdate($this->contactUpdate($this->vcf())),
        );
    }

    private function service(): MaxContactService
    {
        config()->set(MaxServiceProvider::CONFIG_KEY . '.api_token', self::TOKEN);

        return new MaxContactService(
            $this->app->make(Config::class),
            $this->app->make(Logger::class),
        );
    }

    private function contactUpdate(string $vcf, ?string $hash = null, ?string $vcfPhone = null): Update
    {
        return new Update(
            updateType: UpdateType::MessageCreated,
            timestamp: 1000,
            user: $this->user(),
            chatId: 222,
            message: $this->message([
                $this->attachment($vcf, $hash, $vcfPhone),
            ]),
        );
    }

    private function attachment(string $vcf, ?string $hash = null, ?string $vcfPhone = null): Attachment
    {
        return new Attachment(
            type: AttachmentType::Contact,
            payload: new ContactAttachmentPayload(
                hash: $hash ?? $this->hash($vcf),
                vcfInfo: $vcf,
                vcfPhone: $vcfPhone,
            ),
        );
    }

    /**
     * @param list<Attachment> $attachments
     */
    private function message(array $attachments): Message
    {
        return new Message(
            sender: $this->user(),
            recipient: new Recipient(chatId: 222, chatType: 'dialog'),
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
}
