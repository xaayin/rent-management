<?php

declare(strict_types=1);

use App\Enums\InvoiceStatus;
use App\Enums\PaymentMethod;
use App\Exceptions\InvalidPaymentException;
use App\Models\FineRule;
use App\Models\Invoice;
use App\Models\Lease;
use App\Models\Payment;
use App\Models\User;
use App\Services\Billing\InvoiceGenerator;
use App\Services\Billing\PaymentRecorder;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Spatie\Activitylog\Models\Activity;

use function Pest\Laravel\artisan;

/**
 * A flat MVR 500/month lease invoiced for January 2026, due 2026-01-10.
 * Principal = 50,000 laari; with the 0.5%/day rule the fine is MVR 2.50/day.
 */
function paymentInvoice(?FineRule $rule = null): Invoice
{
    $lease = Lease::factory()->active()->flat(50_000)->create([
        'start_date' => '2026-01-01',
        'rent_start_date' => '2026-01-01',
        'expiry_date' => '2036-01-01',
        'due_day' => 10,
    ]);

    if ($rule !== null) {
        $rule->update(['lease_id' => $lease->id]);
    }

    return app(InvoiceGenerator::class)->generate($lease, CarbonImmutable::parse('2026-01-01'));
}

function percentRule(): FineRule
{
    return FineRule::factory()->percentPerDay(50)->create(['effective_from' => '2020-01-01']);
}

function recorder(): PaymentRecorder
{
    return app(PaymentRecorder::class);
}

function pay(Invoice $invoice, int $laari, string $date): Payment
{
    return recorder()->record(
        $invoice,
        Money::fromLaari($laari),
        CarbonImmutable::parse($date),
        PaymentMethod::Cash,
    );
}

it('issues sequential YYYY/NNN receipt numbers (FR-PAY-01)', function () {
    $invoice = paymentInvoice();

    $first = pay($invoice, 20_000, '2026-01-05');
    $second = pay($invoice, 30_000, '2026-01-08');

    expect($first->receipt_number)->toBe('2026/001')
        ->and($second->receipt_number)->toBe('2026/002');
});

it('computes the fine on the actual payment date and allocates rent first (FR-PAY-02, §5.4)', function () {
    $invoice = paymentInvoice(percentRule());

    // 10 days late → fine 2,500. Paying 51,000 settles the 50,000 principal
    // first; the remaining 1,000 goes to the fine.
    $payment = pay($invoice, 51_000, '2026-01-20');
    $invoice->refresh();

    expect($invoice->fine_laari)->toBe(2_500)
        ->and($payment->principal_allocated_laari)->toBe(50_000)
        ->and($payment->fine_allocated_laari)->toBe(1_000)
        ->and($invoice->status)->toBe(InvoiceStatus::PartlyPaid)
        ->and($invoice->outstandingFineLaari())->toBe(1_500)
        ->and($invoice->outstandingTotalLaari())->toBe(1_500);
});

it('marks the invoice Paid only when rent and the due fine are fully settled (§5.4.25)', function () {
    $invoice = paymentInvoice(percentRule());

    pay($invoice, 52_500, '2026-01-20'); // 50,000 principal + 2,500 fine
    $invoice->refresh();

    expect($invoice->status)->toBe(InvoiceStatus::Paid)
        ->and($invoice->outstandingTotalLaari())->toBe(0);
});

it('charges no fine when paid on the due date', function () {
    $invoice = paymentInvoice(percentRule());

    pay($invoice, 50_000, '2026-01-10');
    $invoice->refresh();

    expect($invoice->fine_laari)->toBe(0)
        ->and($invoice->status)->toBe(InvoiceStatus::Paid);
});

it('supports partial payments and carries the balance forward (FR-PAY-03)', function () {
    $invoice = paymentInvoice();

    pay($invoice, 20_000, '2026-01-05');
    $invoice->refresh();

    expect($invoice->status)->toBe(InvoiceStatus::PartlyPaid)
        ->and($invoice->outstandingPrincipalLaari())->toBe(30_000);

    pay($invoice, 30_000, '2026-01-08');
    $invoice->refresh();

    expect($invoice->status)->toBe(InvoiceStatus::Paid)
        ->and($invoice->outstandingTotalLaari())->toBe(0);
});

it('continues accruing fine while principal is outstanding after a partial payment', function () {
    $invoice = paymentInvoice(percentRule());

    pay($invoice, 20_000, '2026-01-20'); // fine at that date: 2,500
    artisan('invoices:refresh-fines', ['--as-of' => '2026-01-30']);
    $invoice->refresh();

    expect($invoice->fine_laari)->toBe(5_000) // 20 days × 250
        ->and($invoice->status)->toBe(InvoiceStatus::PartlyPaid);
});

it('freezes the fine once the principal is settled', function () {
    $invoice = paymentInvoice(percentRule());

    // Settles the full principal + 2,000 of the 2,500 fine due on Jan 20.
    pay($invoice, 52_000, '2026-01-20');
    artisan('invoices:refresh-fines', ['--as-of' => '2026-03-01']);
    $invoice->refresh();

    expect($invoice->fine_laari)->toBe(2_500)          // frozen — not 50 days' worth
        ->and($invoice->outstandingFineLaari())->toBe(500)
        ->and($invoice->status)->toBe(InvoiceStatus::PartlyPaid);
});

it('rejects a payment exceeding the outstanding balance', function () {
    $invoice = paymentInvoice();

    expect(fn () => pay($invoice, 60_000, '2026-01-05'))
        ->toThrow(InvalidPaymentException::class);
});

it('rejects a non-positive payment amount', function () {
    $invoice = paymentInvoice();

    expect(fn () => pay($invoice, 0, '2026-01-05'))->toThrow(InvalidPaymentException::class);
});

it('reverses a payment as an appended negative entry with a reason (FR-PAY-06)', function () {
    $invoice = paymentInvoice(percentRule());
    $payment = pay($invoice, 52_500, '2026-01-20');
    expect($invoice->refresh()->status)->toBe(InvoiceStatus::Paid);

    $reversal = recorder()->reverse($payment, 'Cheque bounced.', CarbonImmutable::parse('2026-01-25'));
    $invoice->refresh();

    expect($reversal->amount_laari)->toBe(-52_500)
        ->and($reversal->principal_allocated_laari)->toBe(-50_000)
        ->and($reversal->fine_allocated_laari)->toBe(-2_500)
        ->and($reversal->reversal_reason)->toBe('Cheque bounced.')
        ->and($reversal->receipt_number)->toBeNull()
        ->and($payment->fresh())->not->toBeNull()             // original still present
        ->and($payment->isReversed())->toBeTrue()
        ->and($invoice->payments()->count())->toBe(2)          // appended, nothing deleted
        ->and($invoice->status)->toBe(InvoiceStatus::Overdue)  // unpaid & past due again
        ->and($invoice->outstandingPrincipalLaari())->toBe(50_000)
        ->and(Activity::where('log_name', 'payment')->count())->toBeGreaterThanOrEqual(2);
});

it('cannot reverse a reversal or double-reverse a payment', function () {
    $invoice = paymentInvoice();
    $payment = pay($invoice, 50_000, '2026-01-05');
    $reversal = recorder()->reverse($payment, 'Wrong invoice.', CarbonImmutable::parse('2026-01-06'));

    expect(fn () => recorder()->reverse($payment, 'Again.', CarbonImmutable::parse('2026-01-07')))
        ->toThrow(InvalidPaymentException::class)
        ->and(fn () => recorder()->reverse($reversal, 'Nope.', CarbonImmutable::parse('2026-01-07')))
        ->toThrow(InvalidPaymentException::class);
});

it('never lets a receipt number be edited after issue (FR-PAY-07)', function () {
    $invoice = paymentInvoice();
    $payment = pay($invoice, 50_000, '2026-01-05');

    expect(fn () => $payment->update(['receipt_number' => '2026/999']))
        ->toThrow(RuntimeException::class);
});

it('never lets a payment be deleted', function () {
    $invoice = paymentInvoice();
    $payment = pay($invoice, 50_000, '2026-01-05');

    expect(fn () => $payment->delete())->toThrow(RuntimeException::class);
});

it('never lets an invoice be deleted', function () {
    $invoice = paymentInvoice();

    expect(fn () => $invoice->delete())->toThrow(RuntimeException::class);
});

it('enforces the §6.1 matrix on recording and reversing payments', function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    $invoice = paymentInvoice();
    $payment = pay($invoice, 20_000, '2026-01-05');

    $finance = User::factory()->create();
    $finance->assignRole('finance_officer');
    $supervisor = User::factory()->create();
    $supervisor->assignRole('supervisor');
    $admin = User::factory()->create();
    $admin->assignRole('administrator');
    $landOfficer = User::factory()->create();
    $landOfficer->assignRole('land_officer');

    expect($finance->can('create', Payment::class))->toBeTrue()
        ->and($supervisor->can('create', Payment::class))->toBeTrue()
        ->and($admin->can('create', Payment::class))->toBeFalse()      // matrix: Admin '–'
        ->and($landOfficer->can('create', Payment::class))->toBeFalse()
        // Reversal is approval-gated: only the Supervisor acts directly.
        ->and($supervisor->can('reverse', $payment))->toBeTrue()
        ->and($finance->can('reverse', $payment))->toBeFalse();
});
