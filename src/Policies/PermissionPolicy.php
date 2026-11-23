<?php

declare(strict_types=1);

namespace GeekCo\LaravelMaxClient\Policies;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;
use LogicException;

/**
 * Базовая Policy реестра MAX: ability policy (viewAny/view/edit/delete/manage)
 * отображается на permission-строку с префиксом кода ресурса (max_user или
 * max_chat). Permission-строка берётся из конфига и проверяется через Gate,
 * поэтому работают и spatie/laravel-permission, и обычные Gate::define.
 */
abstract class PermissionPolicy
{
    /**
     * Код ресурса — префикс permission-строки в конфиге.
     */
    abstract protected function code(): string;

    /**
     * @return array<string, string> ability => permission-строка по умолчанию
     */
    abstract protected function permissions(): array;

    public function viewAny(?Authenticatable $user): bool
    {
        return $this->allows($user, 'view_any');
    }

    public function view(?Authenticatable $user, Model|string|null $model = null): bool
    {
        return $this->allows($user, 'view');
    }

    public function edit(?Authenticatable $user, Model|string|null $model = null): bool
    {
        return $this->allows($user, 'edit');
    }

    public function delete(?Authenticatable $user, Model|string|null $model = null): bool
    {
        return $this->allows($user, 'delete');
    }

    public function manage(?Authenticatable $user, Model|string|null $model = null): bool
    {
        return $this->allows($user, 'manage');
    }

    private function allows(?Authenticatable $user, string $ability): bool
    {
        if ($user === null) {
            return false;
        }

        $permissions = $this->permissions();
        $default = $permissions[$ability] ?? throw new LogicException('Unknown MAX policy ability: '.$ability);

        $permission = config()->string(
            'laravel-max-client.permissions.'.$this->code().'.'.$ability,
            $default,
        );

        return Gate::forUser($user)->allows($permission);
    }
}
