<?php

declare(strict_types=1);

namespace GeekCo\LaravelMaxClient\Services;

use GeekCo\LaravelMaxClient\Support\Config;
use GeekCo\LaravelMaxClient\Support\Logger;
use GeekCo\MaxPhpClient\Dto\ContactAttachmentPayload;
use GeekCo\MaxPhpClient\Dto\Update;
use GeekCo\MaxPhpClient\Enum\AttachmentType;
use GeekCo\MaxPhpClient\Enum\UpdateType;
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
 * разбором vcf_info. Значение vcf_phone приоритет имеет: если MAX начнёт его
 * присылать, адаптер продолжит работать без изменений.
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
     * Приоритет у vcf_phone (если MAX его пришлёт), иначе разбор vcf_info.
     */
    public function phoneFromAttachment(ContactAttachmentPayload $attachment): ?string
    {
        $phone = $attachment->vcfPhone;

        if ($phone !== null && trim($phone) !== '') {
            return trim($phone);
        }

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

        $this->logger->log('warning', 'MAX contact signature mismatch; phone ignored.', [
            'chat_id' => $update->chatId,
            'user_id' => $update->user !== null ? $update->user->userId : $update->userId,
        ]);

        return false;
    }

    private function contactAttachment(Update $update): ?ContactAttachmentPayload
    {
        if (!\in_array($update->updateType, [UpdateType::MessageCreated, UpdateType::MessageCallback], true)) {
            return null;
        }

        $message = $update->message ?? $update->callback?->message;

        if ($message === null) {
            return null;
        }

        foreach ($message->body->attachments ?? [] as $attachment) {
            if ($attachment->type !== AttachmentType::Contact) {
                continue;
            }

            if ($attachment->payload instanceof ContactAttachmentPayload) {
                return $attachment->payload;
            }
        }

        return null;
    }
}
