<?php

declare(strict_types=1);

namespace GeekCo\LaravelMaxClient\Policies;

use GeekCo\LaravelMaxClient\Enums\MaxChatPermission;

final class MaxChatPolicy extends PermissionPolicy
{
    protected function code(): string
    {
        return 'max_chat';
    }

    protected function permissions(): array
    {
        return [
            'view_any' => MaxChatPermission::ViewAny->value,
            'view' => MaxChatPermission::View->value,
            'edit' => MaxChatPermission::Edit->value,
            'delete' => MaxChatPermission::Delete->value,
            'manage' => MaxChatPermission::Manage->value,
        ];
    }
}
