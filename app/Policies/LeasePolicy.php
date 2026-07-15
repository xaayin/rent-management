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
 * Supervisors. The two are separate abilities: `terminate` asks "may you START
 * this?" — which a Land Officer may, by filing an approval request — while
 * `terminateDirectly` asks "may you do it right now, unreviewed?", which only a
 * Supervisor may. Gate the button on the former and the immediate action on the
 * latter; see App\Services\Approvals\ApprovalService.
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

    /**
     * May start a termination — directly, or by requesting approval.
     */
    public function terminate(User $user, Lease $lease): bool
    {
        return $user->mayInitiate(Permission::TerminateLeases);
    }

    /**
     * May terminate with immediate effect, without supervisor review.
     */
    public function terminateDirectly(User $user, Lease $lease): bool
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
