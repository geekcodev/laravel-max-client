<?php

declare(strict_types=1);

namespace GeekCo\LaravelMaxClient\Tests\Unit\Policies;

use GeekCo\LaravelMaxClient\Enums\MaxChatPermission;
use GeekCo\LaravelMaxClient\Enums\MaxUserPermission;
use GeekCo\LaravelMaxClient\Models\MaxChat;
use GeekCo\LaravelMaxClient\Models\MaxUser;
use GeekCo\LaravelMaxClient\Policies\MaxChatPolicy;
use GeekCo\LaravelMaxClient\Policies\MaxUserPolicy;
use GeekCo\LaravelMaxClient\Tests\TestCase;
use Illuminate\Auth\GenericUser;
use Illuminate\Support\Facades\Gate;

final class PermissionPolicyTest extends TestCase
{
    public function testPermissionEnumsContainStablePermissionStrings(): void
    {
        self::assertSame([
            'max_user.view.any',
            'max_user.view',
            'max_user.edit',
            'max_user.delete',
            'max_user.manage',
        ], array_column(MaxUserPermission::cases(), 'value'));

        self::assertSame([
            'max_chat.view.any',
            'max_chat.view',
            'max_chat.edit',
            'max_chat.delete',
            'max_chat.manage',
        ], array_column(MaxChatPermission::cases(), 'value'));
    }

    public function testUserPolicyAllowsEveryAbilityWithPermissions(): void
    {
        $user = $this->userWithAllPermissions(true);
        $record = new MaxUser();

        Gate::policy(MaxUser::class, MaxUserPolicy::class);

        self::assertTrue(Gate::forUser($user)->allows('viewAny', MaxUser::class));
        self::assertTrue(Gate::forUser($user)->allows('view', $record));
        self::assertTrue(Gate::forUser($user)->allows('edit', $record));
        self::assertTrue(Gate::forUser($user)->allows('delete', $record));
        self::assertTrue(Gate::forUser($user)->allows('manage', $record));
    }

    public function testChatPolicyAllowsEveryAbilityWithPermissions(): void
    {
        $user = $this->userWithAllPermissions(true);
        $record = new MaxChat();

        Gate::policy(MaxChat::class, MaxChatPolicy::class);

        self::assertTrue(Gate::forUser($user)->allows('viewAny', MaxChat::class));
        self::assertTrue(Gate::forUser($user)->allows('view', $record));
        self::assertTrue(Gate::forUser($user)->allows('edit', $record));
        self::assertTrue(Gate::forUser($user)->allows('delete', $record));
        self::assertTrue(Gate::forUser($user)->allows('manage', $record));
    }

    public function testPoliciesDenyEveryAbilityWithoutPermissions(): void
    {
        $user = $this->userWithAllPermissions(false);

        Gate::policy(MaxUser::class, MaxUserPolicy::class);
        Gate::policy(MaxChat::class, MaxChatPolicy::class);

        self::assertFalse(Gate::forUser($user)->allows('viewAny', MaxUser::class));
        self::assertFalse(Gate::forUser($user)->allows('view', new MaxUser()));
        self::assertFalse(Gate::forUser($user)->allows('edit', new MaxUser()));
        self::assertFalse(Gate::forUser($user)->allows('delete', new MaxUser()));
        self::assertFalse(Gate::forUser($user)->allows('manage', new MaxUser()));

        self::assertFalse(Gate::forUser($user)->allows('viewAny', MaxChat::class));
        self::assertFalse(Gate::forUser($user)->allows('view', new MaxChat()));
        self::assertFalse(Gate::forUser($user)->allows('edit', new MaxChat()));
        self::assertFalse(Gate::forUser($user)->allows('delete', new MaxChat()));
        self::assertFalse(Gate::forUser($user)->allows('manage', new MaxChat()));
    }

    public function testPolicyUsesPermissionStringFromConfig(): void
    {
        config()->set('laravel-max-client.permissions.max_user.edit', 'custom.user.edit');

        Gate::define(MaxUserPermission::Edit->value, static fn (): bool => false);
        Gate::define('custom.user.edit', static fn (): bool => true);

        $policy = new MaxUserPolicy();

        self::assertTrue($policy->edit(new GenericUser(['id' => 1]), new MaxUser()));
    }

    public function testPolicyDeniesGuest(): void
    {
        $userPolicy = new MaxUserPolicy();
        $chatPolicy = new MaxChatPolicy();

        self::assertFalse($userPolicy->viewAny(null));
        self::assertFalse($userPolicy->view(null));
        self::assertFalse($userPolicy->edit(null));
        self::assertFalse($userPolicy->delete(null));
        self::assertFalse($userPolicy->manage(null));

        self::assertFalse($chatPolicy->viewAny(null));
        self::assertFalse($chatPolicy->view(null));
        self::assertFalse($chatPolicy->edit(null));
        self::assertFalse($chatPolicy->delete(null));
        self::assertFalse($chatPolicy->manage(null));
    }

    private function userWithAllPermissions(bool $allowed): GenericUser
    {
        foreach ([...MaxUserPermission::cases(), ...MaxChatPermission::cases()] as $permission) {
            Gate::define($permission->value, static fn (): bool => $allowed);
        }

        return new GenericUser(['id' => 1]);
    }
}
