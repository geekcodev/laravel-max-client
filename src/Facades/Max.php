<?php

declare(strict_types=1);

namespace GeekCo\LaravelMaxClient\Facades;

use GeekCo\MaxPhpClient\ApiClient;
use Illuminate\Support\Facades\Facade;

/**
 * @method static \GeekCo\MaxPhpClient\Dto\BotInfo getMe()
 * @method static \GeekCo\MaxPhpClient\Dto\BotCommandsResult editBotCommands(array<int, \GeekCo\MaxPhpClient\Dto\BotCommand> $commands)
 * @method static \GeekCo\MaxPhpClient\Dto\ChatListResult getChats(?int $marker = null, ?int $count = null)
 * @method static \GeekCo\MaxPhpClient\Dto\Chat getChat(int $chatId)
 * @method static \GeekCo\MaxPhpClient\Dto\Chat editChat(int $chatId, \GeekCo\MaxPhpClient\Dto\EditChatBody $body)
 * @method static \GeekCo\MaxPhpClient\Dto\SuccessResponse sendBotAction(int $chatId, \GeekCo\MaxPhpClient\Enum\SenderAction $action)
 * @method static \GeekCo\MaxPhpClient\Dto\Message|null getPinnedMessage(int $chatId)
 * @method static \GeekCo\MaxPhpClient\Dto\SuccessResponse pinMessage(int $chatId, \GeekCo\MaxPhpClient\Dto\PinMessageBody $body)
 * @method static \GeekCo\MaxPhpClient\Dto\SuccessResponse unpinMessage(int $chatId)
 * @method static \GeekCo\MaxPhpClient\Dto\ChatMember getBotMembership(int $chatId)
 * @method static \GeekCo\MaxPhpClient\Dto\SuccessResponse removeBotFromChat(int $chatId)
 * @method static \GeekCo\MaxPhpClient\Dto\ChatAdminsResult getChatAdmins(int $chatId, ?int $marker = null, ?int $count = null)
 * @method static \GeekCo\MaxPhpClient\Dto\SuccessResponse addChatAdmin(int $chatId, int $userId, array<int, \GeekCo\MaxPhpClient\Enum\ChatAdminPermission> $permissions, ?string $alias = null)
 * @method static \GeekCo\MaxPhpClient\Dto\SuccessResponse removeChatAdmin(int $chatId, int $userId)
 * @method static \GeekCo\MaxPhpClient\Dto\ChatMembersResult getChatMembers(int $chatId, ?array<int, int> $userIds = null, ?int $marker = null, ?int $count = null)
 * @method static \GeekCo\MaxPhpClient\Dto\AddChatMembersResult addChatMembers(int $chatId, array<int, int> $userIds)
 * @method static \GeekCo\MaxPhpClient\Dto\SuccessResponse removeChatMember(int $chatId, int $userId, bool $block = false)
 * @method static array<int, \GeekCo\MaxPhpClient\Dto\Subscription> getSubscriptions()
 * @method static \GeekCo\MaxPhpClient\Dto\SuccessResponse createSubscription(string $url, ?array<int, string> $updateTypes = null, ?string $secret = null)
 * @method static \GeekCo\MaxPhpClient\Dto\SuccessResponse deleteSubscription(string $url)
 * @method static array<int, \GeekCo\MaxPhpClient\Dto\Update> getUpdates(?int $limit = null, ?int $timeout = null, ?int $marker = null, ?array<int, string> $types = null)
 * @method static array<int, \GeekCo\MaxPhpClient\Dto\Message> getMessages(?int $chatId = null, ?array<int, int> $messageIds = null, ?int $from = null, ?int $to = null, ?int $count = null)
 * @method static \GeekCo\MaxPhpClient\Dto\Message sendMessage(\GeekCo\MaxPhpClient\Dto\Recipient $recipient, \GeekCo\MaxPhpClient\Dto\NewMessageBody $body, ?bool $disableLinkPreview = null)
 * @method static \GeekCo\MaxPhpClient\Dto\SuccessResponse editMessage(string $messageId, \GeekCo\MaxPhpClient\Dto\NewMessageBody $body)
 * @method static \GeekCo\MaxPhpClient\Dto\SuccessResponse deleteMessage(string $messageId)
 * @method static \GeekCo\MaxPhpClient\Dto\Message getMessageById(string $messageId)
 * @method static \GeekCo\MaxPhpClient\Dto\VideoInfo getVideoInfo(string $videoToken)
 * @method static \GeekCo\MaxPhpClient\Dto\UploadResult uploadMedia(\GeekCo\MaxPhpClient\Enum\UploadType $type, string $filePath)
 * @method static \GeekCo\MaxPhpClient\Dto\SuccessResponse sendAnswer(string $callbackId, ?\GeekCo\MaxPhpClient\Dto\NewMessageBody $message = null)
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
