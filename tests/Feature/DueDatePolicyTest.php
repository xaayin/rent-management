<?php

declare(strict_types=1);

use App\Enums\DueDateAnchor;
use App\Livewire\Leases\Index;
use App\Models\Invoice;
use App\Models\Lease;
use App\Models\User;
use App\Services\Billing\DueDateCalculator;
use App\Services\Billing\InvoiceGenerator;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;

/**
 * The council's due-date rule (configurable, config/billing.php):
 *
 *   start_day_based (the rule in force) — a lease whose period starts ON the
 *   1st is due the {due_day}th of THAT month; one starting mid-month is due
 *   the {due_day}th of the NEXT month (matching the paper ledgers, where the
 *   25 Apr–25 May period fell due on 10 May).
 */
function dueDateLease(string $rentStart, int $dueDay = 10, int $graceMonths = 0): Lease
{
    return Lease::factory()->active()->flat(50_000)->create([
        'start_date' => $rentStart,
        'rent_start_date' => $rentStart,
        'expiry_date' => CarbonImmutable::parse($rentStart)->addYears(10)->toDateString(),
        'due_day' => $dueDay,
        'grace_months' => $graceMonths,
    ]);
}

function invoiceFor(Lease $lease, string $month): Invoice
{
    return app(InvoiceGenerator::class)->generate($lease, CarbonImmutable::parse($month));
}

it('bills a lease starting ON the 1st as due the 10th of that same month', function () {
    $lease = dueDateLease('2026-05-01');

    expect(invoiceFor($lease, '2026-05-01')->due_date->toDateString())->toBe('2026-05-10')
        ->and(invoiceFor($lease, '2026-06-01')->due_date->toDateString())->toBe('2026-06-10');
});

it('bills a mid-month lease as due the 10th of the FOLLOWING month', function () {
    // The ABID ledger case: period anchored on the 25th, due on the 10th after.
    $lease = dueDateLease('2026-04-25');

    expect(invoiceFor($lease, '2026-04-01')->due_date->toDateString())->toBe('2026-05-10')
        ->and(invoiceFor($lease, '2026-05-01')->due_date->toDateString())->toBe('2026-06-10');
});

it('treats a lease starting on the 2nd as mid-month — only the 1st counts', function () {
    $lease = dueDateLease('2026-05-02');

    expect(invoiceFor($lease, '2026-05-01')->due_date->toDateString())->toBe('2026-06-10');
});

it('respects the per-lease due day within the anchored month', function () {
    $lease = dueDateLease('2026-04-25', dueDay: 15);

    expect(invoiceFor($lease, '2026-04-01')->due_date->toDateString())->toBe('2026-05-15');
});

it('clamps the due day to the length of the month it lands in', function () {
    // Mid-month lease, January period → due month is February; day 30 → 28.
    $lease = dueDateLease('2026-01-20', dueDay: 30);

    expect(invoiceFor($lease, '2026-01-01')->due_date->toDateString())->toBe('2026-02-28');
});

it('anchors on the day rent actually starts, grace months included', function () {
    // Rent start 1 Feb + 3 months grace → first billable day 1 May, day 1 → same-month due.
    $lease = dueDateLease('2026-02-01', graceMonths: 3);

    expect(invoiceFor($lease, '2026-05-01')->due_date->toDateString())->toBe('2026-05-10');
});

it('applies the rule to an advance invoice from its first covered month', function () {
    $lease = dueDateLease('2026-04-25');

    $invoice = app(InvoiceGenerator::class)->generateRange($lease, CarbonImmutable::parse('2026-04-01'), 6);

    expect($invoice->period_months)->toBe(6)
        ->and($invoice->due_date->toDateString())->toBe('2026-05-10');
});

/*
|--------------------------------------------------------------------------
| The rule is a configuration, not a constant
|--------------------------------------------------------------------------
*/

it('supports the legacy same-month anchor via config', function () {
    config()->set('billing.due_date_anchor', DueDateAnchor::SameMonth->value);

    $lease = dueDateLease('2026-04-25');

    expect(invoiceFor($lease, '2026-04-01')->due_date->toDateString())->toBe('2026-04-10');
});

it('supports an always-next-month anchor via config', function () {
    config()->set('billing.due_date_anchor', DueDateAnchor::NextMonth->value);

    $lease = dueDateLease('2026-05-01');

    expect(invoiceFor($lease, '2026-05-01')->due_date->toDateString())->toBe('2026-06-10');
});

it('falls back to the start-day rule on a malformed config value', function () {
    config()->set('billing.due_date_anchor', 'nonsense');

    $lease = dueDateLease('2026-04-25');

    expect(invoiceFor($lease, '2026-04-01')->due_date->toDateString())->toBe('2026-05-10');
});

it('exposes one calculator both the generator and previews share', function () {
    $lease = dueDateLease('2026-04-25');

    $calculated = app(DueDateCalculator::class)->for($lease, CarbonImmutable::parse('2026-04-01'));

    expect($calculated->toDateString())
        ->toBe(invoiceFor($lease, '2026-04-01')->due_date->toDateString());
});

// --- Lease-form hint ---------------------------------------------------------

it('previews the due date in the lease form as the user fills it in', function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $officer = User::factory()->create();
    $officer->assignRole('land_officer');
    Pest\Laravel\actingAs($officer);

    // Mid-month start → hint promises the following month.
    Livewire\Livewire::test(Index::class)
        ->call('create')
        ->set('rent_start_date', '2026-04-25')
        ->set('due_day', 10)
        ->assertSee('First invoice: April 2026 · due 10 May 2026')
        ->assertSee('falls due the following month')
        // Starting on the 1st → same month.
        ->set('rent_start_date', '2026-05-01')
        ->assertSee('First invoice: May 2026 · due 10 May 2026')
        ->assertSee('due within their own month')
        // Grace shifts the first billable month before anchoring.
        ->set('rent_start_date', '2026-04-25')
        ->set('grace_months', 2)
        ->assertSee('First invoice: June 2026 · due 10 July 2026');
});

it('shows no hint while the form is incomplete', function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $officer = User::factory()->create();
    $officer->assignRole('land_officer');
    Pest\Laravel\actingAs($officer);

    Livewire\Livewire::test(Index::class)
        ->call('create')
        ->set('rent_start_date', '')
        ->assertDontSee('First invoice:');
});
