<?php

declare(strict_types=1);

use App\Livewire\Invoices\Index as InvoicesIndex;
use App\Livewire\Leases\Index as LeasesIndex;
use App\Models\Invoice;
use App\Models\Lease;
use App\Models\Property;
use App\Models\Tenant;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
});

function billingUser(string $role): User
{
    $user = User::factory()->create();
    $user->assignRole($role);

    return $user;
}

it('lets a finance officer view invoices but blocks a land officer', function () {
    actingAs(billingUser('finance_officer'))->get('/invoices')->assertOk();
    actingAs(billingUser('land_officer'))->get('/invoices')->assertForbidden();
});

it('generates the month\'s invoices from the screen', function () {
    Lease::factory()->count(2)->active()->create([
        'start_date' => '2026-01-01',
        'rent_start_date' => '2026-01-01',
        'expiry_date' => '2036-01-01',
    ]);

    actingAs(billingUser('finance_officer'));

    Livewire::test(InvoicesIndex::class)
        ->set('period', '2026-03')
        ->call('generate')
        ->assertHasNoErrors();

    expect(Invoice::count())->toBe(2);
});

it('persists grace and CSR configuration from the lease form', function () {
    actingAs(billingUser('land_officer'));
    $property = Property::factory()->create();
    $tenant = Tenant::factory()->create();

    Livewire::test(LeasesIndex::class)
        ->call('create')
        ->set('agreement_number', 'AG-CSR/2026')
        ->set('property_id', $property->id)
        ->set('tenant_id', $tenant->id)
        ->set('agreement_date', '2026-01-01')
        ->set('start_date', '2026-01-01')
        ->set('rent_start_date', '2026-01-01')
        ->set('duration_years', 10)
        ->set('expiry_date', '2036-01-01')
        ->set('rent_basis', 'per_sqft')
        ->set('rate_laari', 53)
        ->set('area_sqft', 2000)
        ->set('grace_months', 6)
        ->set('due_day', 10)
        ->set('csr_type', 'fixed_annual')
        ->set('csr_amount', '9000')
        ->set('csr_month', 1)
        ->set('status', 'draft')
        ->call('save')
        ->assertHasNoErrors();

    $lease = Lease::where('agreement_number', 'AG-CSR/2026')->first();

    expect($lease->grace_months)->toBe(6)
        ->and($lease->csr_type->value)->toBe('fixed_annual')
        ->and($lease->csr_amount_laari)->toBe(900_000)
        ->and($lease->csr_month)->toBe(1);
});
