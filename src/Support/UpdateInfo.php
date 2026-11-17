<?php

declare(strict_types=1);

namespace GeekCo\LaravelMaxClient\Support;

use GeekCo\MaxPhpClient\Dto\CommentMessage;
use GeekCo\MaxPhpClient\Dto\Message;
use GeekCo\MaxPhpClient\Dto\Update;
use GeekCo\MaxPhpClient\Dto\Update\BotAddedToChatUpdate;
use GeekCo\MaxPhpClient\Dto\Update\BotAdminPermissionsChangedUpdate;
use GeekCo\MaxPhpClient\Dto\Update\BotRemovedFromChatUpdate;
use GeekCo\MaxPhpClient\Dto\Update\BotStartedUpdate;
use GeekCo\MaxPhpClient\Dto\Update\BotStoppedUpdate;
use GeekCo\MaxPhpClient\Dto\Update\ChatTitleChangedUpdate;
use GeekCo\MaxPhpClient\Dto\Update\CommentCreatedUpdate;
use GeekCo\MaxPhpClient\Dto\Update\CommentEditedUpdate;
use GeekCo\MaxPhpClient\Dto\Update\CommentRemovedUpdate;
use GeekCo\MaxPhpClient\Dto\Update\DialogClearedUpdate;
use GeekCo\MaxPhpClient\Dto\Update\DialogMutedUpdate;
use GeekCo\MaxPhpClient\Dto\Update\DialogRemovedUpdate;
use GeekCo\MaxPhpClient\Dto\Update\DialogUnmutedUpdate;
use GeekCo\MaxPhpClient\Dto\Update\MessageCallbackUpdate;
use GeekCo\MaxPhpClient\Dto\Update\MessageCreatedUpdate;
use GeekCo\MaxPhpClient\Dto\Update\MessageEditedUpdate;
use GeekCo\MaxPhpClient\Dto\Update\MessageRemovedUpdate;
use GeekCo\MaxPhpClient\Dto\Update\UserAddedToChatUpdate;
use GeekCo\MaxPhpClient\Dto\Update\UserRemovedFromChatUpdate;
use GeekCo\MaxPhpClient\Dto\User;
use GeekCo\MaxPhpClient\Enum\ChatType;

/**
 * Единая точка извлечения контекста из типизированного апдейта ядра v2.
 *
 * Типизированная модель хранит поля в подтипах события, поэтому слушатели и
 * сервисы не должны знать о конкретных классах апдейтов: они спрашивают
 * chat_id, пользователя, тип чата и признак канала здесь. Неизвестный подтип
 * даёт пустой контекст, а не исключение — обработка апдейта не должна падать
 * из-за нового типа события (OWASP A04).
 */
final readonly class UpdateInfo
{
    public function __construct(
        public ?int $chatId = null,
        public ?User $user = null,
        public ?int $userId = null,
        public ?ChatType $chatType = null,
        public ?bool $isChannel = null,
        public ?string $title = null,
    ) {
    }

    public static function from(Update $update): self
    {
        return match (true) {
            $update instanceof MessageCreatedUpdate,
            $update instanceof MessageEditedUpdate => self::fromMessage($update->message),
            $update instanceof MessageCallbackUpdate => self::fromCallback($update),
            $update instanceof CommentCreatedUpdate,
            $update instanceof CommentEditedUpdate => self::fromComment($update->message),
            $update instanceof CommentRemovedUpdate,
            $update instanceof MessageRemovedUpdate => new self(
                chatId: $update->chatId,
                userId: $update->userId,
            ),
            $update instanceof ChatTitleChangedUpdate => new self(
                chatId: $update->chatId,
                user: $update->user,
                userId: $update->user->userId,
                title: $update->title,
            ),
            $update instanceof BotAddedToChatUpdate,
            $update instanceof BotRemovedFromChatUpdate,
            $update instanceof UserAddedToChatUpdate,
            $update instanceof UserRemovedFromChatUpdate => new self(
                chatId: $update->chatId,
                user: $update->user,
                userId: $update->user->userId,
                isChannel: $update->isChannel,
            ),
            $update instanceof BotStartedUpdate,
            $update instanceof BotStoppedUpdate,
            $update instanceof DialogClearedUpdate,
            $update instanceof DialogRemovedUpdate,
            $update instanceof DialogMutedUpdate,
            $update instanceof DialogUnmutedUpdate => new self(
                chatId: $update->chatId,
                user: $update->user,
                userId: $update->user->userId,
            ),
            $update instanceof BotAdminPermissionsChangedUpdate => new self(
                chatId: $update->chatId,
                userId: $update->userId,
                isChannel: $update->isChannel,
            ),
            default => new self(),
        };
    }

    /**
     * Тип чата: из получателя сообщения/комментария, иначе канал по признаку
     * is_channel (lifecycle-события не несут получателя).
     */
    public function resolveChatType(): ?ChatType
    {
        return $this->chatType ?? ($this->isChannel === true ? ChatType::Channel : null);
    }

    private static function fromMessage(Message $message): self
    {
        return new self(
            chatId: $message->recipient->chatId,
            user: $message->sender,
            userId: $message->sender?->userId,
            chatType: $message->recipient->chatType,
        );
    }

    private static function fromComment(CommentMessage $message): self
    {
        return new self(
            chatId: $message->recipient->chatId,
            user: $message->sender,
            userId: $message->sender?->userId,
            chatType: $message->recipient->chatType,
        );
    }

    private static function fromCallback(MessageCallbackUpdate $update): self
    {
        $message = $update->message;
        $user = $update->callback->user ?? $message?->sender;

        return new self(
            chatId: $message?->recipient->chatId,
            user: $user,
            userId: $user?->userId,
            chatType: $message?->recipient->chatType,
        );
    }
}
