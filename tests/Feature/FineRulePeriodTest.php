<?php

declare(strict_types=1);

use App\Enums\InvoiceLineType;
use App\Exceptions\InvalidFineRuleException;
use App\Livewire\Leases\Index as LeasesIndex;
use App\Models\FineRule;
use App\Models\Invoice;
use App\Models\Lease;
use App\Models\User;
use App\Services\Billing\FineRuleScheduler;
use App\Services\Billing\InvoiceGenerator;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\artisan;

/**
 * Fine rules are effective-dated PERIODS: each rule owns a window
 * [effective_from, effective_to] (a null end means "and onwards"). The rule
 * that fines an invoice is the one whose window contains the invoice's issue
 * date (§5.3.22); a date in a gap between periods accrues no fine at all,
 * which is how a council grants a fine holiday.
 *
 * Windows for one lease may never overlap — otherwise "which rule applies"
 * has two answers, and money must never be ambiguous.
 */
function periodLease(): Lease
{
    return Lease::factory()->active()->flat(50_000)->create([
        'start_date' => '2026-01-01',
        'rent_start_date' => '2026-01-01',
        'expiry_date' => '2036-01-01',
        'due_day' => 10,
    ]);
}

/** An invoice for the given month, with its issue date pinned. */
function invoiceIssuedOn(Lease $lease, string $month, string $issuedOn): Invoice
{
    $invoice = app(InvoiceGenerator::class)->generate($lease, CarbonImmutable::parse($month));
    $invoice->forceFill(['created_at' => $issuedOn.' 09:00:00'])->saveQuietly();

    return $invoice->refresh();
}

function scheduleRule(Lease $lease, array $attributes = []): FineRule
{
    return app(FineRuleScheduler::class)->schedule($lease, array_merge([
        'method' => 'flat_per_day',
        'base' => 'rent',
        'allowance_days' => 0,
        'flat_daily_laari' => 100,          // MVR 1.00/day
        'effective_from' => '2026-01-01',
        'effective_to' => null,
    ], $attributes));
}

// --- Resolution ---------------------------------------------------------------

it('fines an invoice issued inside a closed period under that period\'s rule', function () {
    $lease = periodLease();
    scheduleRule($lease, ['effective_from' => '2026-01-01', 'effective_to' => '2026-03-31']);

    $invoice = invoiceIssuedOn($lease, '2026-01-01', '2026-01-01');

    artisan('invoices:refresh-fines', ['--as-of' => '2026-01-20']);

    // 10 days late × MVR 1.00 = MVR 10.00.
    expect($invoice->refresh()->fine_laari)->toBe(1_000);
});

it('accrues no fine for an invoice issued in a gap between periods', function () {
    $lease = periodLease();
    scheduleRule($lease, ['effective_from' => '2026-01-01', 'effective_to' => '2026-03-31']);
    scheduleRule($lease, ['effective_from' => '2026-07-01', 'effective_to' => null]);

    // Issued in the April–June gap: the council granted a fine holiday.
    $invoice = invoiceIssuedOn($lease, '2026-05-01', '2026-05-01');

    artisan('invoices:refresh-fines', ['--as-of' => '2026-06-20']);

    expect($invoice->refresh()->fine_laari)->toBe(0)
        ->and($invoice->lineItems()->where('type', InvoiceLineType::Fine->value)->exists())->toBeFalse();
});

it('keeps fining an invoice under the rule of its own period after that period ends', function () {
    $lease = periodLease();
    scheduleRule($lease, [
        'effective_from' => '2026-01-01', 'effective_to' => '2026-03-31',
        'flat_daily_laari' => 100,
    ]);
    scheduleRule($lease, [
        'effective_from' => '2026-04-01', 'effective_to' => null,
        'flat_daily_laari' => 5_000,     // MVR 50/day — far harsher
    ]);

    $invoice = invoiceIssuedOn($lease, '2026-01-01', '2026-01-01');

    artisan('invoices:refresh-fines', ['--as-of' => '2026-05-01']);

    // 111 days late at the ORIGINAL MVR 1.00/day — the later period never
    // reaches back over an invoice it did not govern.
    expect($invoice->refresh()->fine_laari)->toBe(11_100);
});

it('picks the period whose window contains the issue date, not merely the latest', function () {
    $lease = periodLease();
    scheduleRule($lease, ['effective_from' => '2026-01-01', 'effective_to' => '2026-06-30', 'flat_daily_laari' => 100]);
    scheduleRule($lease, ['effective_from' => '2026-07-01', 'effective_to' => null, 'flat_daily_laari' => 900]);

    expect($lease->fineRuleOn(CarbonImmutable::parse('2026-02-15'))->flat_daily_laari)->toBe(100)
        ->and($lease->fineRuleOn(CarbonImmutable::parse('2026-08-15'))->flat_daily_laari)->toBe(900)
        ->and($lease->fineRuleOn(CarbonImmutable::parse('2025-12-31')))->toBeNull();
});

it('treats both window ends as inclusive days', function () {
    $lease = periodLease();
    $rule = scheduleRule($lease, ['effective_from' => '2026-02-01', 'effective_to' => '2026-02-28']);

    expect($lease->fineRuleOn(CarbonImmutable::parse('2026-02-01'))?->id)->toBe($rule->id)
        ->and($lease->fineRuleOn(CarbonImmutable::parse('2026-02-28'))?->id)->toBe($rule->id)
        ->and($lease->fineRuleOn(CarbonImmutable::parse('2026-01-31')))->toBeNull()
        ->and($lease->fineRuleOn(CarbonImmutable::parse('2026-03-01')))->toBeNull();
});

// --- Scheduling guards --------------------------------------------------------

it('supersedes an open-ended period by closing it the day before the new one starts', function () {
    $lease = periodLease();
    $first = scheduleRule($lease, ['effective_from' => '2026-01-01', 'effective_to' => null]);

    scheduleRule($lease, ['effective_from' => '2026-07-01', 'effective_to' => null]);

    expect($first->refresh()->effective_to?->toDateString())->toBe('2026-06-30');
});

it('refuses a period that overlaps a closed one', function () {
    $lease = periodLease();
    scheduleRule($lease, ['effective_from' => '2026-01-01', 'effective_to' => '2026-06-30']);

    scheduleRule($lease, ['effective_from' => '2026-05-01', 'effective_to' => '2026-09-30']);
})->throws(InvalidFineRuleException::class, '1 Jan 2026 – 30 Jun 2026');

it('refuses a period that starts before an existing one and would swallow it', function () {
    $lease = periodLease();
    scheduleRule($lease, ['effective_from' => '2026-06-01', 'effective_to' => null]);

    scheduleRule($lease, ['effective_from' => '2026-01-01', 'effective_to' => null]);
})->throws(InvalidFineRuleException::class);

it('refuses a period that starts on the same day as an existing one', function () {
    $lease = periodLease();
    scheduleRule($lease, ['effective_from' => '2026-01-01', 'effective_to' => null]);

    scheduleRule($lease, ['effective_from' => '2026-01-01', 'effective_to' => null]);
})->throws(InvalidFineRuleException::class);

it('refuses a period that ends before it starts', function () {
    scheduleRule(periodLease(), ['effective_from' => '2026-06-01', 'effective_to' => '2026-01-01']);
})->throws(InvalidFineRuleException::class, 'ends before it starts');

it('allows a closed period slotted into a gap between two others', function () {
    $lease = periodLease();
    scheduleRule($lease, ['effective_from' => '2026-01-01', 'effective_to' => '2026-03-31']);
    scheduleRule($lease, ['effective_from' => '2026-09-01', 'effective_to' => null]);

    $filler = scheduleRule($lease, ['effective_from' => '2026-05-01', 'effective_to' => '2026-06-30']);

    expect($filler->exists)->toBeTrue()
        ->and($lease->fineRuleOn(CarbonImmutable::parse('2026-05-15'))?->id)->toBe($filler->id)
        ->and($lease->fineRuleOn(CarbonImmutable::parse('2026-04-15')))->toBeNull();
});

it('closes an open period on a chosen date but never before it started', function () {
    $lease = periodLease();
    $rule = scheduleRule($lease, ['effective_from' => '2026-03-01', 'effective_to' => null]);
    $scheduler = app(FineRuleScheduler::class);

    $scheduler->close($rule, CarbonImmutable::parse('2026-08-31'));
    expect($rule->refresh()->effective_to?->toDateString())->toBe('2026-08-31');

    $scheduler->close($rule, CarbonImmutable::parse('2026-01-01'));
})->throws(InvalidFineRuleException::class);

/*
 | Editing and removing a period. The gate is NOT "has it started" — a period
 | that ran and governed nothing is a mistake worth erasing. The gate is
 | whether any invoice was raised inside its window: those invoices' fines are
 | computed from it nightly, so changing it would silently re-fine real money.
 */

it('removes a period nothing was ever invoiced under, started or not', function () {
    $lease = periodLease();
    $scheduler = app(FineRuleScheduler::class);

    $future = scheduleRule($lease, ['effective_from' => '2030-01-01', 'effective_to' => null]);
    $scheduler->remove($future);

    // Long since started and ended, but no invoice ever fell in it.
    $stale = scheduleRule($lease, ['effective_from' => '2020-01-01', 'effective_to' => '2021-12-31']);
    $scheduler->remove($stale);

    expect(FineRule::query()->whereKey($future->id)->exists())->toBeFalse()
        ->and(FineRule::query()->whereKey($stale->id)->exists())->toBeFalse();
});

it('refuses to remove a period that has already fined an invoice', function () {
    $lease = periodLease();
    $rule = scheduleRule($lease, ['effective_from' => '2026-01-01', 'effective_to' => '2026-03-31']);

    invoiceIssuedOn($lease, '2026-01-01', '2026-01-01');
    artisan('invoices:refresh-fines', ['--as-of' => '2026-01-20']);

    app(FineRuleScheduler::class)->remove($rule);
})->throws(InvalidFineRuleException::class, '1 invoice');

it('refuses to remove a period covering an invoice that is not yet late', function () {
    // The fine has not been computed yet, so fine_rule_id is still null — but
    // this period is what WILL fine it. Deleting it silently cancels that.
    $lease = periodLease();
    $rule = scheduleRule($lease, ['effective_from' => '2026-01-01', 'effective_to' => '2026-03-31']);

    $invoice = invoiceIssuedOn($lease, '2026-01-01', '2026-01-05');

    expect($invoice->fine_rule_id)->toBeNull()
        ->and($invoice->fine_laari)->toBe(0);

    app(FineRuleScheduler::class)->remove($rule);
})->throws(InvalidFineRuleException::class);

it('ignores invoices a later period took over when judging dependency', function () {
    // Legacy stacked rows: A is open-ended on paper but was superseded by B in
    // 2026, so a 2026 invoice depends on B, not A — A stays removable.
    $lease = periodLease();
    $a = FineRule::factory()->flatPerDay(100)->create(['lease_id' => $lease->id, 'effective_from' => '2024-01-01']);
    $b = FineRule::factory()->flatPerDay(900)->create(['lease_id' => $lease->id, 'effective_from' => '2026-01-01']);

    invoiceIssuedOn($lease, '2026-02-01', '2026-02-01');

    $scheduler = app(FineRuleScheduler::class);

    expect($scheduler->dependentInvoiceCount($a))->toBe(0)
        ->and($scheduler->dependentInvoiceCount($b))->toBe(1);

    $scheduler->remove($a);
    expect(FineRule::query()->whereKey($a->id)->exists())->toBeFalse();
});

it('edits a period nothing was invoiced under, in place', function () {
    $lease = periodLease();
    $rule = scheduleRule($lease, [
        'effective_from' => '2026-01-01', 'effective_to' => '2026-03-31', 'flat_daily_laari' => 100,
    ]);

    $updated = app(FineRuleScheduler::class)->update($rule, [
        'method' => 'percent_per_day',
        'base' => 'rent',
        'allowance_days' => 3,
        'flat_daily_laari' => null,
        'percent_daily_bps' => 50,
        'effective_from' => '2026-02-01',
        'effective_to' => '2026-04-30',
    ]);

    expect($updated->id)->toBe($rule->id)                      // same row, not a new one
        ->and($updated->method->value)->toBe('percent_per_day')
        ->and($updated->percent_daily_bps)->toBe(50)
        ->and($updated->flat_daily_laari)->toBeNull()
        ->and($updated->allowance_days)->toBe(3)
        ->and($updated->effective_from->toDateString())->toBe('2026-02-01')
        ->and($updated->effective_to->toDateString())->toBe('2026-04-30')
        ->and($lease->fineRules()->count())->toBe(1);
});

it('refuses to edit a period once an invoice has been raised under it', function () {
    $lease = periodLease();
    $rule = scheduleRule($lease, ['effective_from' => '2026-01-01', 'effective_to' => '2026-03-31']);

    invoiceIssuedOn($lease, '2026-01-01', '2026-01-05');

    app(FineRuleScheduler::class)->update($rule, [
        'method' => 'flat_per_day', 'base' => 'rent', 'allowance_days' => 0,
        'flat_daily_laari' => 9_999,
        'effective_from' => '2026-01-01', 'effective_to' => '2026-03-31',
    ]);
})->throws(InvalidFineRuleException::class, 'end it and start a new period');

it('refuses an edit that would overlap another period, ignoring itself', function () {
    $lease = periodLease();
    $first = scheduleRule($lease, ['effective_from' => '2026-01-01', 'effective_to' => '2026-03-31']);
    scheduleRule($lease, ['effective_from' => '2026-07-01', 'effective_to' => '2026-09-30']);

    $scheduler = app(FineRuleScheduler::class);

    // Re-saving its own window is fine — it does not conflict with itself.
    $scheduler->update($first, [
        'method' => 'flat_per_day', 'base' => 'rent', 'allowance_days' => 0, 'flat_daily_laari' => 100,
        'effective_from' => '2026-01-01', 'effective_to' => '2026-03-31',
    ]);

    // Stretching it into the second period is not.
    $scheduler->update($first, [
        'method' => 'flat_per_day', 'base' => 'rent', 'allowance_days' => 0, 'flat_daily_laari' => 100,
        'effective_from' => '2026-01-01', 'effective_to' => '2026-08-31',
    ]);
})->throws(InvalidFineRuleException::class, '1 Jul 2026 – 30 Sep 2026');

// --- Timeline & application history -------------------------------------------

it('lays the lease out as an ordered timeline of periods and no-fine gaps', function () {
    $lease = periodLease();
    scheduleRule($lease, ['effective_from' => '2026-01-01', 'effective_to' => '2026-03-31']);
    scheduleRule($lease, ['effective_from' => '2026-07-01', 'effective_to' => null]);

    $timeline = app(FineRuleScheduler::class)
        ->timeline($lease, CarbonImmutable::parse('2026-08-15'));

    expect(array_column($timeline, 'type'))->toBe(['rule', 'gap', 'rule'])
        ->and($timeline[0]['status'])->toBe('ended')
        ->and($timeline[1]['from']->toDateString())->toBe('2026-04-01')
        ->and($timeline[1]['to']->toDateString())->toBe('2026-06-30')
        ->and($timeline[2]['status'])->toBe('active')
        ->and($timeline[2]['to'])->toBeNull();
});

it('marks a period that has not started yet as scheduled', function () {
    $lease = periodLease();
    scheduleRule($lease, ['effective_from' => '2027-01-01', 'effective_to' => null]);

    $timeline = app(FineRuleScheduler::class)
        ->timeline($lease, CarbonImmutable::parse('2026-08-15'));

    expect(collect($timeline)->firstWhere('type', 'rule')['status'])->toBe('scheduled');
});

it('records on the invoice which period produced its fine', function () {
    $lease = periodLease();
    $rule = scheduleRule($lease, ['effective_from' => '2026-01-01', 'effective_to' => '2026-03-31']);

    $invoice = invoiceIssuedOn($lease, '2026-01-01', '2026-01-01');

    artisan('invoices:refresh-fines', ['--as-of' => '2026-01-20']);
    $invoice->refresh();

    $meta = $invoice->lineItems()->where('type', InvoiceLineType::Fine->value)->first()->meta;

    expect($invoice->fine_rule_id)->toBe($rule->id)
        ->and($meta['fine_rule_id'])->toBe($rule->id)
        ->and($meta['rule_period'])->toBe('1 Jan 2026 – 31 Mar 2026');
});

// --- Screen -------------------------------------------------------------------

it('creates a fine period from the lease screen and refuses an overlapping one', function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $lease = periodLease();

    $supervisor = User::factory()->create();
    $supervisor->assignRole('supervisor');
    actingAs($supervisor);

    Livewire::test(LeasesIndex::class)
        ->call('configureFine', $lease->id)
        ->set('fine_method', 'flat_per_day')
        ->set('fine_flat_amount', '1.00')
        ->set('fine_effective_from', '2026-01-01')
        ->set('fine_effective_to', '2026-06-30')
        ->call('saveFineRule')
        ->assertHasNoErrors()
        // An overlapping second period is refused with the conflict named.
        ->call('configureFine', $lease->id)
        ->set('fine_method', 'flat_per_day')
        ->set('fine_flat_amount', '2.00')
        ->set('fine_effective_from', '2026-04-01')
        ->set('fine_effective_to', '2026-12-31')
        ->call('saveFineRule')
        ->assertHasErrors('fine_effective_from');

    expect($lease->fineRules()->count())->toBe(1)
        ->and($lease->fineRules()->first()->effective_to->toDateString())->toBe('2026-06-30');
});

it('previews the fine a period would charge before it is saved', function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $lease = periodLease();

    $supervisor = User::factory()->create();
    $supervisor->assignRole('supervisor');
    actingAs($supervisor);

    Livewire::test(LeasesIndex::class)
        ->call('configureFine', $lease->id)
        ->set('fine_method', 'flat_per_day')
        ->set('fine_flat_amount', '1.00')
        ->set('fine_allowance_days', 0)
        // 10 days late on the worked example × MVR 1.00.
        ->assertSee('MVR 10.00');
});

it('shows legacy stacked open-ended rules as the periods they actually covered', function () {
    // Rows written by the pre-period form all left the end open; resolution has
    // always been "the latest start wins", and the timeline must say the same.
    $lease = periodLease();
    FineRule::factory()->flatPerDay(100)->create(['lease_id' => $lease->id, 'effective_from' => '2024-01-01']);
    FineRule::factory()->flatPerDay(900)->create(['lease_id' => $lease->id, 'effective_from' => '2026-01-01']);

    $timeline = app(FineRuleScheduler::class)
        ->timeline($lease, CarbonImmutable::parse('2026-08-15'));

    expect($timeline)->toHaveCount(2)
        ->and($timeline[0]['to']->toDateString())->toBe('2025-12-31')
        ->and($timeline[0]['status'])->toBe('ended')
        ->and($timeline[1]['to'])->toBeNull()
        ->and($timeline[1]['status'])->toBe('active')
        // and the resolver agrees with the picture
        ->and($lease->fineRuleOn(CarbonImmutable::parse('2025-06-01'))->flat_daily_laari)->toBe(100)
        ->and($lease->fineRuleOn(CarbonImmutable::parse('2026-08-15'))->flat_daily_laari)->toBe(900);
});

it('lists which period fined each invoice in the schedule history', function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $lease = periodLease();
    scheduleRule($lease, ['effective_from' => '2026-01-01', 'effective_to' => '2026-03-31']);

    $invoice = invoiceIssuedOn($lease, '2026-01-01', '2026-01-01');
    artisan('invoices:refresh-fines', ['--as-of' => '2026-01-20']);

    $supervisor = User::factory()->create();
    $supervisor->assignRole('supervisor');
    actingAs($supervisor);

    Livewire::test(LeasesIndex::class)
        ->call('configureFine', $lease->id)
        ->assertSee('Applied to')
        ->assertSee($invoice->refresh()->number)
        ->assertSee('1 Jan 2026 – 31 Mar 2026')   // the governing period
        ->assertSee('MVR 10.00')                   // the fine it charged
        ->assertSee('still accruing');
});

it('edits a period from the schedule screen and locks one that has invoices', function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $lease = periodLease();
    $free = scheduleRule($lease, ['effective_from' => '2027-01-01', 'effective_to' => '2027-06-30']);

    $supervisor = User::factory()->create();
    $supervisor->assignRole('supervisor');
    actingAs($supervisor);

    // Editable while nothing falls in it: the form opens prefilled and saves in place.
    Livewire::test(LeasesIndex::class)
        ->call('configureFine', $lease->id)
        ->call('editFinePeriod', $free->id)
        ->assertSet('fine_effective_from', '2027-01-01')
        ->assertSet('fine_effective_to', '2027-06-30')
        ->assertSet('fine_ongoing', false)
        ->assertSee('Edit period')
        ->set('fine_flat_amount', '7.50')
        ->set('fine_effective_to', '2027-09-30')
        ->call('saveFineRule')
        ->assertHasNoErrors();

    expect($lease->fineRules()->count())->toBe(1)
        ->and($free->refresh()->flat_daily_laari)->toBe(750)
        ->and($free->effective_to->toDateString())->toBe('2027-09-30');

    // Once an invoice falls inside it, the row locks and the service refuses.
    $covering = scheduleRule($lease, ['effective_from' => '2026-01-01', 'effective_to' => '2026-03-31']);
    invoiceIssuedOn($lease, '2026-01-01', '2026-01-05');

    Livewire::test(LeasesIndex::class)
        ->call('configureFine', $lease->id)
        ->assertSee('Locked — invoices are fined from it')
        ->call('removeFinePeriod', $covering->id);

    expect($covering->refresh()->exists)->toBeTrue();
});

/*
 | Which period governs an invoice is decided by the MONTH IT BILLS, not by the
 | day someone happened to type it in. Back-entering a December 2025 invoice in
 | August 2026 must still fine it under the rule the council had in December.
 */

it('fines a back-entered invoice under the period of the month it bills', function () {
    $lease = Lease::factory()->active()->flat(50_000)->create([
        'start_date' => '2024-01-15',
        'rent_start_date' => '2024-01-15',   // mid-month: Dec 2025 falls due 10 Jan 2026
        'expiry_date' => '2036-01-01',
        'due_day' => 10,
    ]);

    scheduleRule($lease, [
        'method' => 'percent_per_day', 'base' => 'rent', 'percent_daily_bps' => 50,
        'flat_daily_laari' => null,
        'effective_from' => '2024-01-01', 'effective_to' => '2026-04-30',
    ]);
    scheduleRule($lease, [
        'method' => 'tiered_monthly', 'base' => 'rent', 'flat_daily_laari' => null,
        'first_month_laari' => 10_000, 'subsequent_month_laari' => 5_000,
        'effective_from' => '2026-05-01', 'effective_to' => null,
    ]);

    // Billed for Dec 2025, due 10 Jan 2026 — but only entered in Aug 2026.
    $invoice = invoiceIssuedOn($lease, '2025-12-01', '2026-08-15');

    artisan('invoices:refresh-fines', ['--as-of' => '2026-08-15']);
    $invoice->refresh();

    // 217 days late × 0.5% of MVR 500 (250 laari/day) — the December rule,
    // NOT the tiered one that only began in May 2026.
    expect($invoice->fine_laari)->toBe(54_250)
        ->and($invoice->governingFineRule()->percent_daily_bps)->toBe(50);
});

it('anchors an advance invoice on the first month it covers', function () {
    $lease = periodLease();
    scheduleRule($lease, ['effective_from' => '2026-01-01', 'effective_to' => '2026-03-31', 'flat_daily_laari' => 100]);
    scheduleRule($lease, ['effective_from' => '2026-04-01', 'effective_to' => null, 'flat_daily_laari' => 5_000]);

    // Jan–Jun in one invoice: governed by January's rule, start to finish.
    $invoice = app(InvoiceGenerator::class)
        ->generateRange($lease, CarbonImmutable::parse('2026-01-01'), 6);

    expect($invoice->governingFineRule()->flat_daily_laari)->toBe(100);
});
