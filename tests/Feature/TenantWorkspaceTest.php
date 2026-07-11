<?php

declare(strict_types=1);

use App\Enums\PaymentMethod;
use App\Livewire\Tenants\Index as TenantsIndex;
use App\Models\Lease;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Billing\InvoiceGenerator;
use App\Services\Billing\PaymentRecorder;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
});

/**
 * A tenant with one flat MVR 500 lease, a January 2026 invoice and a MVR 200
 * payment — MVR 300 outstanding.
 */
function tenantWithDrilldown(): array
{
    $tenant = Tenant::factory()->create();
    $lease = Lease::factory()->active()->flat(50_000)->create([
        'tenant_id' => $tenant->id,
        'start_date' => '2026-01-01',
        'rent_start_date' => '2026-01-01',
        'expiry_date' => '2036-01-01',
    ]);

    $invoice = app(InvoiceGenerator::class)->generate($lease, CarbonImmutable::parse('2026-01-01'));
    $payment = app(PaymentRecorder::class)->record(
        $invoice, Money::fromLaari(20_000), CarbonImmutable::parse('2026-01-05'), PaymentMethod::Cash,
    );

    return [$tenant, $lease, $invoice, $payment];
}

it('opens the tenant slide-over with the consolidated balance and leases', function () {
    [$tenant, $lease] = tenantWithDrilldown();

    $officer = User::factory()->create();
    $officer->assignRole('land_officer');
    actingAs($officer);

    Livewire::test(TenantsIndex::class)
        ->call('selectTenant', $tenant->id)
        ->assertSee($tenant->name)
        ->assertSee('Balance due · all leases')
        ->assertSee('MVR 300.00')                    // 500 billed − 200 paid
        ->assertSee($lease->agreement_number)
        ->assertSee('Statement');
});

it('drills down from lease to invoices to payments', function () {
    [$tenant, $lease, $invoice, $payment] = tenantWithDrilldown();

    $officer = User::factory()->create();
    $officer->assignRole('land_officer');
    actingAs($officer);

    Livewire::test(TenantsIndex::class)
        ->call('selectTenant', $tenant->id)
        ->assertDontSee($invoice->number)
        // Level 1 → the lease's invoices appear.
        ->call('toggleLease', $lease->id)
        ->assertSee($invoice->number)
        ->assertSee('Partly paid')
        ->assertDontSee($payment->receipt_number)
        // Level 2 → the invoice's payments appear with the allocation split.
        ->call('toggleInvoice', $invoice->id)
        ->assertSee($payment->receipt_number)
        ->assertSee('MVR 200.00')
        // Toggling the lease closed collapses everything.
        ->call('toggleLease', $lease->id)
        ->assertDontSee($invoice->number);
});

it('shows recent SMS messages in the tenant detail', function () {
    [$tenant, , $invoice] = tenantWithDrilldown();

    \App\Models\NotificationLog::create([
        'tenant_id' => $tenant->id,
        'invoice_id' => $invoice->id,
        'kind' => 'manual',
        'channel' => 'sms',
        'recipient' => $tenant->mobile,
        'content' => 'Please settle invoice '.$invoice->number,
        'status' => 'sent',
    ]);

    $officer = User::factory()->create();
    $officer->assignRole('land_officer');
    actingAs($officer);

    Livewire::test(TenantsIndex::class)
        ->call('selectTenant', $tenant->id)
        ->assertSee('Recent messages')
        ->assertSee('Please settle invoice '.$invoice->number);
});
