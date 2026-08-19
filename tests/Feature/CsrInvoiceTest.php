<?php

declare(strict_types=1);

use App\Enums\InvoiceKind;
use App\Enums\InvoiceLineType;
use App\Enums\InvoiceStatus;
use App\Enums\PaymentMethod;
use App\Exceptions\InvalidInvoiceRangeException;
use App\Jobs\GenerateInvoices;
use App\Livewire\Invoices\Index as InvoicesIndex;
use App\Livewire\Leases\Index;
use App\Models\FineRule;
use App\Models\Invoice;
use App\Models\Lease;
use App\Models\User;
use App\Services\Billing\InvoiceCanceller;
use App\Services\Billing\InvoiceGenerator;
use App\Services\Billing\PaymentRecorder;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\artisan;

/**
 * CSR billed as its OWN annual invoice (lease setting `csr_billing=separate`)
 * instead of riding the csr-month rent invoice — and a CSR invoice never
 * accrues a fine, as a stated property of its kind, not an accident of a zero
 * rent base.
 */

/** Flat MVR 500/month, fixed MVR 1,200/year CSR billed in March, due day 10. */
function csrLease(string $billing = 'separate'): Lease
{
    return Lease::factory()->active()->flat(50_000)->create([
        'start_date' => '2026-01-01',
        'rent_start_date' => '2026-01-01',
        'expiry_date' => '2036-01-01',
        'due_day' => 10,
        'csr_type' => 'fixed_annual',
        'csr_amount_laari' => 120_000,
        'csr_month' => 3,
        'csr_billing' => $billing,
    ]);
}

function csrGenerator(): InvoiceGenerator
{
    return app(InvoiceGenerator::class);
}

// --- Splitting the documents ---------------------------------------------------

it('keeps CSR off the monthly rent invoice when the lease bills it separately', function () {
    $lease = csrLease();

    $march = csrGenerator()->generate($lease, CarbonImmutable::parse('2026-03-01'));

    expect($march->kind)->toBe(InvoiceKind::Rent)
        ->and($march->rent_laari)->toBe(50_000)
        ->and($march->charges_laari)->toBe(0)     // no CSR line rides along
        ->and($march->lineItems()->where('type', InvoiceLineType::Csr->value)->exists())->toBeFalse();
});

it('still bills CSR on the rent invoice for a with_rent lease', function () {
    $lease = csrLease('with_rent');

    $march = csrGenerator()->generate($lease, CarbonImmutable::parse('2026-03-01'));

    expect($march->charges_laari)->toBe(120_000)
        ->and($march->lineItems()->where('type', InvoiceLineType::Csr->value)->exists())->toBeTrue();
});

it('raises the annual CSR invoice as its own document', function () {
    $lease = csrLease();

    $invoice = csrGenerator()->generateCsr($lease, 2026);

    expect($invoice->kind)->toBe(InvoiceKind::Csr)
        ->and($invoice->rent_laari)->toBe(0)
        ->and($invoice->charges_laari)->toBe(120_000)
        ->and($invoice->total_laari)->toBe(120_000)
        ->and($invoice->period_start->toDateString())->toBe('2026-03-01')
        ->and($invoice->periodLabel())->toBe('CSR 2026')
        // due-date anchoring is the same council rule as rent
        ->and($invoice->due_date->toDateString())->toBe('2026-03-10')
        ->and($invoice->lineItems()->sole()->type)->toBe(InvoiceLineType::Csr);
});

it('raises exactly one live CSR invoice per lease per year', function () {
    $lease = csrLease();

    $first = csrGenerator()->generateCsr($lease, 2026);
    $again = csrGenerator()->generateCsr($lease, 2026);

    expect($again->id)->toBe($first->id)
        ->and(Invoice::where('lease_id', $lease->id)->where('kind', InvoiceKind::Csr->value)->count())->toBe(1);
});

it('frees the year again when a CSR invoice is voided', function () {
    $lease = csrLease();

    $wrong = csrGenerator()->generateCsr($lease, 2026);
    app(InvoiceCanceller::class)->cancel($wrong, 'Wrong amount agreed.');

    $corrected = csrGenerator()->generateCsr($lease, 2026);

    expect($corrected->id)->not->toBe($wrong->id)
        ->and($corrected->status)->toBe(InvoiceStatus::Issued);
});

it('refuses a separate CSR invoice on a with_rent lease — it would double-bill', function () {
    csrGenerator()->generateCsr(csrLease('with_rent'), 2026);
})->throws(InvalidInvoiceRangeException::class, 'billed with the rent');

it('refuses a CSR invoice when the lease has no CSR charge', function () {
    $lease = Lease::factory()->active()->flat(50_000)->create([
        'start_date' => '2026-01-01', 'rent_start_date' => '2026-01-01',
        'expiry_date' => '2036-01-01', 'csr_type' => 'none', 'csr_billing' => 'separate',
    ]);

    csrGenerator()->generateCsr($lease, 2026);
})->throws(InvalidInvoiceRangeException::class, 'no CSR');

it('does not let a CSR invoice block the same month\'s rent invoice, or vice versa', function () {
    $lease = csrLease();

    $csr = csrGenerator()->generateCsr($lease, 2026);
    $rent = csrGenerator()->generate($lease, CarbonImmutable::parse('2026-03-01'));

    expect($rent)->not->toBeNull()
        ->and($rent->id)->not->toBe($csr->id)
        ->and($rent->kind)->toBe(InvoiceKind::Rent);
});

it('excludes CSR from an advance range on a separate lease', function () {
    $lease = csrLease();

    // Jan–Jun 2026 covers March, but CSR bills as its own document now.
    $advance = csrGenerator()->generateRange($lease, CarbonImmutable::parse('2026-01-01'), 6);

    expect($advance->rent_laari)->toBe(300_000)
        ->and($advance->charges_laari)->toBe(0)
        ->and($advance->lineItems()->where('type', InvoiceLineType::Csr->value)->exists())->toBeFalse();
});

// --- The no-fine property -------------------------------------------------------

it('never fines a CSR invoice, even under a rent_plus_charges rule', function () {
    $lease = csrLease();
    FineRule::factory()->percentPerDay(50)->baseRentPlusCharges()->create([
        'lease_id' => $lease->id, 'effective_from' => '2020-01-01',
    ]);

    $invoice = csrGenerator()->generateCsr($lease, 2026);

    artisan('invoices:refresh-fines', ['--as-of' => '2026-06-01'])->assertSuccessful();
    $invoice->refresh();

    // Overdue yes — it IS late and reminders should chase it — but fined never.
    expect($invoice->status)->toBe(InvoiceStatus::Overdue)
        ->and($invoice->fine_laari)->toBe(0)
        ->and($invoice->lineItems()->where('type', InvoiceLineType::Fine->value)->exists())->toBeFalse();
});

it('keeps fining the rent invoice of the same lease as before', function () {
    $lease = csrLease();
    FineRule::factory()->flatPerDay(100)->create([
        'lease_id' => $lease->id, 'effective_from' => '2020-01-01',
    ]);

    $rent = csrGenerator()->generate($lease, CarbonImmutable::parse('2026-03-01'));
    csrGenerator()->generateCsr($lease, 2026);

    artisan('invoices:refresh-fines', ['--as-of' => '2026-03-20']);

    // Rent due 10 Mar, 10 days late × MVR 1.00.
    expect($rent->refresh()->fine_laari)->toBe(1_000);
});

// --- Money flows work unchanged -------------------------------------------------

it('takes a payment against a CSR invoice through the normal path', function () {
    $lease = csrLease();
    $invoice = csrGenerator()->generateCsr($lease, 2026);

    app(PaymentRecorder::class)->record(
        $invoice, Money::fromLaari(120_000), CarbonImmutable::parse('2026-03-05'), PaymentMethod::BankTransfer,
    );

    expect($invoice->refresh()->status)->toBe(InvoiceStatus::Paid);
});

// --- The screen -----------------------------------------------------------------

it('creates an annual CSR invoice from the invoices screen', function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $lease = csrLease();

    $supervisor = User::factory()->create();
    $supervisor->assignRole('supervisor');
    actingAs($supervisor);

    Livewire::test(InvoicesIndex::class)
        ->call('openCreateInvoice', $lease->id)
        ->set('inv_kind', 'csr')
        ->set('inv_csr_year', 2026)
        ->call('createInvoice')
        ->assertHasNoErrors();

    $invoice = Invoice::where('lease_id', $lease->id)->where('kind', InvoiceKind::Csr->value)->sole();

    expect($invoice->charges_laari)->toBe(120_000);
});

it('auto-raises the annual CSR invoice when the monthly run hits the CSR month', function () {
    $lease = csrLease();   // CSR month = March

    (new GenerateInvoices('2026-02'))->handle(csrGenerator());
    expect(Invoice::where('kind', InvoiceKind::Csr->value)->count())->toBe(0);

    (new GenerateInvoices('2026-03'))->handle(csrGenerator());
    (new GenerateInvoices('2026-03'))->handle(csrGenerator());   // idempotent re-run

    expect(Invoice::where('kind', InvoiceKind::Csr->value)->count())->toBe(1)
        ->and(Invoice::where('kind', InvoiceKind::Rent->value)->count())->toBe(2);
});

it('persists the CSR billing choice from the lease form', function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $lease = csrLease('with_rent');

    $supervisor = User::factory()->create();
    $supervisor->assignRole('supervisor');
    actingAs($supervisor);

    Livewire::test(Index::class)
        ->call('edit', $lease->id)
        ->assertSet('csr_billing', 'with_rent')
        ->set('csr_billing', 'separate')
        ->call('save')
        ->assertHasNoErrors();

    expect($lease->refresh()->billsCsrSeparately())->toBeTrue();
});
