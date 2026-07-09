<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\Permission;
use App\Models\Payment;
use App\Models\User;

/**
 * Recording payments is granted to Finance Officers and Supervisors (§6.1 —
 * note the Administrator is NOT permitted). Reversing a payment is
 * approval-gated: Finance may only initiate; until the approvals workflow
 * exists, only users who may act without approval — Supervisors — reverse
 * directly.
 */
class PaymentPolicy
{
    public function create(User $user): bool
    {
        return $user->hasPermissionTo(Permission::RecordPayments->value);
    }

    public function reverse(User $user, Payment $payment): bool
    {
        return $user->mayActWithoutApproval(Permission::ReversePayments);
    }
}
