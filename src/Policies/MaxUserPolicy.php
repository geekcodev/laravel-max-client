<?php

declare(strict_types=1);

namespace GeekCo\LaravelMaxClient\Policies;

use GeekCo\LaravelMaxClient\Enums\MaxUserPermission;

final class MaxUserPolicy extends PermissionPolicy
{
    protected function code(): string
    {
        return 'max_user';
    }

    protected function permissions(): array
    {
        return [
            'view_any' => MaxUserPermission::ViewAny->value,
            'view' => MaxUserPermission::View->value,
            'edit' => MaxUserPermission::Edit->value,
            'delete' => MaxUserPermission::Delete->value,
            'manage' => MaxUserPermission::Manage->value,
        ];
    }
}
