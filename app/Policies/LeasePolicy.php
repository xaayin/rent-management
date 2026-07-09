<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\Permission;
use App\Models\Lease;
use App\Models\User;

/**
 * Creating/amending leases is granted to Land/Lease Officers and Supervisors
 * (PRD §6.1 "Create / amend leases").
 *
 * Terminating a lease is `A` for Land Officers (requires approval) and `✓` for
 * Supervisors. Until the approvals workflow exists (Slice 4/5), only users who
 * may act without approval — Supervisors — can terminate directly.
 */
class LeasePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermissionTo(Permission::ManageLeases->value);
    }

    public function create(User $user): bool
    {
        return $user->hasPermissionTo(Permission::ManageLeases->value);
    }

    public function update(User $user, Lease $lease): bool
    {
        return $user->hasPermissionTo(Permission::ManageLeases->value);
    }

    public function terminate(User $user, Lease $lease): bool
    {
        return $user->mayActWithoutApproval(Permission::TerminateLeases);
    }

    /**
     * Fine-rule configuration is a distinct §6.1 action ("Configure fine
     * rules") granted only to Administrators and Supervisors.
     */
    public function configureFineRule(User $user, Lease $lease): bool
    {
        return $user->hasPermissionTo(Permission::ConfigureFineRules->value);
    }
}
