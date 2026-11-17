<?php

declare(strict_types=1);

namespace GeekCo\LaravelMaxClient\Services;

use GeekCo\LaravelMaxClient\Support\Config;
use GeekCo\LaravelMaxClient\Support\Logger;
use GeekCo\LaravelMaxClient\Support\UpdateInfo;
use GeekCo\MaxPhpClient\Dto\Attachment\ContactAttachment;
use GeekCo\MaxPhpClient\Dto\ContactAttachmentPayload;
use GeekCo\MaxPhpClient\Dto\Message;
use GeekCo\MaxPhpClient\Dto\Update;
use GeekCo\MaxPhpClient\Dto\Update\MessageCallbackUpdate;
use GeekCo\MaxPhpClient\Dto\Update\MessageCreatedUpdate;
use GeekCo\MaxPhpClient\Security\ContactPhoneExtractor;
use GeekCo\MaxPhpClient\Security\ContactVerifier;

/**
 * Телефон пользователя из вложения-контакта (кнопка request_contact).
 *
 * Подлинность вложения проверяет ContactVerifier ядра: hash = HMAC-SHA256
 * (access_token, vcf_info), сравнение константное. Разбор vCard в номер делает
 * ContactPhoneExtractor ядра — обе части намеренно не дублируются в адаптере.
 *
 * Во входящем payload MAX не присылает vcf_phone (поле есть только в исходящем
 * ContactAttachmentRequestPayload, который бот кладёт сам), поэтому номер берётся
 * разбором vcf_info. В ядре v2.0.0 поле vcfPhone из входящего DTO удалено, так
 * что запасного источника номера больше нет — это соответствует реалям API.
 *
 * Номер возвращается как есть — ядро его не нормализует и не валидирует.
 * Канонизация под формат потребителя (например, приведение к +7XXXXXXXXXX)
 * делается на стороне приложения, одним местом, иначе значения из разных
 * точек ввода разойдутся.
 *
 * Телефон и vcf_info — персональные данные: в лог уходят только chat_id,
 * user_id и факт действия, сам номер не логируется (OWASP A09).
 */
final class MaxContactService
{
    private readonly ContactVerifier $verifier;

    public function __construct(
        Config $config,
        private readonly Logger $logger,
    ) {
        $this->verifier = new ContactVerifier($config->apiToken());
    }

    /**
     * Подтверждённый телефон из апдейта или null: контакта нет, подпись не сошлась
     * либо номер не извлёкся.
     */
    public function phoneFromUpdate(Update $update): ?string
    {
        $attachment = $this->contactAttachment($update);

        if ($attachment === null) {
            return null;
        }

        if (!$this->verify($attachment, $update)) {
            return null;
        }

        return $this->phoneFromAttachment($attachment);
    }

    /**
     * Только проверка подписи вложения-контакта, без разбора номера.
     */
    public function isTrusted(Update $update): bool
    {
        $attachment = $this->contactAttachment($update);

        return $attachment !== null && $this->verify($attachment, $update);
    }

    /**
     * Номер из разобранного vCard. Пустой или неразбираемый vcf_info даёт null.
     */
    public function phoneFromAttachment(ContactAttachmentPayload $attachment): ?string
    {
        if ($attachment->vcfInfo === null) {
            return null;
        }

        return ContactPhoneExtractor::fromVcf($attachment->vcfInfo);
    }

    private function verify(ContactAttachmentPayload $attachment, Update $update): bool
    {
        $vcfInfo = $attachment->vcfInfo ?? '';

        if ($this->verifier->verify($vcfInfo, $attachment->hash)) {
            return true;
        }

        $info = UpdateInfo::from($update);

        $this->logger->log('warning', 'MAX contact signature mismatch; phone ignored.', [
            'chat_id' => $info->chatId,
            'user_id' => $info->userId,
        ]);

        return false;
    }

    private function contactAttachment(Update $update): ?ContactAttachmentPayload
    {
        $message = $this->contactMessage($update);

        if ($message === null) {
            return null;
        }

        foreach ($message->body->attachments ?? [] as $attachment) {
            if ($attachment instanceof ContactAttachment) {
                return $attachment->payload;
            }
        }

        return null;
    }

    /**
     * Контакт приходит только в новом сообщении или в сообщении нажатой кнопки.
     */
    private function contactMessage(Update $update): ?Message
    {
        return match (true) {
            $update instanceof MessageCreatedUpdate => $update->message,
            $update instanceof MessageCallbackUpdate => $update->message,
            default => null,
        };
    }
}
