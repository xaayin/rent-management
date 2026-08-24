<?php

declare(strict_types=1);

use App\Enums\InvoiceStatus;
use App\Enums\PaymentMethod;
use App\Livewire\Leases\Index;
use App\Livewire\Leases\Show as LeaseShow;
use App\Models\FineRule;
use App\Models\Lease;
use App\Models\User;
use App\Services\Billing\InvoiceCanceller;
use App\Services\Billing\InvoiceFineApplier;
use App\Services\Billing\InvoiceGenerator;
use App\Services\Billing\PaymentRecorder;
use App\Services\Reporting\LeaseAccountSummary;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;

/**
 * The full lease page (/leases/{id}). Its money band answers "what is owed
 * right now" with the fine recomputed live through the SAME engine a payment
 * would use — a figure staff quote on the phone must be the figure the payment
 * settles.
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
});

/** MVR 500/month, due the 10th, MVR 1.00/day fine from 2020. */
function pageLease(): Lease
{
    $lease = Lease::factory()->active()->flat(50_000)->create([
        'start_date' => '2026-01-01',
        'rent_start_date' => '2026-01-01',
        'expiry_date' => '2036-01-01',
        'due_day' => 10,
    ]);

    FineRule::factory()->flatPerDay(100)->create([
        'lease_id' => $lease->id, 'effective_from' => '2020-01-01',
    ]);

    return $lease;
}

function supervisorFor(): User
{
    $user = User::factory()->create();
    $user->assignRole('supervisor');

    return $user;
}

// --- The money band -------------------------------------------------------------

it('states what is owed today with the fine recomputed live', function () {
    $lease = pageLease();
    app(InvoiceGenerator::class)->generate($lease, CarbonImmutable::parse('2026-01-01'));

    // 20 Jan: 10 days past the 10th at MVR 1.00/day, with NO fine refresh run.
    $summary = app(LeaseAccountSummary::class)->for($lease, CarbonImmutable::parse('2026-01-20'));

    expect($summary['principal']->laari)->toBe(50_000)
        ->and($summary['fine']->laari)->toBe(1_000)      // live, not the stored 0
        ->and($summary['total']->laari)->toBe(51_000)
        ->and($summary['unpaid_count'])->toBe(1)
        ->and($summary['days_overdue'])->toBe(10)
        ->and($summary['settled'])->toBeFalse();
});

it('reports a settled lease as up to date rather than zero owing', function () {
    $lease = pageLease();
    $invoice = app(InvoiceGenerator::class)->generate($lease, CarbonImmutable::parse('2026-01-01'));

    app(PaymentRecorder::class)->record(
        $invoice, Money::fromLaari(50_000), CarbonImmutable::parse('2026-01-05'), PaymentMethod::Cash,
    );

    $summary = app(LeaseAccountSummary::class)->for($lease, CarbonImmutable::parse('2026-01-20'));

    expect($summary['settled'])->toBeTrue()
        ->and($summary['total']->laari)->toBe(0)
        ->and($summary['unpaid_count'])->toBe(0)
        ->and($summary['days_overdue'])->toBe(0);
});

it('never counts a cancelled invoice in what is owed', function () {
    $lease = pageLease();
    $invoice = app(InvoiceGenerator::class)->generate($lease, CarbonImmutable::parse('2026-01-01'));
    app(InvoiceCanceller::class)->cancel($invoice, 'Raised in error.');

    $summary = app(LeaseAccountSummary::class)->for($lease, CarbonImmutable::parse('2026-01-20'));

    expect($summary['total']->laari)->toBe(0)
        ->and($summary['settled'])->toBeTrue();
});

it('quotes the same figure the record-payment panel would settle', function () {
    $lease = pageLease();
    $invoice = app(InvoiceGenerator::class)->generate($lease, CarbonImmutable::parse('2026-01-01'));

    $asOf = CarbonImmutable::parse('2026-02-14');
    $summary = app(LeaseAccountSummary::class)->for($lease, $asOf);
    $preview = app(InvoiceFineApplier::class)->previewFine($invoice, $asOf);

    expect($summary['fine']->laari)->toBe($preview->totalLaari);
});

// --- The page -------------------------------------------------------------------

it('opens the lease page with its money band, invoices and payments', function () {
    $lease = pageLease();
    $invoice = app(InvoiceGenerator::class)->generate($lease, CarbonImmutable::parse('2026-01-01'));
    app(PaymentRecorder::class)->record(
        $invoice, Money::fromLaari(20_000), CarbonImmutable::parse('2026-01-15'), PaymentMethod::Cash,
    );

    actingAs(supervisorFor());

    Livewire::test(LeaseShow::class, ['lease' => $lease])
        ->assertSee($lease->agreement_number)
        ->assertSee($lease->tenant->name)
        ->assertSee('Due today')
        ->assertSee('Outstanding invoices')
        ->assertSee('Payments & receipts')
        ->assertSee('Lease terms')
        ->assertSee($invoice->number);
});

it('is reachable by URL and gated like the rest of the registry', function () {
    $lease = pageLease();

    actingAs(supervisorFor())->get("/leases/{$lease->id}")->assertOk();

    $finance = User::factory()->create();
    $finance->assignRole('finance_officer');           // §6.1 keeps Finance out of /leases
    actingAs($finance)->get("/leases/{$lease->id}")->assertForbidden();
});

it('records a payment from the lease page', function () {
    $lease = pageLease();
    $invoice = app(InvoiceGenerator::class)->generate($lease, CarbonImmutable::parse('2026-01-01'));

    actingAs(supervisorFor());

    Livewire::test(LeaseShow::class, ['lease' => $lease])
        ->call('startPayment', $invoice->id)
        // MVR 500 rent + 5 days late at MVR 1.00/day — the page quotes the fine,
        // so settling it in full means paying it.
        ->set('pay_amount', '505.00')
        ->set('pay_date', '2026-01-15')
        ->call('confirmPayment')
        ->assertHasNoErrors();

    expect($invoice->refresh()->status)->toBe(InvoiceStatus::Paid)
        ->and($invoice->fine_laari)->toBe(500);
});

it('links through from the list peek to the full page', function () {
    $lease = pageLease();
    actingAs(supervisorFor());

    Livewire::test(Index::class)
        ->call('selectLease', $lease->id)
        ->assertSee('Open lease')
        ->assertSee(route('leases.show', $lease), escape: false);
});

it('never shows a tenant balance smaller than the lease it contains', function () {
    $lease = pageLease();
    app(InvoiceGenerator::class)->generate($lease, CarbonImmutable::parse('2026-01-01'));

    $asOf = CarbonImmutable::parse('2026-03-01');   // well past due, no fine refresh run
    $summaries = app(LeaseAccountSummary::class);

    $leaseDue = $summaries->for($lease, $asOf)['total'];
    $tenantDue = $summaries->forTenant($lease->tenant, $asOf);

    expect($leaseDue->laari)->toBeGreaterThan(0)
        ->and($tenantDue->laari)->toBeGreaterThanOrEqual($leaseDue->laari)
        // the stored figure is the stale one this guards against
        ->and($lease->tenant->outstandingBalance()->laari)->toBeLessThan($tenantDue->laari);
});

it('deep-links back to the edit form and the fine schedule from the page', function () {
    $lease = pageLease();
    actingAs(supervisorFor());

    Livewire::withQueryParams(['edit' => $lease->id])
        ->test(Index::class)
        ->assertSet('editingId', $lease->id)
        ->assertSet('showForm', true);

    Livewire::withQueryParams(['fines' => $lease->id])
        ->test(Index::class)
        ->assertSet('fineRuleLeaseId', $lease->id);
});
