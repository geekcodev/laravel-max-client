<?php

declare(strict_types=1);

namespace GeekCo\LaravelMaxClient\Listeners;

use GeekCo\LaravelMaxClient\Enums\MaxChatStatus;
use GeekCo\LaravelMaxClient\Services\MaxChatProfileService;
use GeekCo\LaravelMaxClient\Support\Config;
use GeekCo\LaravelMaxClient\Support\UpdateInfo;
use GeekCo\LaravelMaxClient\Webhook\MaxUpdateReceived;
use GeekCo\MaxPhpClient\Dto\Update;
use GeekCo\MaxPhpClient\Dto\Update\BotAddedToChatUpdate;
use GeekCo\MaxPhpClient\Dto\Update\BotRemovedFromChatUpdate;
use GeekCo\MaxPhpClient\Dto\Update\BotStartedUpdate;
use GeekCo\MaxPhpClient\Dto\Update\BotStoppedUpdate;
use GeekCo\MaxPhpClient\Dto\Update\ChatTitleChangedUpdate;
use GeekCo\MaxPhpClient\Dto\Update\CommentCreatedUpdate;
use GeekCo\MaxPhpClient\Dto\Update\CommentEditedUpdate;
use GeekCo\MaxPhpClient\Dto\Update\MessageCallbackUpdate;
use GeekCo\MaxPhpClient\Dto\Update\MessageCreatedUpdate;
use GeekCo\MaxPhpClient\Dto\Update\MessageEditedUpdate;
use GeekCo\MaxPhpClient\Dto\User;
use Illuminate\Support\Facades\Log;

/**
 * Реестр чатов: upsert max_chats по апдейтам bot_added/bot_started/
 * bot_stopped/bot_removed (getChats удалён — chat_id хранить через
 * подписку) и связь пользователей в max_chat_users. При наличии user в апдейте —
 * upsert в max_users.
 *
 * С ядра v2.0.0 Update — типизированная иерархия, плоских полей больше нет:
 * контекст события извлекается через Support\UpdateInfo, а ветвление идёт по
 * классу подтипа (instanceof), а не по строке update_type.
 *
 * max_chats хранит одну строку на чат, а не на пару «пользователь + чат»:
 * события bot_* — это события о боте в чате, а не о пользователе, поэтому user
 * в них может не прийти. Раньше колонка user_id была частью уникального ключа и
 * новая строка без user не заводилась, из-за чего bot_removed без user терялся.
 * Теперь статус пишется всегда, а пользователь без user просто не попадает в
 * max_chat_users.
 *
 * chat_type определяется из Recipient->chatType (message/comment/callback),
 * isChannel (lifecycle), либо getChat() API (fallback для bot_added/bot_started).
 * Message/comment/callback-апдейты статус не меняют — только дозаполняют
 * chat_type у существующего чата и двигают last_activity_at.
 *
 * Название группы или канала апдейтами bot_added/bot_started не приходит, поэтому
 * для них метаданные запрашиваются через MaxChatProfileService (одним запросом
 * на чат). Событие chat_title_changed приходит уже с готовым title — для него
 * запрос не нужен.
 *
 * Включается config('laravel-max-client.chats.enabled').
 *
 * Внутренний класс: собирается контейнером, вручную не инстанцировать. Конструктор принимает
 * MaxChatProfileService, а не ApiClient, как было в v1.1.3 — изменение задокументировано в
 * .agents/release/RELEASE_NOTES_v1.1.4.md. Слушатели положено переопределять привязкой
 * MaxUpdateReceived::class, а не подменой класса.
 */
final class PersistMaxChatListener
{
    public function __construct(
        private readonly Config $config,
        private readonly MaxChatProfileService $chatProfile,
    ) {
    }

    public function handle(MaxUpdateReceived $event): void
    {
        $update = $event->update;
        $info = UpdateInfo::from($update);

        if ($this->isActivity($update)) {
            $this->touchExistingChat($info);

            return;
        }

        if ($update instanceof ChatTitleChangedUpdate) {
            $this->applyTitle($info);

            return;
        }

        $status = match (true) {
            $update instanceof BotAddedToChatUpdate,
            $update instanceof BotStartedUpdate => MaxChatStatus::Active,
            $update instanceof BotStoppedUpdate => MaxChatStatus::Stopped,
            $update instanceof BotRemovedFromChatUpdate => MaxChatStatus::Removed,
            default => null,
        };

        if ($status === null) {
            return;
        }

        $chatId = $info->chatId;

        if ($chatId === null) {
            Log::warning('MAX chat update without chat_id skipped.', [
                'update_type' => $update->updateType,
            ]);

            return;
        }

        if ($info->user !== null && $info->userId !== null) {
            $this->upsertUser($info->user, $info->userId);
        }

        $this->upsertChat($info, $chatId, $status);

        if ($info->userId !== null) {
            $this->upsertChatLink($chatId, $info->userId, $status);
        }

        $this->syncMetadataIfNeeded($update, $chatId);
    }

    /**
     * Апдейты активности: сообщение, редактирование, нажатие кнопки, комментарий.
     */
    private function isActivity(Update $update): bool
    {
        return $update instanceof MessageCreatedUpdate
            || $update instanceof MessageEditedUpdate
            || $update instanceof MessageCallbackUpdate
            || $update instanceof CommentCreatedUpdate
            || $update instanceof CommentEditedUpdate;
    }

    /**
     * Одна строка на чат: создаём или обновляем по chat_id.
     */
    private function upsertChat(UpdateInfo $info, int $chatId, MaxChatStatus $status): void
    {
        $data = [
            'status' => $status,
            'last_activity_at' => now(),
        ];

        $chatType = $info->resolveChatType();

        if ($chatType !== null) {
            $data['chat_type'] = $chatType;
        }

        $this->config->chatsModel()::query()->updateOrCreate(
            ['chat_id' => $chatId],
            $data,
        );
    }

    /**
     * Связь «чат + пользователь»: апдейты bot_* — это события о боте в чате, а не
     * о пользователе, поэтому user в них может не прийти. Без user связь не
     * заводим: о взаимодействии этого пользователя с ботом мы ничего не знаем.
     */
    private function upsertChatLink(int $chatId, int $userId, MaxChatStatus $status): void
    {
        $this->config->chatUsersModel()::query()->updateOrCreate(
            [
                'chat_id' => $chatId,
                'user_id' => $userId,
            ],
            [
                'status' => $status,
                'last_activity_at' => now(),
            ],
        );
    }

    private function upsertUser(?User $user, int $userId): void
    {
        if ($user === null) {
            return;
        }

        $userModel = $this->config->usersModel();

        $userModel::query()->updateOrCreate(
            ['user_id' => $userId],
            [
                'first_name' => $user->firstName,
                'last_name' => $user->lastName,
                'username' => $user->username,
                'is_bot' => $user->isBot,
                'last_activity_time' => $user->lastActivityTime,
                'name' => $user->name,
                'profile_checked_at' => now(),
            ],
        );
    }

    /**
     * Апдейт активности: дозаполняет chat_type у существующего чата и двигает
     * last_activity_at. Чат не создаёт и статус не меняет. Связь пользователя,
     * если он пришёл, обновляется: апдейт активности — это как раз факт
     * взаимодействия пользователя с ботом.
     */
    private function touchExistingChat(UpdateInfo $info): void
    {
        $chatId = $info->chatId;

        if ($chatId === null) {
            return;
        }

        $chatModel = $this->config->chatsModel();

        $chatType = $info->resolveChatType();

        if ($chatType !== null) {
            $chatModel::query()
                ->where('chat_id', $chatId)
                ->whereNull('chat_type')
                ->update(['chat_type' => $chatType]);
        }

        $chatModel::query()
            ->where('chat_id', $chatId)
            ->update(['last_activity_at' => now()]);

        if ($info->user === null || $info->userId === null) {
            return;
        }

        $this->upsertUser($info->user, $info->userId);

        if ($chatModel::query()->where('chat_id', $chatId)->exists()) {
            $this->config->chatUsersModel()::query()->updateOrCreate(
                ['chat_id' => $chatId, 'user_id' => $info->userId],
                ['last_activity_at' => now()],
            );
        }
    }

    /**
     * Название из события chat_title_changed: запрос к API не нужен, значение
     * пишется в строку чата. Пустой title игнорируется, чтобы не затереть уже
     * известное название.
     */
    private function applyTitle(UpdateInfo $info): void
    {
        $chatId = $info->chatId;
        $title = $info->title === null ? '' : trim($info->title);

        if ($chatId === null || $title === '') {
            return;
        }

        $chatModel = $this->config->chatsModel();

        $chatModel::query()
            ->where('chat_id', $chatId)
            ->update([
                'title' => $title,
                'chat_checked_at' => now(),
            ]);
    }

    /**
     * Запросить метаданные чата, если название ещё неизвестно и посторонние
     * запросы разрешены (chats.fetch_metadata). Название есть только у группы и
     * канала, поэтому для диалога запрос не делается.
     *
     * Правила выбора записи и передачи уже известных значений живут в
     * MaxChatProfileService::ensureMetadata(): здесь только условия «когда».
     */
    private function syncMetadataIfNeeded(Update $update, int $chatId): void
    {
        if (!$this->config->chatsFetchMetadata()) {
            return;
        }

        if (!$update instanceof BotAddedToChatUpdate && !$update instanceof BotStartedUpdate) {
            return;
        }

        $this->chatProfile->ensureMetadata($chatId);
    }
}
