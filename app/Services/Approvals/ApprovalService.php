<?php

declare(strict_types=1);

namespace App\Services\Approvals;

use App\Enums\ApprovalAction;
use App\Enums\ApprovalStatus;
use App\Enums\LeaseStatus;
use App\Exceptions\InvalidApprovalException;
use App\Exceptions\InvalidPaymentException;
use App\Models\ApprovalRequest;
use App\Models\Lease;
use App\Models\Payment;
use App\Models\User;
use App\Services\Billing\PaymentRecorder;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * The supervisor-approval workflow for the PRD §6.1 `A` cells.
 *
 * The matrix distinguishes three answers per action: not allowed, allowed
 * directly (`✓`), and allowed but requiring approval (`A`). This service owns
 * the third: a user who `requiresApprovalFor()` an action files a request here
 * rather than acting, and a user who `mayActWithoutApproval()` decides it.
 * Users who may act directly never touch this — they call the underlying
 * action, as they always have.
 *
 * The action itself is replayed on approval from the request's own reason and
 * payload, so what is carried out is what was asked for and reviewed — never
 * re-read from today's form state.
 */
class ApprovalService
{
    public function __construct(private readonly PaymentRecorder $payments) {}

    /**
     * File a request for approval. The subject is left untouched.
     *
     * @param  array<string, mixed>  $payload
     */
    public function request(
        User $user,
        ApprovalAction $action,
        Model $subject,
        string $reason,
        array $payload = [],
    ): ApprovalRequest {
        if (! $user->mayInitiate($action->permission())) {
            throw InvalidApprovalException::doesNotRequireApproval();
        }

        // Someone who may act directly has no business queueing work for a
        // supervisor — that would let them launder a direct action through the
        // approvals log as if it had been reviewed.
        if (! $user->requiresApprovalFor($action->permission())) {
            throw InvalidApprovalException::doesNotRequireApproval();
        }

        $this->assertActionable($action, $subject);

        try {
            return ApprovalRequest::create([
                'action' => $action,
                'subject_type' => $subject->getMorphClass(),
                'subject_id' => $subject->getKey(),
                'status' => ApprovalStatus::Pending,
                'reason' => $reason,
                'payload' => $payload ?: null,
                'requested_by' => $user->id,
                'requested_at' => now(),
                'pending_key' => ApprovalRequest::pendingKeyFor($action, $subject),
            ]);
        } catch (QueryException $e) {
            // The unique index on `pending_key` is the real guard: two officers
            // filing at once both pass a "is one pending?" read, and only one
            // survives the insert.
            throw InvalidApprovalException::alreadyPending();
        }
    }

    /**
     * Approve a pending request and carry the action out, atomically.
     */
    public function approve(ApprovalRequest $request, User $approver, ?string $note = null): void
    {
        $this->assertDecidable($request, $approver);

        DB::transaction(function () use ($request, $approver, $note): void {
            /*
             * Re-read the request under a lock before executing. Two supervisors
             * hitting Approve at the same moment would otherwise both pass the
             * status check and both execute — reversing a payment twice.
             */
            $fresh = ApprovalRequest::whereKey($request->getKey())->lockForUpdate()->firstOrFail();

            if (! $fresh->isPending()) {
                throw InvalidApprovalException::alreadyDecided();
            }

            $subject = $fresh->subject;

            // The subject may have moved on while this sat in the queue.
            $this->assertActionable($fresh->action, $subject);

            $this->execute($fresh, $subject, $approver);

            $fresh->update([
                'status' => ApprovalStatus::Approved,
                'decided_by' => $approver->id,
                'decided_at' => now(),
                'decision_note' => $note,
                'pending_key' => null,
            ]);
        });
    }

    /**
     * Refuse a pending request. The subject is left untouched; the note is
     * mandatory so the requester learns why.
     */
    public function reject(ApprovalRequest $request, User $approver, string $note): void
    {
        $this->assertDecidable($request, $approver);

        if (! $request->isPending()) {
            throw InvalidApprovalException::alreadyDecided();
        }

        $request->update([
            'status' => ApprovalStatus::Rejected,
            'decided_by' => $approver->id,
            'decided_at' => now(),
            'decision_note' => $note,
            'pending_key' => null,
        ]);
    }

    /**
     * Withdraw a request. Only the person who filed it may do so — a
     * supervisor who disagrees rejects it, leaving a reviewed decision behind
     * rather than making it disappear.
     */
    public function cancel(ApprovalRequest $request, User $user): void
    {
        if ($request->requested_by !== $user->id) {
            throw InvalidApprovalException::doesNotRequireApproval();
        }

        if (! $request->isPending()) {
            throw InvalidApprovalException::alreadyDecided();
        }

        $request->update([
            'status' => ApprovalStatus::Cancelled,
            'decided_at' => now(),
            'pending_key' => null,
        ]);
    }

    /**
     * Requests this user is entitled to decide — those whose action they may
     * perform directly.
     *
     * @return list<ApprovalAction>
     */
    public function decidableActions(User $user): array
    {
        return array_values(array_filter(
            ApprovalAction::cases(),
            fn (ApprovalAction $action) => $user->mayActWithoutApproval($action->permission()),
        ));
    }

    public function pendingCountFor(User $user): int
    {
        $actions = $this->decidableActions($user);

        if ($actions === []) {
            return 0;
        }

        return ApprovalRequest::pending()
            ->whereIn('action', array_map(fn (ApprovalAction $a) => $a->value, $actions))
            ->count();
    }

    /**
     * Carry out the approved action, as the approver.
     */
    private function execute(ApprovalRequest $request, Model $subject, User $approver): void
    {
        match ($request->action) {
            ApprovalAction::TerminateLease => $subject->terminate($request->reason),
            ApprovalAction::ReversePayment => $this->payments->reverse(
                $subject,
                $request->reason,
                CarbonImmutable::parse(today()->toDateString()),
                $approver,
            ),
        };
    }

    private function assertDecidable(ApprovalRequest $request, User $approver): void
    {
        if (! $approver->mayActWithoutApproval($request->action->permission())) {
            throw InvalidApprovalException::doesNotRequireApproval();
        }
    }

    /**
     * Whether the action still makes sense against the subject as it stands.
     * Checked when filing (fail fast) and again at approval (the world moved).
     */
    private function assertActionable(ApprovalAction $action, Model $subject): void
    {
        match ($action) {
            ApprovalAction::TerminateLease => $this->assertLeaseTerminable($subject),
            ApprovalAction::ReversePayment => $this->assertPaymentReversible($subject),
        };
    }

    private function assertLeaseTerminable(Lease $lease): void
    {
        if ($lease->status === LeaseStatus::Terminated) {
            throw InvalidApprovalException::subjectNoLongerActionable(
                'This lease has already been terminated.'
            );
        }
    }

    private function assertPaymentReversible(Payment $payment): void
    {
        try {
            $this->payments->assertReversible($payment);
        } catch (InvalidPaymentException $e) {
            throw InvalidApprovalException::subjectNoLongerActionable($e->getMessage());
        }
    }
}
