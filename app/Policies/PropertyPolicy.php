<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\Permission;
use App\Models\Property;
use App\Models\User;

/**
 * Managing properties is granted to Land/Lease Officers and Supervisors
 * (PRD §6.1 "Manage properties").
 */
class PropertyPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermissionTo(Permission::ManageProperties->value);
    }

    public function create(User $user): bool
    {
        return $user->hasPermissionTo(Permission::ManageProperties->value);
    }

    public function update(User $user, Property $property): bool
    {
        return $user->hasPermissionTo(Permission::ManageProperties->value);
    }
}
