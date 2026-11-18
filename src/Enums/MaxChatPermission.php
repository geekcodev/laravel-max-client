<?php

declare(strict_types=1);

namespace GeekCo\LaravelMaxClient\Enums;

/**
 * Права на локальную запись чата MAX (реестр max_chats).
 *
 * Значения — permission-строки для Gate/spatie. Создание записи в контракт не
 * входит: реестр наполняется вебхуками, ручное создание пакетом не
 * поддерживается.
 */
enum MaxChatPermission: string
{
    case ViewAny = 'max_chat.view.any';
    case View = 'max_chat.view';
    case Edit = 'max_chat.edit';
    case Delete = 'max_chat.delete';
    case Manage = 'max_chat.manage';
}
