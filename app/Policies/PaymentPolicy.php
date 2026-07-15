<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\Permission;
use App\Models\Payment;
use App\Models\User;

/**
 * Recording payments is granted to Finance Officers and Supervisors (§6.1 —
 * note the Administrator is NOT permitted).
 *
 * Reversing a payment is approval-gated: `reverse` asks "may you START this?"
 * — which a Finance Officer may, by filing an approval request — while
 * `reverseDirectly` asks "may you reverse it right now, unreviewed?", which
 * only a Supervisor may. See App\Services\Approvals\ApprovalService.
 */
class PaymentPolicy
{
    public function create(User $user): bool
    {
        return $user->hasPermissionTo(Permission::RecordPayments->value);
    }

    /**
     * May start a reversal — directly, or by requesting approval.
     */
    public function reverse(User $user, Payment $payment): bool
    {
        return $user->mayInitiate(Permission::ReversePayments);
    }

    /**
     * May reverse with immediate effect, without supervisor review.
     */
    public function reverseDirectly(User $user, Payment $payment): bool
    {
        return $user->mayActWithoutApproval(Permission::ReversePayments);
    }
}
