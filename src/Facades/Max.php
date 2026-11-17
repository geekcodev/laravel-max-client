<?php

declare(strict_types=1);

namespace GeekCo\LaravelMaxClient\Facades;

use GeekCo\MaxPhpClient\ApiClient;
use Illuminate\Support\Facades\Facade;

/**
 * @method static \GeekCo\MaxPhpClient\Dto\BotInfo getMe()
 * @method static \GeekCo\MaxPhpClient\Dto\BotCommandsInfo editBotCommands(array<int, \GeekCo\MaxPhpClient\Dto\BotCommand> $commands)
 * @method static \GeekCo\MaxPhpClient\Dto\Chat getChat(int $chatId)
 * @method static \GeekCo\MaxPhpClient\Dto\Chat editChat(int $chatId, \GeekCo\MaxPhpClient\Dto\ChatPatch $body)
 * @method static \GeekCo\MaxPhpClient\Dto\SimpleQueryResult sendChatAction(int $chatId, \GeekCo\MaxPhpClient\Enum\SenderAction $action)
 * @method static \GeekCo\MaxPhpClient\Dto\Message|null getPinnedMessage(int $chatId)
 * @method static \GeekCo\MaxPhpClient\Dto\SimpleQueryResult pinMessage(int $chatId, \GeekCo\MaxPhpClient\Dto\PinMessageBody $body)
 * @method static \GeekCo\MaxPhpClient\Dto\SimpleQueryResult unpinMessage(int $chatId)
 * @method static \GeekCo\MaxPhpClient\Dto\ChatMember getBotMembership(int $chatId)
 * @method static \GeekCo\MaxPhpClient\Dto\SimpleQueryResult removeBotFromChat(int $chatId)
 * @method static \GeekCo\MaxPhpClient\Dto\ChatAdminsResult getChatAdmins(int $chatId, ?int $marker = null, ?int $count = null)
 * @method static \GeekCo\MaxPhpClient\Dto\SimpleQueryResult addChatAdmin(int $chatId, int $userId, array<int, \GeekCo\MaxPhpClient\Enum\ChatAdminPermission> $permissions, ?string $alias = null, ?int $marker = null)
 * @method static \GeekCo\MaxPhpClient\Dto\SimpleQueryResult removeChatAdmin(int $chatId, int $userId)
 * @method static \GeekCo\MaxPhpClient\Dto\ChatMembersList getChatMembers(int $chatId, ?array<int, int> $userIds = null, ?int $marker = null, ?int $count = null)
 * @method static \GeekCo\MaxPhpClient\Dto\ModifyMembersResult addChatMembers(int $chatId, array<int, int> $userIds) с 9 сентября 2026 ограничен со стороны MAX; в спеке присутствует, пометки об удалении нет
 * @method static \GeekCo\MaxPhpClient\Dto\SimpleQueryResult removeChatMember(int $chatId, int $userId, bool $block = false)
 * @method static array<int, \GeekCo\MaxPhpClient\Dto\Subscription> getSubscriptions()
 * @method static \GeekCo\MaxPhpClient\Dto\SimpleQueryResult createSubscription(string $url, ?array<int, string> $updateTypes = null, ?string $secret = null)
 * @method static \GeekCo\MaxPhpClient\Dto\SimpleQueryResult deleteSubscription(string $url)
 * @method static \GeekCo\MaxPhpClient\Dto\UpdateList getUpdates(?int $limit = null, ?int $timeout = null, ?int $marker = null, ?array<int, string> $types = null)
 * @method static array<int, \GeekCo\MaxPhpClient\Dto\Message> getMessages(?int $chatId = null, ?array<int, string> $messageIds = null, ?int $from = null, ?int $to = null, ?int $count = null)
 * @method static \GeekCo\MaxPhpClient\Dto\Message sendMessage(\GeekCo\MaxPhpClient\Dto\Recipient $recipient, \GeekCo\MaxPhpClient\Dto\NewMessageBody $body, ?bool $disableLinkPreview = null)
 * @method static \GeekCo\MaxPhpClient\Dto\SimpleQueryResult editMessage(string $messageId, \GeekCo\MaxPhpClient\Dto\NewMessageBody $body)
 * @method static \GeekCo\MaxPhpClient\Dto\SimpleQueryResult deleteMessage(string $messageId)
 * @method static \GeekCo\MaxPhpClient\Dto\Message getMessageById(string $messageId)
 * @method static \GeekCo\MaxPhpClient\Dto\VideoAttachmentDetails getVideoAttachmentDetails(string $videoToken)
 * @method static \GeekCo\MaxPhpClient\Dto\UploadEndpoint uploadMedia(\GeekCo\MaxPhpClient\Enum\UploadType $type, string $filePath)
 * @method static \GeekCo\MaxPhpClient\Dto\SimpleQueryResult answerCallback(string $callbackId, ?\GeekCo\MaxPhpClient\Dto\NewMessageBody $message = null, ?string $notification = null, ?bool $disableLinkPreview = null)
 * @method static \GeekCo\MaxPhpClient\Dto\CommentMessageList getComments(string $messageId, ?array<int, string> $commentIds = null, ?int $after = null, ?int $before = null, ?int $count = null)
 * @method static \GeekCo\MaxPhpClient\Dto\CommentMessage sendComment(string $messageId, \GeekCo\MaxPhpClient\Dto\NewCommentBody $body, ?bool $disableLinkPreview = null)
 * @method static \GeekCo\MaxPhpClient\Dto\SimpleQueryResult editComment(string $messageId, string $commentId, \GeekCo\MaxPhpClient\Dto\NewCommentBody $body)
 * @method static \GeekCo\MaxPhpClient\Dto\SimpleQueryResult deleteComment(string $messageId, string $commentId)
 * @method static \GeekCo\MaxPhpClient\Dto\CommentMessage getCommentById(string $messageId, string $commentId)
 *
 * @see \GeekCo\MaxPhpClient\ApiClient
 */
final class Max extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return ApiClient::class;
    }
}
