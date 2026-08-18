<?php

declare(strict_types=1);

use App\Enums\InvoiceStatus;
use App\Enums\PaymentMethod;
use App\Exceptions\InvalidInvoiceCancellationException;
use App\Livewire\Invoices\Index as InvoicesIndex;
use App\Models\FineRule;
use App\Models\Invoice;
use App\Models\Lease;
use App\Models\NotificationLog;
use App\Models\User;
use App\Services\Billing\InvoiceCanceller;
use App\Services\Billing\InvoiceGenerator;
use App\Services\Billing\PaymentRecorder;
use App\Services\Reminders\ReminderDispatcher;
use App\Services\Reporting\ReportService;
use App\Services\Reporting\TenantLedger;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\artisan;

/**
 * An invoice raised in error is VOIDED, never deleted: the row and its
 * YYYY/NNN number stay so the sequence has no unexplainable hole, while the
 * charge drops out of every balance, statement, reminder and report.
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
});

function cancellableLease(): Lease
{
    return Lease::factory()->active()->flat(50_000)->create([
        'start_date' => '2026-01-01',
        'rent_start_date' => '2026-01-01',
        'expiry_date' => '2036-01-01',
        'due_day' => 10,
    ]);
}

function cancellableInvoice(?Lease $lease = null, string $month = '2026-01-01'): Invoice
{
    return app(InvoiceGenerator::class)->generate($lease ?? cancellableLease(), CarbonImmutable::parse($month));
}

function canceller(): InvoiceCanceller
{
    return app(InvoiceCanceller::class);
}

function supervisorUser(): User
{
    $user = User::factory()->create();
    $user->assignRole('supervisor');

    return $user;
}

// --- The core rule ------------------------------------------------------------

it('voids an unpaid invoice, keeping its number and recording who and why', function () {
    $invoice = cancellableInvoice();
    $number = $invoice->number;
    $by = supervisorUser();

    canceller()->cancel($invoice, 'Raised against the wrong lease.', $by);
    $invoice->refresh();

    expect($invoice->status)->toBe(InvoiceStatus::Cancelled)
        ->and($invoice->number)->toBe($number)              // the sequence keeps no hole
        ->and($invoice->cancellation_reason)->toBe('Raised against the wrong lease.')
        ->and($invoice->cancelled_by)->toBe($by->id)
        ->and($invoice->cancelled_at)->not->toBeNull()
        ->and($invoice->isCancelled())->toBeTrue();
});

it('still refuses to delete an invoice outright', function () {
    $invoice = cancellableInvoice();
    canceller()->cancel($invoice, 'Duplicate.');

    $invoice->refresh()->delete();
})->throws(RuntimeException::class);

it('refuses to void an invoice that has been paid', function () {
    $invoice = cancellableInvoice();

    app(PaymentRecorder::class)->record(
        $invoice, Money::fromLaari(10_000), CarbonImmutable::parse('2026-01-05'), PaymentMethod::Cash,
    );

    canceller()->cancel($invoice->refresh(), 'Changed my mind.');
})->throws(InvalidInvoiceCancellationException::class, 'payment');

it('allows voiding once the only payment has been reversed', function () {
    $invoice = cancellableInvoice();

    $payment = app(PaymentRecorder::class)->record(
        $invoice, Money::fromLaari(10_000), CarbonImmutable::parse('2026-01-05'), PaymentMethod::Cash,
    );
    app(PaymentRecorder::class)->reverse($payment, 'Cheque bounced.', CarbonImmutable::parse('2026-01-09'));

    canceller()->cancel($invoice->refresh(), 'Invoice was a duplicate.');

    expect($invoice->refresh()->status)->toBe(InvoiceStatus::Cancelled);
});

it('refuses to void the same invoice twice', function () {
    $invoice = cancellableInvoice();
    canceller()->cancel($invoice, 'First.');

    canceller()->cancel($invoice->refresh(), 'Second.');
})->throws(InvalidInvoiceCancellationException::class, 'already');

it('requires a reason', function () {
    canceller()->cancel(cancellableInvoice(), '   ');
})->throws(InvalidInvoiceCancellationException::class, 'reason');

// --- It really does leave the system ------------------------------------------

it('drops out of the tenant balance, the ledger and the arrears report', function () {
    $lease = cancellableLease();
    $invoice = cancellableInvoice($lease);
    $tenant = $lease->tenant;

    expect($tenant->outstandingBalance()->laari)->toBe($invoice->total_laari)
        ->and($tenant->outstandingInvoiceCount())->toBe(1);

    canceller()->cancel($invoice, 'Raised in error.');

    $reports = app(ReportService::class);
    $today = CarbonImmutable::parse('2026-02-01');

    expect($tenant->fresh()->outstandingBalance()->laari)->toBe(0)
        ->and($tenant->fresh()->outstandingInvoiceCount())->toBe(0)
        ->and($reports->arrears($today))->toBeEmpty()
        ->and(app(TenantLedger::class)->entries($tenant)->pluck('label')->implode(' '))
        ->not->toContain($invoice->number);
});

it('is left out of the billed figures on the dashboard', function () {
    $today = CarbonImmutable::parse('2026-01-15');
    $lease = cancellableLease();
    $invoice = cancellableInvoice($lease);

    $reports = app(ReportService::class);
    expect($reports->dashboard($today)['billed_invoices'])->toBe(1);

    canceller()->cancel($invoice, 'Raised in error.');

    expect($reports->dashboard($today)['billed_invoices'])->toBe(0)
        ->and($reports->dashboard($today)['billed_laari'])->toBe(0)
        ->and($reports->incomeByMonth(2026)->firstWhere('month', 1)['billed_laari'])->toBe(0);
});

it('stops accruing a fine and is skipped by reminders', function () {
    $lease = cancellableLease();
    FineRule::factory()->percentPerDay(50)->create([
        'lease_id' => $lease->id, 'effective_from' => '2020-01-01',
    ]);
    $invoice = cancellableInvoice($lease);

    canceller()->cancel($invoice, 'Raised in error.');

    artisan('invoices:refresh-fines', ['--as-of' => '2026-03-01'])->assertSuccessful();

    expect($invoice->refresh()->fine_laari)->toBe(0)
        ->and($invoice->status)->toBe(InvoiceStatus::Cancelled)
        ->and(NotificationLog::count())->toBe(0);

    // The nightly reminder run must not chase a voided invoice.
    app(ReminderDispatcher::class)->dispatchForDate(CarbonImmutable::parse('2026-03-01'));

    expect(NotificationLog::count())->toBe(0);
});

it('frees the month so a corrected invoice can be raised', function () {
    $lease = cancellableLease();
    $wrong = cancellableInvoice($lease);

    canceller()->cancel($wrong, 'Wrong amount.');

    $corrected = app(InvoiceGenerator::class)->generate($lease, CarbonImmutable::parse('2026-01-01'));

    expect($corrected)->not->toBeNull()
        ->and($corrected->id)->not->toBe($wrong->id)
        ->and($corrected->number)->not->toBe($wrong->number)   // a fresh number, the void one retired
        ->and($corrected->status)->toBe(InvoiceStatus::Issued);
});

// --- Screen -------------------------------------------------------------------

it('voids an invoice from the invoices screen with a reason', function () {
    $invoice = cancellableInvoice();
    actingAs(supervisorUser());

    Livewire::test(InvoicesIndex::class)
        ->call('startCancel', $invoice->id)
        ->call('confirmCancel')
        ->assertHasErrors('cancellation_reason')            // the reason is mandatory
        ->set('cancellation_reason', 'Duplicate of 2026/004.')
        ->call('confirmCancel')
        ->assertHasNoErrors();

    expect($invoice->refresh()->status)->toBe(InvoiceStatus::Cancelled);
});

it('blocks voiding from the screen once the invoice has money against it', function () {
    $invoice = cancellableInvoice();
    app(PaymentRecorder::class)->record(
        $invoice, Money::fromLaari(10_000), CarbonImmutable::parse('2026-01-05'), PaymentMethod::Cash,
    );

    actingAs(supervisorUser());

    Livewire::test(InvoicesIndex::class)
        ->call('startCancel', $invoice->id)
        ->set('cancellation_reason', 'Trying anyway.')
        ->call('confirmCancel')
        ->assertHasErrors('cancellation_reason');

    expect($invoice->refresh()->status)->not->toBe(InvoiceStatus::Cancelled);
});

it('keeps the void action away from roles that cannot issue invoices', function () {
    $invoice = cancellableInvoice();
    $landOfficer = User::factory()->create();
    $landOfficer->assignRole('land_officer');

    expect($landOfficer->can('cancel', $invoice))->toBeFalse()
        ->and(supervisorUser()->can('cancel', $invoice))->toBeTrue();
});
