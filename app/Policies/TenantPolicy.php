<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\Permission;
use App\Models\Tenant;
use App\Models\User;

/**
 * Managing tenants is granted to Land/Lease Officers and Supervisors
 * (PRD §6.1 "Manage tenants").
 */
class TenantPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermissionTo(Permission::ManageTenants->value);
    }

    public function create(User $user): bool
    {
        return $user->hasPermissionTo(Permission::ManageTenants->value);
    }

    public function update(User $user, Tenant $tenant): bool
    {
        return $user->hasPermissionTo(Permission::ManageTenants->value);
    }
}
