<?php

declare(strict_types=1);

namespace App\Services\Billing;

use App\Exceptions\InvalidFineRuleException;
use App\Models\FineRule;
use App\Models\Invoice;
use App\Models\Lease;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The ONE place a lease's fine periods are written (FR-FIN-09).
 *
 * It holds a single invariant: the periods on a lease never overlap, so
 * "which rule fines this invoice" always has exactly one answer. Gaps between
 * periods are legitimate and mean no fine accrues — a fine holiday.
 *
 * Periods are effectively append-only. The only mutation allowed on a period
 * that has already begun is closing its open end (never moving its start, and
 * never shortening it below what has already run), because the money it has
 * already governed must stay explainable.
 */
class FineRuleScheduler
{
    /**
     * Add a period to a lease, superseding an open-ended predecessor.
     *
     * @param  array<string, mixed>  $attributes  the FineRule columns, including
     *                                            effective_from and a nullable effective_to
     */
    public function schedule(Lease $lease, array $attributes): FineRule
    {
        $from = CarbonImmutable::parse((string) $attributes['effective_from'])->startOfDay();
        $to = ($attributes['effective_to'] ?? null) !== null && $attributes['effective_to'] !== ''
            ? CarbonImmutable::parse((string) $attributes['effective_to'])->startOfDay()
            : null;

        if ($to !== null && $to->lessThan($from)) {
            throw InvalidFineRuleException::endsBeforeItStarts();
        }

        return DB::transaction(function () use ($lease, $attributes, $from, $to): FineRule {
            // Lock the lease's periods so two supervisors cannot each pass the
            // overlap check and then both write.
            $existing = $lease->fineRules()->lockForUpdate()->orderBy('effective_from')->get();

            foreach ($existing as $rule) {
                if (! $this->overlaps($rule, $from, $to)) {
                    continue;
                }

                // The everyday case: a new period supersedes the running one,
                // which is closed the day before the new one starts.
                if ($rule->isOpenEnded() && CarbonImmutable::parse($rule->effective_from->toDateString())->lessThan($from)) {
                    $rule->update(['effective_to' => $from->subDay()->toDateString()]);

                    continue;
                }

                throw InvalidFineRuleException::overlaps($rule->periodLabel());
            }

            return $lease->fineRules()->create(array_merge($attributes, [
                'effective_from' => $from->toDateString(),
                'effective_to' => $to?->toDateString(),
            ]));
        });
    }

    /**
     * What would happen if this period were saved — answered without writing
     * anything, so the form can warn before the click rather than after.
     *
     * @param  int|null  $ignoreRuleId  the period being edited, which cannot clash with itself
     * @return array{status: string, message: string}
     *                                                status: ok | supersedes | conflict
     */
    public function inspect(Lease $lease, CarbonImmutable $from, ?CarbonImmutable $to, ?int $ignoreRuleId = null): array
    {
        if ($to !== null && $to->lessThan($from)) {
            return ['status' => 'conflict', 'message' => InvalidFineRuleException::endsBeforeItStarts()->getMessage()];
        }

        foreach ($lease->fineRules()->orderBy('effective_from')->get() as $rule) {
            // A period being edited never conflicts with itself.
            if ($rule->id === $ignoreRuleId || ! $this->overlaps($rule, $from, $to)) {
                continue;
            }

            if ($ignoreRuleId !== null) {
                return ['status' => 'conflict', 'message' => InvalidFineRuleException::overlaps($rule->periodLabel())->getMessage()];
            }

            if ($rule->isOpenEnded() && CarbonImmutable::parse($rule->effective_from->toDateString())->lessThan($from)) {
                return [
                    'status' => 'supersedes',
                    'message' => 'The period starting '.$rule->effective_from->format('j M Y')
                        .' will be ended on '.$from->subDay()->format('j M Y').' so this one can take over.',
                ];
            }

            return ['status' => 'conflict', 'message' => InvalidFineRuleException::overlaps($rule->periodLabel())->getMessage()];
        }

        return ['status' => 'ok', 'message' => 'This period sits in free time — nothing else changes.'];
    }

    /**
     * Change a period in place. Allowed only while no invoice has been raised
     * inside it: such a period has never governed money, so rewriting it is
     * indistinguishable from deleting it and adding another. Once an invoice
     * falls in the window the rule is what its fine is recomputed from nightly,
     * and it must be ended rather than rewritten.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function update(FineRule $rule, array $attributes): FineRule
    {
        $from = CarbonImmutable::parse((string) $attributes['effective_from'])->startOfDay();
        $to = ($attributes['effective_to'] ?? null) !== null && $attributes['effective_to'] !== ''
            ? CarbonImmutable::parse((string) $attributes['effective_to'])->startOfDay()
            : null;

        if ($to !== null && $to->lessThan($from)) {
            throw InvalidFineRuleException::endsBeforeItStarts();
        }

        return DB::transaction(function () use ($rule, $attributes, $from, $to): FineRule {
            $rule->refresh();

            if (($dependents = $this->dependentInvoiceCount($rule)) > 0) {
                throw InvalidFineRuleException::cannotEdit($dependents);
            }

            // Every other period still has to stay out of the way; a period
            // never conflicts with itself.
            foreach ($rule->lease->fineRules()->lockForUpdate()->orderBy('effective_from')->get() as $other) {
                if ($other->id === $rule->id || ! $this->overlaps($other, $from, $to)) {
                    continue;
                }

                throw InvalidFineRuleException::overlaps($other->periodLabel());
            }

            $rule->update(array_merge($attributes, [
                'effective_from' => $from->toDateString(),
                'effective_to' => $to?->toDateString(),
            ]));

            return $rule->refresh();
        });
    }

    /**
     * How many invoices this period is responsible for: anything it has already
     * fined, plus anything issued inside its effective window that it has simply
     * not fined YET (an invoice not past its due date has no fine computed, but
     * this period is what will compute it).
     *
     * Zero means the period can be edited or deleted without changing a single
     * figure anywhere.
     */
    public function dependentInvoiceCount(FineRule $rule): int
    {
        $window = $this->effectiveWindow($rule);

        return Invoice::query()
            ->where('lease_id', $rule->lease_id)
            ->where(function ($query) use ($rule, $window) {
                $query
                    ->where('fine_rule_id', $rule->id)
                    ->orWhere(function ($issued) use ($window) {
                        $issued->whereDate('created_at', '>=', $window['from']->toDateString());

                        if ($window['to'] !== null) {
                            $issued->whereDate('created_at', '<=', $window['to']->toDateString());
                        }
                    });
            })
            ->count();
    }

    /**
     * The span this period actually governs. A rule left open-ended only runs
     * until the next one starts — legacy rows all left the end open, and
     * resolution has always been "the latest start wins", so the window that
     * matters is the clamped one, not the one literally stored.
     *
     * @param  Collection<int, FineRule>|null  $siblings  preloaded periods, to avoid a query per rule
     * @return array{from: CarbonImmutable, to: ?CarbonImmutable}
     */
    public function effectiveWindow(FineRule $rule, mixed $siblings = null): array
    {
        $from = CarbonImmutable::parse($rule->effective_from->toDateString());
        $to = $rule->isOpenEnded() ? null : CarbonImmutable::parse($rule->effective_to->toDateString());

        $next = $siblings !== null
            ? $siblings->first(fn (FineRule $other): bool => $other->id !== $rule->id
                && CarbonImmutable::parse($other->effective_from->toDateString())->greaterThan($from))
            : FineRule::query()
                ->where('lease_id', $rule->lease_id)
                ->whereKeyNot($rule->id)
                ->whereDate('effective_from', '>', $from->toDateString())
                ->orderBy('effective_from')->orderBy('id')
                ->first();

        if ($next !== null) {
            $supersededOn = CarbonImmutable::parse($next->effective_from->toDateString())->subDay();

            if ($to === null || $to->greaterThan($supersededOn)) {
                $to = $supersededOn;
            }
        }

        return ['from' => $from, 'to' => $to];
    }

    /**
     * End an open period on a chosen day (inclusive). Used when the council
     * stops fining without immediately replacing the rule.
     */
    public function close(FineRule $rule, CarbonImmutable $on): FineRule
    {
        if (! $rule->isOpenEnded()) {
            throw InvalidFineRuleException::alreadyClosed($rule->periodLabel());
        }

        $start = CarbonImmutable::parse($rule->effective_from->toDateString());

        if ($on->startOfDay()->lessThan($start)) {
            throw InvalidFineRuleException::closedBeforeStart($start->format('j M Y'));
        }

        $rule->update(['effective_to' => $on->startOfDay()->toDateString()]);

        return $rule->refresh();
    }

    /**
     * Delete a period no invoice depends on — a mistyped entry, or one that ran
     * over a quiet stretch and fined nothing. A period that any invoice falls
     * inside must be ended rather than erased.
     */
    public function remove(FineRule $rule): void
    {
        DB::transaction(function () use ($rule): void {
            if (($dependents = $this->dependentInvoiceCount($rule)) > 0) {
                throw InvalidFineRuleException::cannotRemove($dependents);
            }

            $rule->delete();
        });
    }

    /**
     * The lease's fine history as a continuous, ordered strip of segments:
     * every period, with the no-fine gaps between them made explicit so nobody
     * has to notice a gap by comparing dates.
     *
     * @return list<array{type: string, from: CarbonImmutable, to: ?CarbonImmutable, rule: ?FineRule, dependents: int, editable: bool, status: string, label: string}>
     */
    public function timeline(Lease $lease, ?CarbonImmutable $today = null): array
    {
        $today ??= CarbonImmutable::parse(today()->toDateString());

        $rules = $lease->fineRules()
            ->orderBy('effective_from')->orderBy('id')->get();

        $segments = [];
        $previousEnd = null;

        foreach ($rules as $index => $rule) {
            $from = CarbonImmutable::parse($rule->effective_from->toDateString());

            if ($previousEnd !== null && $from->greaterThan($previousEnd->addDay())) {
                $segments[] = $this->gap($previousEnd->addDay(), $from->subDay(), $today);
            }

            $to = $rule->isOpenEnded() ? null : CarbonImmutable::parse($rule->effective_to->toDateString());

            // Rows written before periods existed all left the end open, so a
            // lease can carry several. Resolution has always been "the latest
            // start wins", so show each as running only until the next begins —
            // otherwise the timeline would claim coverage that never applied.
            $next = $rules->get($index + 1);
            $supersededOn = $next !== null
                ? CarbonImmutable::parse($next->effective_from->toDateString())->subDay()
                : null;

            $displayTo = $supersededOn !== null && ($to === null || $to->greaterThan($supersededOn))
                ? $supersededOn
                : $to;

            $dependents = $this->dependentInvoiceCount($rule);

            $segments[] = [
                'type' => 'rule',
                'from' => $from,
                'to' => $displayTo,
                'rule' => $rule,
                'dependents' => $dependents,
                'editable' => $dependents === 0,
                'status' => $displayTo !== null && $today->greaterThan($displayTo)
                    ? 'ended'
                    : $rule->statusOn($today),
                'label' => $displayTo === null
                    ? $from->format('j M Y').' onwards'
                    : $from->format('j M Y').' – '.$displayTo->format('j M Y'),
            ];

            if ($displayTo === null) {
                return $segments; // an open period runs to the horizon
            }

            $previousEnd = $displayTo;
        }

        // A trailing gap: the last period ended and nothing replaced it, so no
        // fine accrues from the next day on. Worth saying out loud.
        if ($previousEnd !== null) {
            $segments[] = $this->gap($previousEnd->addDay(), null, $today);
        }

        return $segments;
    }

    /**
     * @return array{type: string, from: CarbonImmutable, to: ?CarbonImmutable, rule: null, dependents: int, editable: bool, status: string, label: string}
     */
    private function gap(CarbonImmutable $from, ?CarbonImmutable $to, CarbonImmutable $today): array
    {
        $status = $today->lessThan($from)
            ? 'scheduled'
            : (($to === null || ! $today->greaterThan($to)) ? 'active' : 'ended');

        return [
            'type' => 'gap',
            'from' => $from,
            'to' => $to,
            'rule' => null,
            'dependents' => 0,
            'editable' => false,
            'status' => $status,
            'label' => $to === null
                ? $from->format('j M Y').' onwards'
                : $from->format('j M Y').' – '.$to->format('j M Y'),
        ];
    }

    /** Do two closed/open windows share at least one day? */
    private function overlaps(FineRule $rule, CarbonImmutable $from, ?CarbonImmutable $to): bool
    {
        $ruleFrom = CarbonImmutable::parse($rule->effective_from->toDateString());
        $ruleTo = $rule->isOpenEnded() ? null : CarbonImmutable::parse($rule->effective_to->toDateString());

        $startsAfterRuleEnds = $ruleTo !== null && $from->greaterThan($ruleTo);
        $endsBeforeRuleStarts = $to !== null && $to->lessThan($ruleFrom);

        return ! $startsAfterRuleEnds && ! $endsBeforeRuleStarts;
    }
}
