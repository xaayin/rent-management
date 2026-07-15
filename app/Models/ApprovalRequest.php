<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ApprovalAction;
use App\Enums\ApprovalStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * A request for supervisor approval of a §6.1 `A`-cell action.
 *
 * Created by a user who `requiresApprovalFor()` the action; carried out — or
 * refused — by one who `mayActWithoutApproval()`. See ApprovalService: all
 * state changes go through it, never through this model directly.
 */
class ApprovalRequest extends Model
{
    use LogsActivity;

    protected $fillable = [
        'action', 'subject_type', 'subject_id', 'status', 'reason', 'payload',
        'requested_by', 'requested_at', 'decided_by', 'decided_at',
        'decision_note', 'pending_key',
    ];

    /**
     * The value of `pending_key` for an open request. Kept here so the service
     * and the DB's unique index agree on one definition.
     */
    public static function pendingKeyFor(ApprovalAction $action, Model $subject): string
    {
        return $action->value.':'.$subject->getMorphClass().':'.$subject->getKey();
    }

    /** @return MorphTo<Model, $this> */
    public function subject(): MorphTo
    {
        return $this->morphTo();
    }

    /** @return BelongsTo<User, $this> */
    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    /** @return BelongsTo<User, $this> */
    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    /** @param Builder<$this> $query */
    public function scopePending(Builder $query): void
    {
        $query->where('status', ApprovalStatus::Pending->value);
    }

    public function isPending(): bool
    {
        return $this->status === ApprovalStatus::Pending;
    }

    /**
     * A one-line description of what is being asked for, for the approvals
     * inbox — a supervisor must be able to judge the request without opening
     * the underlying record.
     */
    public function subjectSummary(): string
    {
        $subject = $this->subject;

        if ($subject === null) {
            return 'Record no longer available';
        }

        return match ($this->action) {
            ApprovalAction::TerminateLease => $subject->agreement_number
                .' · '.($subject->property?->name ?? '—')
                .' · '.($subject->tenant?->name ?? '—'),
            ApprovalAction::ReversePayment => 'Receipt '.$subject->receipt_number
                .' · '.$subject->amount()->format()
                .' · '.($subject->invoice?->lease?->tenant?->name ?? '—'),
        };
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['action', 'status', 'reason', 'decision_note', 'requested_by', 'decided_by'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges()
            ->useLogName('approval');
    }

    protected function casts(): array
    {
        return [
            'action' => ApprovalAction::class,
            'status' => ApprovalStatus::class,
            'payload' => 'array',
            'requested_at' => 'datetime',
            'decided_at' => 'datetime',
        ];
    }
}
