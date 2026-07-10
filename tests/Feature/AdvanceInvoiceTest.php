<?php

declare(strict_types=1);

use App\Enums\InvoiceStatus;
use App\Enums\PaymentMethod;
use App\Exceptions\InvalidInvoiceRangeException;
use App\Jobs\GenerateInvoices;
use App\Livewire\Invoices\Index;
use App\Models\Invoice;
use App\Models\Lease;
use App\Models\User;
use App\Services\Billing\InvoiceGenerator;
use App\Services\Billing\PaymentRecorder;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Livewire\Livewire;

function advanceGenerator(): InvoiceGenerator
{
    return app(InvoiceGenerator::class);
}

/**
 * The PRD per-ft² example lease: MVR 1,060/month, Jan 2026 → Jan 2036.
 */
function advanceLease(array $attributes = []): Lease
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

it('creates a single invoice covering several months with correct money', function () {
    $invoice = advanceGenerator()->generateRange(advanceLease(), CarbonImmutable::parse('2026-03-01'), 6);

    // 6 × MVR 1,060 = MVR 6,360, one document due on the first month's due day.
    expect($invoice->rent_laari)->toBe(636_000)
        ->and($invoice->total()->format())->toBe('MVR 6,360.00')
        ->and($invoice->period_months)->toBe(6)
        ->and($invoice->period_start->toDateString())->toBe('2026-03-01')
        ->and($invoice->period_end->toDateString())->toBe('2026-08-31')
        ->and($invoice->due_date->toDateString())->toBe('2026-03-10')
        ->and($invoice->periodLabel())->toBe('Mar 2026 – Aug 2026')
        ->and($invoice->lineItems->first()->description)->toContain('6 months × MVR 1,060.00');
});

it('bills the annual CSR once per occurrence inside the range', function () {
    // Fixed MVR 9,000 CSR each January; a 24-month range from Mar 2026 covers
    // Jan 2027 and Jan 2028 → two CSR lines.
    $lease = advanceLease();
    $lease->update(['csr_type' => 'fixed_annual', 'csr_amount_laari' => 900_000, 'csr_month' => 1]);

    $invoice = advanceGenerator()->generateRange($lease, CarbonImmutable::parse('2026-03-01'), 24);

    expect($invoice->charges_laari)->toBe(1_800_000)
        ->and($invoice->total_laari)->toBe(24 * 106_000 + 1_800_000)
        ->and($invoice->lineItems()->where('type', 'csr')->count())->toBe(2)
        ->and($invoice->lineItems()->where('type', 'csr')->pluck('description')->implode(' '))
        ->toContain('2027')->toContain('2028');
});

it('can bill the entire remaining lease term', function () {
    $lease = advanceLease();
    $from = CarbonImmutable::parse('2026-02-01');

    // Last billable month starts before expiry (Dec 2035) → 119 months.
    $months = (int) $from->diffInMonths(CarbonImmutable::parse($lease->expiry_date)->startOfMonth());

    $invoice = advanceGenerator()->generateRange($lease, $from, $months);

    expect($months)->toBe(119)
        ->and($invoice->period_end->toDateString())->toBe('2035-12-31')
        ->and($invoice->rent_laari)->toBe(119 * 106_000);
});

it('rejects ranges that overlap existing invoices, naming them', function () {
    $lease = advanceLease();
    $january = advanceGenerator()->generate($lease, CarbonImmutable::parse('2026-01-01'));

    expect(fn () => advanceGenerator()->generateRange($lease, CarbonImmutable::parse('2026-01-01'), 3))
        ->toThrow(InvalidInvoiceRangeException::class, $january->number);
});

it('rejects ranges outside the billable window', function () {
    $graceLease = advanceLease(['grace_months' => 6]); // rent starts Jul 2026

    expect(fn () => advanceGenerator()->generateRange($graceLease, CarbonImmutable::parse('2026-05-01'), 3))
        ->toThrow(InvalidInvoiceRangeException::class, 'grace');

    expect(fn () => advanceGenerator()->generateRange(advanceLease(), CarbonImmutable::parse('2035-11-01'), 6))
        ->toThrow(InvalidInvoiceRangeException::class, 'expiry');

    expect(fn () => advanceGenerator()->generateRange(advanceLease(), CarbonImmutable::parse('2026-02-01'), 0))
        ->toThrow(InvalidInvoiceRangeException::class);
});

it('stops the monthly run double-billing months covered by an advance invoice (FR-INV-06)', function () {
    $lease = advanceLease();
    advanceGenerator()->generateRange($lease, CarbonImmutable::parse('2026-03-01'), 6); // Mar–Aug

    GenerateInvoices::dispatchSync('2026-05'); // inside the range → skip
    GenerateInvoices::dispatchSync('2026-09'); // outside → normal monthly invoice

    expect(Invoice::where('lease_id', $lease->id)->count())->toBe(2)
        ->and(Invoice::where('lease_id', $lease->id)->where('period_month', 9)->exists())->toBeTrue();
});

it('creates an advance invoice through the modal with quick-picks and preview', function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    $lease = advanceLease();
    advanceGenerator()->generate($lease, CarbonImmutable::parse('2026-01-01')); // Jan billed

    $finance = User::factory()->create();
    $finance->assignRole('finance_officer');
    \Pest\Laravel\actingAs($finance);

    $component = Livewire::test(Index::class)
        ->call('openCreateInvoice', $lease->id);

    // Start month suggested as the first unbilled month (Feb 2026).
    expect($component->get('inv_start'))->toBe('2026-02');

    $component->call('setMonths', 12)
        ->assertSee('12 × MVR 1,060.00')
        ->assertSee('MVR 12,720.00')          // live preview total
        ->call('createInvoice')
        ->assertHasNoErrors();

    $invoice = Invoice::where('lease_id', $lease->id)->where('period_months', 12)->first();

    expect($invoice)->not->toBeNull()
        ->and($invoice->total()->format())->toBe('MVR 12,720.00')
        ->and($invoice->period_start->toDateString())->toBe('2026-02-01');

    // Overlapping attempt surfaces the conflict in the preview/create.
    $component = Livewire::test(Index::class)
        ->call('openCreateInvoice', $lease->id)
        ->set('inv_start', '2026-06')
        ->set('inv_months', 3)
        ->assertSee('overlaps existing invoice')
        ->call('createInvoice')
        ->assertHasErrors(['inv_months']);
});

it('computes the until-lease-end quick pick', function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $lease = advanceLease();

    $finance = User::factory()->create();
    $finance->assignRole('finance_officer');
    \Pest\Laravel\actingAs($finance);

    Livewire::test(Index::class)
        ->call('openCreateInvoice', $lease->id)
        ->set('inv_start', '2026-02')
        ->call('setMonthsUntilLeaseEnd')
        ->assertSet('inv_months', 119);        // Feb 2026 … Dec 2035
});

it('settles an advance invoice with a single payment', function () {
    $lease = advanceLease();
    $invoice = advanceGenerator()->generateRange($lease, CarbonImmutable::parse('2026-03-01'), 12);

    app(PaymentRecorder::class)->record(
        $invoice,
        Money::fromLaari(12 * 106_000),
        CarbonImmutable::parse('2026-03-05'),
        PaymentMethod::BankTransfer,
    );

    expect($invoice->fresh()->status)->toBe(InvoiceStatus::Paid)
        ->and($invoice->fresh()->outstandingTotalLaari())->toBe(0);
});
