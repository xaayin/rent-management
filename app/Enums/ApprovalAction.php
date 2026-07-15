<?php

declare(strict_types=1);

namespace App\Enums;

use App\Models\Lease;
use App\Models\Payment;

/**
 * The actions the PRD §6.1 matrix marks `A` — allowed for one role, but only
 * once a supervisor approves. Each maps to the approval-gated permission pair
 * (base = may initiate, "… without approval" = may act directly); see
 * App\Enums\Permission::directVariant() and User::requiresApprovalFor().
 *
 * `waive_fine` is the third `A` cell. It is deliberately absent: fine waivers
 * are not built yet, so there would be nothing to execute on approval. Adding
 * the case here plus a branch in ApprovalService::execute() is all the wiring
 * it needs once the waiver feature lands.
 */
enum ApprovalAction: string
{
    case TerminateLease = 'terminate_lease';
    case ReversePayment = 'reverse_payment';

    /**
     * The base permission a user needs to request this action.
     */
    public function permission(): Permission
    {
        return match ($this) {
            self::TerminateLease => Permission::TerminateLeases,
            self::ReversePayment => Permission::ReversePayments,
        };
    }

    /**
     * The model this action acts on.
     *
     * @return class-string<Lease|Payment>
     */
    public function subjectType(): string
    {
        return match ($this) {
            self::TerminateLease => Lease::class,
            self::ReversePayment => Payment::class,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::TerminateLease => 'Terminate lease',
            self::ReversePayment => 'Reverse payment',
        };
    }
}
