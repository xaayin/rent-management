<?php

declare(strict_types=1);

use App\Enums\InvoiceStatus;
use App\Enums\PaymentMethod;
use App\Exceptions\InvalidPaymentException;
use App\Models\FineRule;
use App\Models\Invoice;
use App\Models\Lease;
use App\Models\Payment;
use App\Models\Receipt;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Billing\InvoiceGenerator;
use App\Services\Billing\PaymentRecorder;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Artisan;
use Spatie\LaravelPdf\Facades\Pdf;

use function Pest\Laravel\actingAs;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
});

function bulkRecorder(): PaymentRecorder
{
    return app(PaymentRecorder::class);
}

/**
 * A tenant with `$count` monthly MVR 500 invoices, oldest first — Jan, Feb, …
 * of 2026, each due on the 10th. Returns [tenant, invoices].
 *
 * @return array{0: Tenant, 1: Collection<int, Invoice>}
 */
function tenantWithInvoices(int $count = 3, ?FineRule $rule = null): array
{
    $tenant = Tenant::factory()->create();
    $lease = Lease::factory()->active()->flat(50_000)->create([
        'tenant_id' => $tenant->id,
        'start_date' => '2026-01-01',
        'rent_start_date' => '2026-01-01',
        'expiry_date' => '2036-01-01',
        'due_day' => 10,
    ]);

    if ($rule !== null) {
        $rule->update(['lease_id' => $lease->id]);
    }

    $invoices = collect(range(0, $count - 1))->map(
        fn (int $i) => app(InvoiceGenerator::class)->generate(
            $lease,
            CarbonImmutable::parse('2026-01-01')->addMonths($i),
        ),
    );

    return [$tenant, $invoices];
}

it('settles several invoices from one payment, oldest first', function () {
    [$tenant, $invoices] = tenantWithInvoices(3);

    // MVR 1,500 clears all three MVR 500 invoices exactly.
    $receipt = bulkRecorder()->recordForTenant(
        $tenant,
        Money::fromRufiyaa('1500.00'),
        CarbonImmutable::parse('2026-01-05'),
        PaymentMethod::Cash,
    );

    expect($receipt->payments)->toHaveCount(3)
        ->and($receipt->total()->format())->toBe('MVR 1,500.00');

    $invoices->each(fn (Invoice $i) => expect($i->fresh()->status)->toBe(InvoiceStatus::Paid));
});

it('issues ONE receipt number for the whole handover', function () {
    [$tenant] = tenantWithInvoices(3);

    $receipt = bulkRecorder()->recordForTenant(
        $tenant,
        Money::fromRufiyaa('1500.00'),
        CarbonImmutable::parse('2026-01-05'),
        PaymentMethod::Cash,
    );

    // The whole point: one handover, one number — not one per invoice.
    expect($receipt->number)->toBe('2026/001')
        ->and($receipt->payments->pluck('receipt_number')->unique()->all())->toBe(['2026/001'])
        ->and(Receipt::count())->toBe(1)
        ->and(Payment::count())->toBe(3);
});

it('stops part-way when the money runs out, leaving later invoices untouched', function () {
    [$tenant, $invoices] = tenantWithInvoices(3);

    // MVR 750 covers January in full and half of February.
    $receipt = bulkRecorder()->recordForTenant(
        $tenant,
        Money::fromRufiyaa('750.00'),
        CarbonImmutable::parse('2026-01-05'),
        PaymentMethod::Cash,
    );

    [$jan, $feb, $mar] = [$invoices[0]->fresh(), $invoices[1]->fresh(), $invoices[2]->fresh()];

    expect($receipt->payments)->toHaveCount(2)          // nothing written for March
        ->and($jan->status)->toBe(InvoiceStatus::Paid)
        ->and($feb->status)->toBe(InvoiceStatus::PartlyPaid)
        ->and($feb->outstandingTotalLaari())->toBe(25_000)
        ->and($mar->status)->not->toBe(InvoiceStatus::Paid)
        ->and($mar->payments()->count())->toBe(0);
});

it('rejects an overpayment rather than banking a credit (§5.4)', function () {
    [$tenant] = tenantWithInvoices(2);

    // MVR 1,500 against MVR 1,000 owed — the PRD forbids credit balances.
    expect(fn () => bulkRecorder()->recordForTenant(
        $tenant,
        Money::fromRufiyaa('1500.00'),
        CarbonImmutable::parse('2026-01-05'),
        PaymentMethod::Cash,
    ))->toThrow(InvalidPaymentException::class)
        ->and(Receipt::count())->toBe(0)
        ->and(Payment::count())->toBe(0);   // nothing half-written
});

it('rejects a non-positive amount', function () {
    [$tenant] = tenantWithInvoices(2);

    expect(fn () => bulkRecorder()->recordForTenant(
        $tenant, Money::fromLaari(0), CarbonImmutable::parse('2026-01-05'), PaymentMethod::Cash
    ))->toThrow(InvalidPaymentException::class);
});

it('only touches the invoices the clerk selected', function () {
    [$tenant, $invoices] = tenantWithInvoices(3);

    // The tenant disputes January, so the clerk excludes it.
    $receipt = bulkRecorder()->recordForTenant(
        $tenant,
        Money::fromRufiyaa('1000.00'),
        CarbonImmutable::parse('2026-01-05'),
        PaymentMethod::Cash,
        onlyInvoiceIds: [$invoices[1]->id, $invoices[2]->id],
    );

    expect($receipt->payments)->toHaveCount(2)
        ->and($invoices[0]->fresh()->payments()->count())->toBe(0)
        ->and($invoices[0]->fresh()->status)->not->toBe(InvoiceStatus::Paid)
        ->and($invoices[1]->fresh()->status)->toBe(InvoiceStatus::Paid)
        ->and($invoices[2]->fresh()->status)->toBe(InvoiceStatus::Paid);
});

it('recomputes each invoice’s fine as of the payment date before allocating (FR-PAY-02)', function () {
    // 0.5%/day on MVR 500 = MVR 2.50/day.
    [$tenant, $invoices] = tenantWithInvoices(2, FineRule::factory()->percentPerDay(50)->create(['effective_from' => '2020-01-01']));

    Artisan::call('invoices:refresh-fines', ['--as-of' => '2026-02-20']);

    $jan = $invoices[0]->fresh();
    $feb = $invoices[1]->fresh();

    // Jan due 10 Jan → 41 days late by 20 Feb = MVR 102.50 fine.
    // Feb due 10 Feb → 10 days late by 20 Feb = MVR 25.00 fine.
    expect($jan->fine_laari)->toBe(10_250)
        ->and($feb->fine_laari)->toBe(2_500);

    $receipt = bulkRecorder()->recordForTenant(
        $tenant,
        Money::fromLaari(50_000 + 10_250 + 50_000 + 2_500),
        CarbonImmutable::parse('2026-02-20'),
        PaymentMethod::Cash,
    );

    expect($receipt->payments)->toHaveCount(2)
        ->and($jan->fresh()->status)->toBe(InvoiceStatus::Paid)
        ->and($feb->fresh()->status)->toBe(InvoiceStatus::Paid);

    // Rent first, then fine — per invoice (§5.4).
    $janRow = $receipt->payments->firstWhere('invoice_id', $jan->id);

    expect($janRow->principal_allocated_laari)->toBe(50_000)
        ->and($janRow->fine_allocated_laari)->toBe(10_250);
});

it('lets a supervisor reverse one invoice’s slice without unwinding the whole receipt', function () {
    [$tenant, $invoices] = tenantWithInvoices(3);

    $receipt = bulkRecorder()->recordForTenant(
        $tenant,
        Money::fromRufiyaa('1500.00'),
        CarbonImmutable::parse('2026-01-05'),
        PaymentMethod::Cash,
    );

    $janRow = $receipt->payments->firstWhere('invoice_id', $invoices[0]->id);

    bulkRecorder()->reverse($janRow, 'Applied to the wrong tenant.', CarbonImmutable::parse('2026-01-06'));

    expect($janRow->fresh()->isReversed())->toBeTrue()
        ->and($invoices[0]->fresh()->status)->not->toBe(InvoiceStatus::Paid)
        // The other two allocations from the same receipt are untouched.
        ->and($invoices[1]->fresh()->status)->toBe(InvoiceStatus::Paid)
        ->and($invoices[2]->fresh()->status)->toBe(InvoiceStatus::Paid);
});

it('records who took the money on the receipt', function () {
    [$tenant] = tenantWithInvoices(1);
    $finance = User::factory()->create();
    $finance->assignRole('finance_officer');

    $receipt = bulkRecorder()->recordForTenant(
        $tenant,
        Money::fromRufiyaa('500.00'),
        CarbonImmutable::parse('2026-01-05'),
        PaymentMethod::BankTransfer,
        reference: 'BML 88421',
        recordedBy: $finance,
    );

    expect($receipt->recorded_by)->toBe($finance->id)
        ->and($receipt->tenant_id)->toBe($tenant->id)
        ->and($receipt->payments->first()->reference)->toBe('BML 88421')
        ->and($receipt->payments->first()->method)->toBe(PaymentMethod::BankTransfer);
});

it('ignores settled invoices when spreading the money', function () {
    [$tenant, $invoices] = tenantWithInvoices(2);

    // January is already paid off on its own.
    bulkRecorder()->record($invoices[0], Money::fromRufiyaa('500.00'), CarbonImmutable::parse('2026-01-05'), PaymentMethod::Cash);

    $receipt = bulkRecorder()->recordForTenant(
        $tenant,
        Money::fromRufiyaa('500.00'),
        CarbonImmutable::parse('2026-02-05'),
        PaymentMethod::Cash,
    );

    expect($receipt->payments)->toHaveCount(1)
        ->and($receipt->payments->first()->invoice_id)->toBe($invoices[1]->id);
});

it('is append-only: a receipt can never be edited or deleted', function () {
    [$tenant] = tenantWithInvoices(1);

    $receipt = bulkRecorder()->recordForTenant(
        $tenant, Money::fromRufiyaa('500.00'), CarbonImmutable::parse('2026-01-05'), PaymentMethod::Cash
    );

    expect(fn () => $receipt->update(['number' => '2026/999']))->toThrow(RuntimeException::class)
        ->and(fn () => $receipt->delete())->toThrow(RuntimeException::class);
});

it('keeps single-invoice receipts working exactly as before (FR-PAY-01)', function () {
    [, $invoices] = tenantWithInvoices(1);

    $payment = bulkRecorder()->record(
        $invoices[0], Money::fromRufiyaa('500.00'), CarbonImmutable::parse('2026-01-05'), PaymentMethod::Cash
    );

    expect($payment->receipt_number)->toBe('2026/001')
        ->and($payment->receipt->isSplit())->toBeFalse()
        ->and($invoices[0]->fresh()->status)->toBe(InvoiceStatus::Paid);
});

/**
 * A tenant who hands over one sum for five invoices gets ONE document showing
 * where all of it went — not five, and not a single line implying one charge.
 */
it('itemises the split on the receipt PDF, and serves it from any of its rows', function () {
    [$tenant, $invoices] = tenantWithInvoices(3);

    $receipt = bulkRecorder()->recordForTenant(
        $tenant,
        Money::fromRufiyaa('1500.00'),
        CarbonImmutable::parse('2026-01-05'),
        PaymentMethod::Cash,
    );

    $html = view('pdf.receipt', [
        'receipt' => $receipt->load('tenant', 'payments.invoice.lease.property'),
    ])->render();

    expect($html)
        ->toContain('2026/001')                                  // one number for the handover
        ->toContain('applied across 3 invoices')
        ->toContain('MVR 1,500.00');                             // total received

    // Every invoice it settled is named on the document.
    $invoices->each(fn (Invoice $i) => expect($html)->toContain($i->number));

    // The PDF route is addressed by payment row, but renders the whole receipt,
    // so any of the three rows produces the same document.
    $user = User::factory()->create();
    $user->assignRole('finance_officer');

    foreach ($receipt->payments as $row) {
        Pdf::fake();

        actingAs($user)->get("/payments/{$row->id}/receipt")->assertOk();

        Pdf::assertRespondedWithPdf(fn ($pdf) => $pdf->viewName === 'pdf.receipt');
    }
});
