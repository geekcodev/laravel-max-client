<?php

declare(strict_types=1);

namespace GeekCo\LaravelMaxClient\Tests\Unit\Services;

use GeekCo\LaravelMaxClient\MaxServiceProvider;
use GeekCo\LaravelMaxClient\Services\MaxContactService;
use GeekCo\LaravelMaxClient\Support\Config;
use GeekCo\LaravelMaxClient\Support\Logger;
use GeekCo\LaravelMaxClient\Tests\TestCase;
use GeekCo\MaxPhpClient\Dto\Attachment;
use GeekCo\MaxPhpClient\Dto\Attachment\ContactAttachment;
use GeekCo\MaxPhpClient\Dto\Attachment\LocationAttachment;
use GeekCo\MaxPhpClient\Dto\Callback;
use GeekCo\MaxPhpClient\Dto\ContactAttachmentPayload;
use GeekCo\MaxPhpClient\Dto\Message;
use GeekCo\MaxPhpClient\Dto\MessageBody;
use GeekCo\MaxPhpClient\Dto\Recipient;
use GeekCo\MaxPhpClient\Dto\Update;
use GeekCo\MaxPhpClient\Dto\Update\BotAddedToChatUpdate;
use GeekCo\MaxPhpClient\Dto\Update\MessageCallbackUpdate;
use GeekCo\MaxPhpClient\Dto\Update\MessageCreatedUpdate;
use GeekCo\MaxPhpClient\Dto\User;
use GeekCo\MaxPhpClient\Enum\ChatType;
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

    /**
     * В ядре v2.0.0 поле vcf_phone удалено из входящего DTO: API его не присылает,
     * поэтому номер берётся только разбором vcf_info. Лишнее поле в payload не
     * ломает разбор и не подменяет номер.
     */
    public function testLegacyVcfPhoneFieldIsIgnored(): void
    {
        $payload = $this->messageUpdatePayload([
            'message' => [
                'sender' => $this->user()->toArray(),
                'recipient' => ['chat_id' => 222, 'chat_type' => 'dialog'],
                'timestamp' => 1000,
                'body' => [
                    'mid' => 'b1',
                    'seq' => 0,
                    'attachments' => [
                        [
                            'type' => 'contact',
                            'payload' => [
                                'hash' => $this->hash($this->vcf()),
                                'vcf_info' => $this->vcf(),
                                'vcf_phone' => '+7 925 000 00 00',
                            ],
                        ],
                    ],
                ],
            ],
        ]);

        $this->assertSame(self::PHONE, $this->service()->phoneFromUpdate(Update::fromArray($payload)));
    }

    public function testBrokenSignatureYieldsNoPhone(): void
    {
        $update = $this->contactUpdate($this->vcf(), hash: str_repeat('a', 64));

        $this->assertNull($this->service()->phoneFromUpdate($update));
    }

    /**
     * Контакт без подписи: отсутствующий или null-`hash` разбирается ядром в
     * null, апдейт не роняется. Телефон из него считается неподтверждённым
     * (fail-closed), номер не сохраняется.
     */
    public function testContactWithoutHashYieldsNoPhone(): void
    {
        $update = $this->contactUpdate($this->vcf(), hash: '');

        $this->assertNull($this->service()->phoneFromUpdate($update));
        $this->assertFalse($this->service()->isTrusted($update));
    }

    /**
     * То же самое, но вложение разбирается из payload реального вебхука:
     * подтверждаем, что путь «MAX прислал контакт без hash» доходит до сервиса,
     * а не отклоняется на разборе.
     */
    public function testContactDecodedWithoutHashYieldsNoPhone(): void
    {
        $payload = $this->messageUpdatePayload([
            'message' => [
                'sender' => $this->user()->toArray(),
                'recipient' => ['chat_id' => 222, 'chat_type' => 'dialog'],
                'timestamp' => 1000,
                'body' => [
                    'mid' => 'b1',
                    'seq' => 0,
                    'attachments' => [
                        [
                            'type' => 'contact',
                            'payload' => ['vcf_info' => $this->vcf()],
                        ],
                    ],
                ],
            ],
        ]);

        $update = Update::fromArray($payload);

        $this->assertNull($this->service()->phoneFromUpdate($update));
        $this->assertFalse($this->service()->isTrusted($update));
    }

    public function testMissingContactAttachmentYieldsNoPhone(): void
    {
        $update = new MessageCreatedUpdate(
            message: $this->message([]),
            timestamp: 1000,
        );

        $this->assertNull($this->service()->phoneFromUpdate($update));
    }

    public function testAttachmentOfAnotherTypeIsIgnored(): void
    {
        $update = new MessageCreatedUpdate(
            message: $this->message([
                new LocationAttachment(latitude: 1.0, longitude: 2.0),
            ]),
            timestamp: 1000,
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
        $update = new BotAddedToChatUpdate(
            chatId: 222,
            user: $this->user(),
            isChannel: false,
            timestamp: 1000,
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

        $update = new MessageCallbackUpdate(
            callback: new Callback(callbackId: 'cb-1', user: $this->user()),
            message: $message,
            timestamp: 1000,
        );

        $this->assertTrue($this->service()->isTrusted($update));
        $this->assertSame(self::PHONE, $this->service()->phoneFromUpdate($update));
    }

    /**
     * В типизированной модели контакт — это ContactAttachment, поэтому подмена
     * payload чужого типа больше невозможна; неподписанный контакт остаётся
     * fail-closed.
     */
    public function testUnsignedContactAttachmentIsNotTrusted(): void
    {
        $update = new MessageCreatedUpdate(
            message: $this->message([
                new ContactAttachment(new ContactAttachmentPayload(vcfInfo: $this->vcf())),
            ]),
            timestamp: 1000,
        );

        $this->assertFalse($this->service()->isTrusted($update));
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

    private function contactUpdate(string $vcf, ?string $hash = null): MessageCreatedUpdate
    {
        return new MessageCreatedUpdate(
            message: $this->message([
                $this->attachment($vcf, $hash),
            ]),
            timestamp: 1000,
        );
    }

    private function attachment(string $vcf, ?string $hash = null): ContactAttachment
    {
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

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function messageUpdatePayload(array $data): array
    {
        return ['update_type' => 'message_created', 'timestamp' => 1000, ...$data];
    }
}
