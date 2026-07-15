<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\ApprovalRequest;
use App\Models\User;
use App\Services\Approvals\ApprovalService;

/**
 * Who may see and decide supervisor-approval requests (PRD §6.1 `A` cells).
 *
 * The rule throughout: you may decide a request only for an action you could
 * have performed directly yourself. Nothing here grants a new power — it only
 * routes an existing one, so the matrix stays the single source of truth.
 */
class ApprovalRequestPolicy
{
    /**
     * The approvals inbox is visible to anyone who can decide at least one kind
     * of request.
     */
    public function viewAny(User $user): bool
    {
        return app(ApprovalService::class)->decidableActions($user) !== [];
    }

    public function decide(User $user, ApprovalRequest $request): bool
    {
        return $request->isPending()
            && $user->mayActWithoutApproval($request->action->permission());
    }

    /**
     * Only the requester withdraws their own request; a supervisor who
     * disagrees rejects it, which leaves the decision on the record.
     */
    public function cancel(User $user, ApprovalRequest $request): bool
    {
        return $request->isPending() && $request->requested_by === $user->id;
    }
}
