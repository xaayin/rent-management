<?php

declare(strict_types=1);

use App\Enums\InvoiceLineType;
use App\Enums\InvoiceStatus;
use App\Livewire\Leases\Index as LeasesIndex;
use App\Models\FineRule;
use App\Models\Invoice;
use App\Models\Lease;
use App\Models\User;
use App\Services\Billing\InvoiceGenerator;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\artisan;

/**
 * A flat MVR 500/month lease invoiced for January 2026, due 2026-01-10.
 */
function overdueInvoiceFor(?FineRule $rule = null, array $leaseAttributes = []): Invoice
{
    $lease = Lease::factory()->active()->flat(50_000)->create(array_merge([
        'start_date' => '2026-01-01',
        'rent_start_date' => '2026-01-01',
        'expiry_date' => '2036-01-01',
        'due_day' => 10,
    ], $leaseAttributes));

    if ($rule !== null) {
        $rule->update(['lease_id' => $lease->id]);
    }

    return app(InvoiceGenerator::class)->generate($lease, CarbonImmutable::parse('2026-01-01'));
}

it('marks a past-due invoice Overdue and attaches the itemised fine (FR-FIN-11/12)', function () {
    // 0.5%/day of rent — the ABID ledger example. 10 days late × MVR 2.50 = MVR 25.
    $invoice = overdueInvoiceFor(FineRule::factory()->percentPerDay(50)->create(['effective_from' => '2020-01-01']));

    artisan('invoices:refresh-fines', ['--as-of' => '2026-01-20'])->assertSuccessful();
    $invoice->refresh();

    expect($invoice->status)->toBe(InvoiceStatus::Overdue)
        ->and($invoice->fine_laari)->toBe(2_500)
        ->and($invoice->total_laari)->toBe(52_500);

    $fineLine = $invoice->lineItems()->where('type', InvoiceLineType::Fine->value)->first();

    expect($fineLine)->not->toBeNull()
        ->and($fineLine->meta['method'])->toBe('percent_per_day')
        ->and($fineLine->meta['late_days'])->toBe(10)
        ->and($fineLine->meta['daily_laari'])->toBe(250)
        ->and($fineLine->meta['total_laari'])->toBe(2_500);
});

it('recomputes the fine each day without duplicating the fine line (FR-FIN-05)', function () {
    $invoice = overdueInvoiceFor(FineRule::factory()->percentPerDay(50)->create(['effective_from' => '2020-01-01']));

    artisan('invoices:refresh-fines', ['--as-of' => '2026-01-20']);
    artisan('invoices:refresh-fines', ['--as-of' => '2026-01-21']);
    $invoice->refresh();

    expect($invoice->fine_laari)->toBe(2_750) // 11 days × 250
        ->and($invoice->lineItems()->where('type', InvoiceLineType::Fine->value)->count())->toBe(1);
});

it('leaves invoices that are not yet due untouched', function () {
    $invoice = overdueInvoiceFor(FineRule::factory()->percentPerDay(50)->create(['effective_from' => '2020-01-01']));

    artisan('invoices:refresh-fines', ['--as-of' => '2026-01-10']); // on the due date, not past it
    $invoice->refresh();

    expect($invoice->status)->toBe(InvoiceStatus::Issued)
        ->and($invoice->fine_laari)->toBe(0);
});

it('marks Overdue but accrues nothing when the lease has no fine rule', function () {
    $invoice = overdueInvoiceFor();

    artisan('invoices:refresh-fines', ['--as-of' => '2026-02-01']);
    $invoice->refresh();

    expect($invoice->status)->toBe(InvoiceStatus::Overdue)
        ->and($invoice->fine_laari)->toBe(0)
        ->and($invoice->lineItems()->where('type', InvoiceLineType::Fine->value)->exists())->toBeFalse();
});

it('keeps each lease\'s rule independent — changing one does not affect others', function () {
    $percent = overdueInvoiceFor(FineRule::factory()->percentPerDay(50)->create(['effective_from' => '2020-01-01']));
    $tiered = overdueInvoiceFor(FineRule::factory()->tiered(10_000, 5_000)->create(['effective_from' => '2020-01-01']));

    artisan('invoices:refresh-fines', ['--as-of' => '2026-03-05']);

    // 54 late days × 250 = 13,500 vs tiered 2 overdue months = 15,000.
    expect($percent->refresh()->fine_laari)->toBe(13_500)
        ->and($tiered->refresh()->fine_laari)->toBe(15_000);

    // Switching the first lease to flat MVR 1.00/day (effective before the
    // month these invoices bill) re-fines only that lease — the other is
    // untouched (acceptance §13.1.31).
    FineRule::factory()->flatPerDay(100)->create([
        'lease_id' => $percent->lease_id,
        'effective_from' => '2025-12-01',
    ]);

    artisan('invoices:refresh-fines', ['--as-of' => '2026-03-05']);

    expect($percent->refresh()->fine_laari)->toBe(5_400)   // 54 days × 100 laari
        ->and($tiered->refresh()->fine_laari)->toBe(15_000);
});

it('applies the rule in force for the month the invoice bills (FR-FIN-09)', function () {
    $invoice = overdueInvoiceFor(FineRule::factory()->flatPerDay(100)->create(['effective_from' => '2020-01-01']));

    // Entered long after the fact, then a later, much harsher rule is added.
    $invoice->forceFill(['created_at' => '2026-08-15 00:00:00'])->saveQuietly();

    FineRule::factory()->flatPerDay(1_000)->create([
        'lease_id' => $invoice->lease_id,
        'effective_from' => '2026-02-01',
    ]);

    artisan('invoices:refresh-fines', ['--as-of' => '2026-03-01']);

    // 50 late days × the ORIGINAL 100 laari/day = 5,000 — not 50,000.
    expect($invoice->refresh()->fine_laari)->toBe(5_000);
});

/*
 | Fine-rule configuration screen & permissions (§6.1 "Configure fine rules").
 */

it('lets a supervisor configure a lease fine rule but blocks a land officer', function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    $lease = Lease::factory()->active()->create();

    $supervisor = User::factory()->create();
    $supervisor->assignRole('supervisor');
    $landOfficer = User::factory()->create();
    $landOfficer->assignRole('land_officer');

    expect($supervisor->can('configureFineRule', $lease))->toBeTrue()
        ->and($landOfficer->can('configureFineRule', $lease))->toBeFalse();

    actingAs($supervisor);
    Livewire::test(LeasesIndex::class)
        ->call('configureFine', $lease->id)
        ->set('fine_method', 'percent_per_day')
        ->set('fine_base', 'rent')
        ->set('fine_percent', '0.5')
        ->set('fine_effective_from', '2026-07-10')
        ->call('saveFineRule')
        ->assertHasNoErrors();

    $rule = $lease->fineRules()->first();

    expect($rule)->not->toBeNull()
        ->and($rule->percent_daily_bps)->toBe(50)
        ->and($rule->method->value)->toBe('percent_per_day');

    actingAs($landOfficer);
    Livewire::test(LeasesIndex::class)
        ->call('configureFine', $lease->id)
        ->assertForbidden();
});
