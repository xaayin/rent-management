<?php

declare(strict_types=1);

use App\Enums\LeaseStatus;
use App\Livewire\Leases\Index as LeasesIndex;
use App\Livewire\Properties\Index as PropertiesIndex;
use App\Livewire\Tenants\Index as TenantsIndex;
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

function registryUser(string $role): User
{
    $user = User::factory()->create();
    $user->assignRole($role);

    return $user;
}

// --- Properties -------------------------------------------------------------

it('lets a land officer view properties but blocks an auditor', function () {
    actingAs(registryUser('land_officer'))->get('/properties')->assertOk();
    actingAs(registryUser('auditor'))->get('/properties')->assertForbidden();
});

it('creates and archives a property', function () {
    actingAs(registryUser('land_officer'));

    Livewire::test(PropertiesIndex::class)
        ->call('create')
        ->set('name', 'Harbour Kiosk')
        ->set('land_number', 'L-9001')
        ->set('size_sqft', 320)
        ->set('usage_type', 'commercial')
        ->call('save')
        ->assertHasNoErrors();

    $property = Property::where('land_number', 'L-9001')->first();
    expect($property)->not->toBeNull();

    Livewire::test(PropertiesIndex::class)->call('archive', $property->id);
    expect($property->fresh()->isArchived())->toBeTrue();
});

it('filters and paginates the property list', function () {
    foreach (range(1, 12) as $i) {
        Property::factory()->create(['name' => sprintf('Plot %02d', $i), 'usage_type' => 'commercial']);
    }
    Property::factory()->archived()->create(['name' => 'Zulu Boatyard', 'land_number' => 'L-Z1', 'usage_type' => 'boat_shed']);

    actingAs(registryUser('land_officer'));

    Livewire::test(PropertiesIndex::class)
        // 13 properties, name-ordered, 10 per page.
        ->assertSee('Showing 1–10 of 13')
        ->assertDontSee('Zulu Boatyard')
        ->call('gotoPage', 2)
        ->assertSee('Zulu Boatyard')
        // Usage chip resets to page 1.
        ->set('usageFilter', 'boat_shed')
        ->assertSee('Showing 1–1 of 1')
        ->assertSee('Zulu Boatyard')
        // Status chip.
        ->call('clearFilters')
        ->set('statusFilter', 'archived')
        ->assertSee('Showing 1–1 of 1')
        // Search by land number.
        ->call('clearFilters')
        ->set('q', 'L-Z1')
        ->assertSee('Zulu Boatyard')
        ->assertDontSee('Plot 01');
});

// --- Tenants ----------------------------------------------------------------

it('requires a national ID for an individual tenant', function () {
    actingAs(registryUser('land_officer'));

    Livewire::test(TenantsIndex::class)
        ->call('create')
        ->set('type', 'individual')
        ->set('name', 'Hawwa')
        ->set('mobile', '+9607771000')
        ->call('save')
        ->assertHasErrors(['national_id']);
});

it('requires a company reg number and contact person for an organisation', function () {
    actingAs(registryUser('land_officer'));

    Livewire::test(TenantsIndex::class)
        ->call('create')
        ->set('type', 'organisation')
        ->set('name', 'Island Traders')
        ->set('mobile', '+9607772000')
        ->call('save')
        ->assertHasErrors(['company_reg_no', 'contact_person']);
});

it('rejects a duplicate national ID (FR-TEN-04)', function () {
    Tenant::factory()->create(['national_id' => 'A118342']);

    actingAs(registryUser('land_officer'));

    Livewire::test(TenantsIndex::class)
        ->call('create')
        ->set('type', 'individual')
        ->set('name', 'Someone Else')
        ->set('national_id', 'A118342')
        ->set('mobile', '+9607773000')
        ->call('save')
        ->assertHasErrors(['national_id']);
});

it('filters and paginates the tenant list', function () {
    foreach (range(1, 12) as $i) {
        Tenant::factory()->create(['name' => sprintf('Tenant %02d', $i)]);
    }
    $org = Tenant::factory()->organisation()->create(['name' => 'Zeta Holdings', 'company_reg_no' => 'C-500/2019']);

    actingAs(registryUser('land_officer'));

    Livewire::test(TenantsIndex::class)
        // 13 tenants, 10 per page, ordered by name.
        ->assertSee('Showing 1–10 of 13')
        ->assertDontSee('Zeta Holdings')
        ->call('gotoPage', 2)
        ->assertSee('Showing 11–13 of 13')
        ->assertSee('Zeta Holdings')
        // Type chip resets to page 1.
        ->set('typeFilter', 'organisation')
        ->assertSee('Showing 1–1 of 1')
        ->assertSee('Zeta Holdings')
        // Search by registry number.
        ->call('clearFilters')
        ->set('q', 'C-500/2019')
        ->assertSee('Zeta Holdings')
        ->assertDontSee('Tenant 01');
});

it('creates an individual tenant', function () {
    actingAs(registryUser('land_officer'));

    Livewire::test(TenantsIndex::class)
        ->call('create')
        ->set('type', 'individual')
        ->set('name', 'Ahmed Nasheed')
        ->set('national_id', 'A222333')
        ->set('mobile', '+9607774000')
        ->call('save')
        ->assertHasNoErrors();

    expect(Tenant::where('national_id', 'A222333')->exists())->toBeTrue();
});

// --- Leases -----------------------------------------------------------------

it('creates a draft lease', function () {
    actingAs(registryUser('land_officer'));
    $property = Property::factory()->create();
    $tenant = Tenant::factory()->create();

    Livewire::test(LeasesIndex::class)
        ->call('create')
        ->set('agreement_number', 'AG-7001/2026')
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
        ->set('status', 'draft')
        ->call('save')
        ->assertHasNoErrors();

    expect(Lease::where('agreement_number', 'AG-7001/2026')->exists())->toBeTrue();
});

it('blocks activating a lease on a parcel that already has an active lease', function () {
    actingAs(registryUser('land_officer'));
    $property = Property::factory()->create();
    Lease::factory()->active()->create(['property_id' => $property->id]);
    $tenant = Tenant::factory()->create();

    Livewire::test(LeasesIndex::class)
        ->call('create')
        ->set('agreement_number', 'AG-7002/2026')
        ->set('property_id', $property->id)
        ->set('tenant_id', $tenant->id)
        ->set('agreement_date', '2026-01-01')
        ->set('start_date', '2026-01-01')
        ->set('rent_start_date', '2026-01-01')
        ->set('duration_years', 10)
        ->set('expiry_date', '2036-01-01')
        ->set('rent_basis', 'flat')
        ->set('flat_amount', '500.00')
        ->set('status', 'active')
        ->call('save')
        ->assertHasErrors(['property_id']);

    expect(Lease::where('agreement_number', 'AG-7002/2026')->exists())->toBeFalse();
});

it('only lets a supervisor terminate a lease directly', function () {
    $lease = Lease::factory()->active()->create();

    $supervisor = registryUser('supervisor');
    $landOfficer = registryUser('land_officer');

    expect($supervisor->can('terminate', $lease))->toBeTrue()
        ->and($landOfficer->can('terminate', $lease))->toBeFalse();

    actingAs($supervisor);
    Livewire::test(LeasesIndex::class)
        ->call('startTerminate', $lease->id)
        ->set('termination_reason', 'Tenant vacated the premises.')
        ->call('confirmTerminate')
        ->assertHasNoErrors();

    expect($lease->fresh()->status)->toBe(LeaseStatus::Terminated);
});
