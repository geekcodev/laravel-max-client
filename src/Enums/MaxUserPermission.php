<?php

declare(strict_types=1);

namespace GeekCo\LaravelMaxClient\Enums;

/**
 * Права на локальную запись пользователя MAX (реестр max_users).
 *
 * Значения — permission-строки для Gate/spatie. Создание записи в контракт не
 * входит: реестр наполняется вебхуками, ручное создание пакетом не
 * поддерживается.
 */
enum MaxUserPermission: string
{
    case ViewAny = 'max_user.view.any';
    case View = 'max_user.view';
    case Edit = 'max_user.edit';
    case Delete = 'max_user.delete';
    case Manage = 'max_user.manage';
}
