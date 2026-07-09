<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\Permission;
use App\Models\User;

/**
 * Managing staff users & roles is Administrator-only (PRD §6.1: "Manage users
 * & roles"). Authorized server-side on every action, not just in the UI.
 */
class UserPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermissionTo(Permission::ManageUsers->value);
    }

    public function create(User $user): bool
    {
        return $user->hasPermissionTo(Permission::ManageUsers->value);
    }

    public function update(User $user, User $model): bool
    {
        return $user->hasPermissionTo(Permission::ManageUsers->value);
    }

    public function delete(User $user, User $model): bool
    {
        // Never allow deleting your own account.
        return $user->hasPermissionTo(Permission::ManageUsers->value)
            && $user->isNot($model);
    }
}
