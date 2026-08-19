<?php

declare(strict_types=1);

namespace App\Services\Collections;

use App\Enums\FollowUpState;
use App\Enums\InvoiceStatus;
use App\Enums\PromiseOutcome;
use App\Models\ArrearsContact;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Turns the arrears list into a worklist: who to chase today, who has promised
 * and can be left alone until their date, and who broke a promise and should be
 * chased first.
 *
 * Nothing here decides whether a promise was kept — the payment ledger does,
 * every time the queue is read. A promise cannot go stale, be forgotten, or be
 * marked "done" by someone who did not actually collect the money.
 */
class ArrearsFollowUpService
{
    /**
     * The chasing queue, most urgent first.
     *
     * @param  string  $filter  attention | promised | all
     * @return Collection<int, array<string, mixed>>
     */
    public function queue(CarbonImmutable $today, string $filter = 'attention'): Collection
    {
        $today = $today->startOfDay();

        $rows = $this->arrearsByTenant($today)
            ->map(function (array $row) use ($today): array {
                $contact = $this->latestContactFor($row['tenant']);
                $outcome = $contact !== null
                    ? $this->outcomeFor($contact, $today)
                    : PromiseOutcome::None;

                $row['last_contact'] = $contact;
                $row['promise_outcome'] = $outcome;
                $row['state'] = $this->stateFor($contact, $outcome, $today);

                return $row;
            })
            ->filter(fn (array $row): bool => match ($filter) {
                'attention' => $row['state']->needsAttention(),
                'promised' => $row['state'] === FollowUpState::Promised,
                default => true,
            });

        // Priority first, then the oldest debt — the two things a collector
        // actually sorts by.
        return $rows
            ->sortBy([
                fn (array $a, array $b): int => $a['state']->priority() <=> $b['state']->priority(),
                fn (array $a, array $b): int => $b['days_overdue'] <=> $a['days_overdue'],
            ])
            ->values();
    }

    /**
     * The headline split the council asks for: of everything overdue, how much
     * has somebody actually secured a promise against, and how much has not.
     *
     * @return array{promised: Money, unsecured: Money, total: Money, needs_attention: int}
     */
    public function summary(CarbonImmutable $today): array
    {
        $rows = $this->queue($today, 'all');

        $promised = $rows
            ->filter(fn (array $row): bool => $row['state'] === FollowUpState::Promised)
            ->sum(fn (array $row): int => $row['outstanding']->laari);

        $total = $rows->sum(fn (array $row): int => $row['outstanding']->laari);

        return [
            'promised' => Money::fromLaari((int) $promised),
            'unsecured' => Money::fromLaari((int) $total - (int) $promised),
            'total' => Money::fromLaari((int) $total),
            'needs_attention' => $rows->filter(fn (array $row): bool => $row['state']->needsAttention())->count(),
        ];
    }

    public function needsAttentionCount(CarbonImmutable $today): int
    {
        return $this->queue($today, 'attention')->count();
    }

    /**
     * Record a contact. The outstanding balance is snapshotted so the history
     * still reads correctly once the balance has moved on.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function log(Tenant $tenant, array $attributes, ?User $by = null): ArrearsContact
    {
        $promisedAmount = ($attributes['promised_amount'] ?? '') !== ''
            ? Money::fromRufiyaa((string) $attributes['promised_amount'])->laari
            : null;

        return $tenant->arrearsContacts()->create([
            'contacted_on' => $attributes['contacted_on'] ?? CarbonImmutable::parse(today()->toDateString())->toDateString(),
            'channel' => $attributes['channel'] ?? 'call',
            'note' => trim((string) $attributes['note']),
            'promised_on' => ($attributes['promised_on'] ?? '') !== '' ? $attributes['promised_on'] : null,
            'promised_amount_laari' => $promisedAmount,
            'outstanding_at_contact_laari' => $tenant->outstandingBalance()->laari,
            'recorded_by' => $by?->id,
        ]);
    }

    /**
     * How a promise stands as of a date, judged purely on money received since
     * the contact. Reversals are negative rows, so a payment that bounced
     * un-keeps the promise on its own.
     */
    public function outcomeFor(ArrearsContact $contact, CarbonImmutable $today): PromiseOutcome
    {
        if (! $contact->isPromise()) {
            return PromiseOutcome::None;
        }

        $paid = $this->netPaidSince($contact->tenant_id, CarbonImmutable::parse($contact->contacted_on->toDateString()));

        if ($paid >= $contact->promisedTargetLaari()) {
            return PromiseOutcome::Kept;
        }

        return $today->startOfDay()->greaterThan(CarbonImmutable::parse($contact->promised_on->toDateString()))
            ? PromiseOutcome::Broken
            : PromiseOutcome::Open;
    }

    /** @return Collection<int, ArrearsContact> */
    public function historyFor(Tenant $tenant, int $limit = 20): Collection
    {
        return $tenant->arrearsContacts()
            ->with('recordedBy')
            ->orderByDesc('contacted_on')->orderByDesc('id')
            ->limit($limit)
            ->get();
    }

    private function stateFor(?ArrearsContact $contact, PromiseOutcome $outcome, CarbonImmutable $today): FollowUpState
    {
        if ($contact === null) {
            return FollowUpState::NeverContacted;
        }

        return match ($outcome) {
            PromiseOutcome::Broken => FollowUpState::Broken,
            PromiseOutcome::Open => FollowUpState::Promised,
            // A kept promise that still leaves a balance, or a plain note:
            // chase again once the contact has gone cold.
            default => CarbonImmutable::parse($contact->contacted_on->toDateString())
                ->addDays((int) config('collections.follow_up_stale_days', 14))
                ->lessThan($today)
                    ? FollowUpState::Stale
                    : FollowUpState::Recent,
        };
    }

    /**
     * Net money received from a tenant on or after a date — payments minus
     * reversals, which are stored as negative rows.
     */
    private function netPaidSince(int $tenantId, CarbonImmutable $since): int
    {
        return (int) Payment::query()
            ->whereHas('invoice.lease', fn ($query) => $query->where('tenant_id', $tenantId))
            ->whereDate('payment_date', '>=', $since->toDateString())
            ->sum('amount_laari');
    }

    private function latestContactFor(Tenant $tenant): ?ArrearsContact
    {
        return $tenant->arrearsContacts()
            ->with('recordedBy')
            ->orderByDesc('contacted_on')->orderByDesc('id')
            ->first();
    }

    /**
     * Every tenant with something past due, with what they owe and how long the
     * oldest piece of it has been outstanding.
     *
     * @return Collection<int, array<string, mixed>>
     */
    private function arrearsByTenant(CarbonImmutable $today): Collection
    {
        return Invoice::query()
            ->with('lease.tenant')
            ->whereDate('due_date', '<', $today->toDateString())
            ->whereIn('status', [
                InvoiceStatus::Issued->value,
                InvoiceStatus::PartlyPaid->value,
                InvoiceStatus::Overdue->value,
            ])
            ->get()
            ->filter(fn (Invoice $invoice): bool => $invoice->outstandingTotalLaari() > 0
                && $invoice->lease?->tenant !== null)
            ->groupBy(fn (Invoice $invoice): int => $invoice->lease->tenant_id)
            ->map(function (Collection $invoices) use ($today): array {
                $oldestDue = $invoices->min(fn (Invoice $invoice): string => $invoice->due_date->toDateString());

                return [
                    'tenant' => $invoices->first()->lease->tenant,
                    'outstanding' => Money::fromLaari(
                        (int) $invoices->sum(fn (Invoice $invoice): int => $invoice->outstandingTotalLaari()),
                    ),
                    'invoice_count' => $invoices->count(),
                    'oldest_due' => CarbonImmutable::parse($oldestDue),
                    'days_overdue' => (int) CarbonImmutable::parse($oldestDue)->diffInDays($today),
                ];
            })
            ->values();
    }
}
