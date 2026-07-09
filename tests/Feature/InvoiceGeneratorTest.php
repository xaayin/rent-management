<?php

declare(strict_types=1);

use App\Enums\InvoiceLineType;
use App\Models\Invoice;
use App\Models\Lease;
use App\Services\Billing\InvoiceGenerator;
use Carbon\CarbonImmutable;

function generator(): InvoiceGenerator
{
    return app(InvoiceGenerator::class);
}

function activeLease(array $attributes = []): Lease
{
    return Lease::factory()->active()->create(array_merge([
        'rent_basis' => 'per_sqft',
        'rate_laari' => 53,
        'area_sqft' => 2000,
        'start_date' => '2026-01-01',
        'rent_start_date' => '2026-01-01',
        'duration_years' => 10,
        'expiry_date' => '2036-01-01',
        'grace_months' => 0,
        'due_day' => 10,
    ], $attributes));
}

it('generates a per-ft² rent invoice matching the PRD example', function () {
    $lease = activeLease();

    $invoice = generator()->generate($lease, CarbonImmutable::parse('2026-03-01'));

    expect($invoice->rent_laari)->toBe(106_000)
        ->and($invoice->charges_laari)->toBe(0)
        ->and($invoice->total_laari)->toBe(106_000)
        ->and($invoice->total()->format())->toBe('MVR 1,060.00')
        ->and($invoice->due_date->toDateString())->toBe('2026-03-10')
        ->and($invoice->period_start->toDateString())->toBe('2026-03-01')
        ->and($invoice->period_end->toDateString())->toBe('2026-03-31')
        ->and($invoice->number)->toMatch('/^2026\/\d{3}$/');
});

it('generates a flat rent invoice', function () {
    $lease = activeLease();
    $lease->update(['rent_basis' => 'flat', 'rate_laari' => null, 'area_sqft' => null, 'flat_amount_laari' => 50_000]);

    $invoice = generator()->generate($lease, CarbonImmutable::parse('2026-03-01'));

    expect($invoice->total()->format())->toBe('MVR 500.00');
});

it('suppresses invoices during the grace period (FR-CHG-03)', function () {
    // grace 6 months from 2026-01-01 -> first billable month is July 2026.
    $lease = activeLease(['grace_months' => 6]);

    expect(generator()->generate($lease, CarbonImmutable::parse('2026-06-01')))->toBeNull()
        ->and(generator()->generate($lease, CarbonImmutable::parse('2026-07-01')))->not->toBeNull();
});

it('does not invoice before the rent-start date', function () {
    $lease = activeLease(['rent_start_date' => '2026-06-01']);

    expect(generator()->generate($lease, CarbonImmutable::parse('2026-05-01')))->toBeNull()
        ->and(generator()->generate($lease, CarbonImmutable::parse('2026-06-01')))->not->toBeNull();
});

it('does not invoice on or after the expiry date (FR-INV-04)', function () {
    $lease = activeLease(['rent_start_date' => '2020-01-01', 'expiry_date' => '2026-06-01']);

    expect(generator()->generate($lease, CarbonImmutable::parse('2026-06-01')))->toBeNull()
        ->and(generator()->generate($lease, CarbonImmutable::parse('2026-05-01')))->not->toBeNull();
});

it('does not invoice a non-active lease', function () {
    $lease = Lease::factory()->create(['status' => 'draft', 'rent_start_date' => '2026-01-01', 'expiry_date' => '2036-01-01']);

    expect(generator()->generate($lease, CarbonImmutable::parse('2026-03-01')))->toBeNull();
});

it('never double-invoices a period (FR-INV-06)', function () {
    $lease = activeLease();
    $period = CarbonImmutable::parse('2026-03-01');

    $first = generator()->generate($lease, $period);
    $second = generator()->generate($lease, $period);

    expect($second->id)->toBe($first->id)
        ->and(Invoice::where('lease_id', $lease->id)->count())->toBe(1);
});

it('numbers invoices sequentially per year and resets each year (FR-INV-03)', function () {
    $a = activeLease();
    $b = activeLease();

    $a2026 = generator()->generate($a, CarbonImmutable::parse('2026-03-01'));
    $b2026 = generator()->generate($b, CarbonImmutable::parse('2026-03-01'));
    $a2027 = generator()->generate($a, CarbonImmutable::parse('2027-03-01'));

    expect($a2026->number)->toBe('2026/001')
        ->and($b2026->number)->toBe('2026/002')
        ->and($a2027->number)->toBe('2027/001');
});

it('adds a fixed annual CSR charge only in the CSR month (FR-CHG-04)', function () {
    // MVR 9,000/year = 900,000 laari, billed in March.
    $lease = activeLease()->fresh();
    $lease->update(['csr_type' => 'fixed_annual', 'csr_amount_laari' => 900_000, 'csr_month' => 3]);

    $february = generator()->generate($lease, CarbonImmutable::parse('2026-02-01'));
    $march = generator()->generate($lease, CarbonImmutable::parse('2026-03-01'));

    expect($february->charges_laari)->toBe(0)
        ->and($march->charges_laari)->toBe(900_000)
        ->and($march->total_laari)->toBe(106_000 + 900_000);
});

it('computes a percentage-of-revenue CSR charge', function () {
    // 1% (100 bps) of MVR 9,000 declared revenue (900,000 laari) = 9,000 laari = MVR 90.
    $lease = activeLease();
    $lease->update(['csr_type' => 'percent_revenue', 'csr_percent_bps' => 100, 'csr_declared_revenue_laari' => 900_000, 'csr_month' => 3]);

    $march = generator()->generate($lease, CarbonImmutable::parse('2026-03-01'));

    expect($march->charges_laari)->toBe(9_000);
});

it('itemises rent and CSR as separate line items (FR-INV-02)', function () {
    $lease = activeLease();
    $lease->update(['csr_type' => 'fixed_annual', 'csr_amount_laari' => 900_000, 'csr_month' => 3]);

    $invoice = generator()->generate($lease, CarbonImmutable::parse('2026-03-01'));
    $lines = $invoice->lineItems;

    expect($lines)->toHaveCount(2)
        ->and($lines[0]->type)->toBe(InvoiceLineType::Rent)
        ->and($lines[0]->meta['rate_laari'])->toBe(53)
        ->and($lines[0]->meta['area_sqft'])->toBe(2000)
        ->and($lines[1]->type)->toBe(InvoiceLineType::Csr)
        ->and($lines[1]->amount_laari)->toBe(900_000);
});
