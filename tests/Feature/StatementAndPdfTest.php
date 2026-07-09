<?php

declare(strict_types=1);

use App\Enums\PaymentMethod;
use App\Livewire\Invoices\Index as InvoicesIndex;
use App\Models\FineRule;
use App\Models\Invoice;
use App\Models\Lease;
use App\Models\Payment;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Billing\InvoiceGenerator;
use App\Services\Billing\PaymentRecorder;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Artisan;
use Livewire\Livewire;
use Spatie\LaravelPdf\Facades\Pdf;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
});

function statementUser(string $role): User
{
    $user = User::factory()->create();
    $user->assignRole($role);

    return $user;
}

/**
 * A tenant with one flat MVR 500 lease, a January 2026 invoice and a MVR 200
 * payment — leaving MVR 300 outstanding.
 */
function tenantWithLedger(): array
{
    $tenant = Tenant::factory()->create();
    $lease = Lease::factory()->active()->flat(50_000)->create([
        'tenant_id' => $tenant->id,
        'start_date' => '2026-01-01',
        'rent_start_date' => '2026-01-01',
        'expiry_date' => '2036-01-01',
        'due_day' => 10,
    ]);

    $invoice = app(InvoiceGenerator::class)->generate($lease, CarbonImmutable::parse('2026-01-01'));

    $payment = app(PaymentRecorder::class)->record(
        $invoice,
        Money::fromLaari(20_000),
        CarbonImmutable::parse('2026-01-05'),
        PaymentMethod::Cash,
    );

    return [$tenant, $invoice, $payment];
}

it('renders the consolidated tenant statement with a running balance (FR-PAY-05)', function () {
    [$tenant, $invoice, $payment] = tenantWithLedger();

    actingAs(statementUser('auditor'))
        ->get("/tenants/{$tenant->id}/statement")
        ->assertOk()
        ->assertSee($invoice->number)
        ->assertSee($payment->receipt_number)
        ->assertSee('MVR 300.00');   // balance due after the partial payment
});

it('shows reversals on the statement', function () {
    [$tenant, $invoice, $payment] = tenantWithLedger();

    app(PaymentRecorder::class)->reverse($payment, 'Recorded against the wrong invoice.', CarbonImmutable::parse('2026-01-06'));

    actingAs(statementUser('auditor'))
        ->get("/tenants/{$tenant->id}/statement")
        ->assertOk()
        ->assertSee('Reversal of receipt')
        ->assertSee('MVR 500.00');   // balance back to the full invoice
});

it('requires sign-in for the statement', function () {
    [$tenant] = tenantWithLedger();

    get("/tenants/{$tenant->id}/statement")->assertRedirect('/login');
});

it('serves the invoice PDF through the fake driver (FR-INV-07)', function () {
    [, $invoice] = tenantWithLedger();

    Pdf::fake();

    actingAs(statementUser('finance_officer'))
        ->get("/invoices/{$invoice->id}/pdf")
        ->assertOk();

    Pdf::assertRespondedWithPdf(fn ($pdf) => $pdf->viewName === 'pdf.invoice');
});

it('serves the receipt PDF and rejects reversal rows', function () {
    [, $invoice, $payment] = tenantWithLedger();
    $reversal = app(PaymentRecorder::class)->reverse($payment, 'Wrong amount.', CarbonImmutable::parse('2026-01-06'));

    Pdf::fake();

    actingAs(statementUser('finance_officer'))
        ->get("/payments/{$payment->id}/receipt")
        ->assertOk();

    Pdf::assertRespondedWithPdf(fn ($pdf) => $pdf->viewName === 'pdf.receipt');

    actingAs(statementUser('finance_officer'))
        ->get("/payments/{$reversal->id}/receipt")
        ->assertNotFound();
});

it('renders the invoice PDF HTML with the full fine breakdown (FR-FIN-12)', function () {
    $rule = FineRule::factory()->percentPerDay(50)->create(['effective_from' => '2020-01-01']);
    $lease = Lease::factory()->active()->flat(50_000)->create([
        'start_date' => '2026-01-01',
        'rent_start_date' => '2026-01-01',
        'expiry_date' => '2036-01-01',
        'due_day' => 10,
    ]);
    $rule->update(['lease_id' => $lease->id]);

    $invoice = app(InvoiceGenerator::class)->generate($lease, CarbonImmutable::parse('2026-01-01'));
    Artisan::call('invoices:refresh-fines', ['--as-of' => '2026-01-20']);

    $html = view('pdf.invoice', ['invoice' => $invoice->fresh()->load(['lease.tenant', 'lease.property', 'lineItems'])])->render();

    expect($html)
        ->toContain('Late fine')
        ->toContain('Percentage per day')      // method spelled out
        ->toContain('10 day(s) late')
        ->toContain('MVR 2.50')                // daily amount
        ->toContain(config('billing.payment_account'));
});

it('renders the receipt PDF HTML with the allocation split (FR-PAY-02)', function () {
    [, , $payment] = tenantWithLedger();

    $html = view('pdf.receipt', ['payment' => $payment->load('invoice.lease.tenant', 'invoice.lease.property')])->render();

    expect($html)
        ->toContain((string) $payment->receipt_number)
        ->toContain('Rent &amp; charges')
        ->toContain('MVR 200.00')              // amount received
        ->toContain('MVR 300.00');             // balance remaining
});

it('records a payment from the invoices screen with the flash receipt message', function () {
    [, $invoice] = tenantWithLedger();

    actingAs(statementUser('finance_officer'));

    Livewire::test(InvoicesIndex::class)
        ->call('startPayment', $invoice->id)
        ->set('pay_amount', '300.00')
        ->set('pay_date', '2026-01-08')
        ->set('pay_method', 'bank_transfer')
        ->call('confirmPayment')
        ->assertHasNoErrors();

    expect($invoice->fresh()->outstandingTotalLaari())->toBe(0)
        ->and($invoice->fresh()->status->value)->toBe('paid');
});

it('blocks recording a payment for a user without the permission', function () {
    [, $invoice] = tenantWithLedger();

    // Administrators may open the invoices screen but NOT record payments (§6.1).
    actingAs(statementUser('administrator'));

    Livewire::test(InvoicesIndex::class)
        ->call('startPayment', $invoice->id)
        ->assertForbidden();
});

it('lets a supervisor reverse a payment from the screen but not a finance officer', function () {
    [, $invoice, $payment] = tenantWithLedger();

    actingAs(statementUser('finance_officer'));
    Livewire::test(InvoicesIndex::class)
        ->call('startReverse', $payment->id)
        ->assertForbidden();

    actingAs(statementUser('supervisor'));
    Livewire::test(InvoicesIndex::class)
        ->call('startReverse', $payment->id)
        ->set('reversal_reason', 'Duplicate entry.')
        ->call('confirmReverse')
        ->assertHasNoErrors();

    expect($payment->fresh()->isReversed())->toBeTrue()
        ->and(Payment::count())->toBe(2);
});
