<?php

declare(strict_types=1);

use App\Enums\LeaseStatus;
use App\Exceptions\ParcelAlreadyLeasedException;
use App\Models\Lease;
use App\Models\Property;
use Illuminate\Database\QueryException;

use function Pest\Laravel\artisan;

it('enforces a unique agreement number (FR-LSE-06)', function () {
    Lease::factory()->create(['agreement_number' => 'AG-0001/2026']);

    expect(fn () => Lease::factory()->create(['agreement_number' => 'AG-0001/2026']))
        ->toThrow(QueryException::class);
});

it('refuses a second active lease on the same parcel (FR-PRP-02)', function () {
    $property = Property::factory()->create();
    Lease::factory()->active()->create(['property_id' => $property->id]);

    expect(fn () => Lease::factory()->active()->create(['property_id' => $property->id]))
        ->toThrow(ParcelAlreadyLeasedException::class);
});

it('allows multiple non-active leases on the same parcel', function () {
    $property = Property::factory()->create();

    Lease::factory()->create(['property_id' => $property->id, 'status' => LeaseStatus::Draft->value]);
    Lease::factory()->create(['property_id' => $property->id, 'status' => LeaseStatus::Draft->value]);

    expect(Lease::where('property_id', $property->id)->count())->toBe(2);
});

it('allows an active lease on a parcel whose previous lease is terminated', function () {
    $property = Property::factory()->create();
    Lease::factory()->create(['property_id' => $property->id, 'status' => LeaseStatus::Terminated->value]);

    $active = Lease::factory()->active()->create(['property_id' => $property->id]);

    expect($active->isActive())->toBeTrue();
});

it('auto-expires active leases past their expiry date (FR-LSE-03)', function () {
    $expiring = Lease::factory()->pastExpiry()->create();
    $current = Lease::factory()->active()->create();
    $draftPast = Lease::factory()->create([
        'status' => LeaseStatus::Draft->value,
        'expiry_date' => now()->subDay()->toDateString(),
    ]);

    artisan('leases:expire')->assertSuccessful();

    expect($expiring->fresh()->status)->toBe(LeaseStatus::Expired)
        ->and($current->fresh()->status)->toBe(LeaseStatus::Active)
        ->and($draftPast->fresh()->status)->toBe(LeaseStatus::Draft);
});

it('computes monthly rent from the rent basis', function () {
    // 2,000 ft² × 53 laari = MVR 1,060.00
    $perSqft = Lease::factory()->create(['rate_laari' => 53, 'area_sqft' => 2000]);
    $flat = Lease::factory()->flat(50_000)->create();

    expect($perSqft->monthlyRent()->format())->toBe('MVR 1,060.00')
        ->and($flat->monthlyRent()->format())->toBe('MVR 500.00');
});
