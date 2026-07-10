<?php

declare(strict_types=1);

use App\Enums\LeaseStatus;
use App\Enums\TenantType;
use App\Models\FineRule;
use App\Models\Lease;
use App\Models\Property;
use App\Models\Tenant;
use App\Services\Import\ImportReport;
use App\Services\Import\RegisterImporter;

use function Pest\Laravel\artisan;

function fixturePath(): string
{
    return base_path('tests/Fixtures/register-sample.csv');
}

function runImport(bool $dryRun = false): ImportReport
{
    return app(RegisterImporter::class)->import(fixturePath(), $dryRun);
}

it('imports the sample register with correct records and amounts', function () {
    $report = runImport();

    // 6 data rows: 3 imported, 3 rejected.
    expect($report->rowsProcessed)->toBe(6)
        ->and($report->imported())->toBe(3)
        ->and($report->entities['properties'])->toBe(['created' => 3, 'updated' => 0])
        ->and($report->entities['tenants'])->toBe(['created' => 3, 'updated' => 0])
        ->and($report->entities['leases'])->toBe(['created' => 3, 'updated' => 0])
        ->and($report->entities['fine_rules'])->toBe(['created' => 2, 'updated' => 0]);

    // The Dhiraagu per-ft² example: 2,000 ft² × 53 laari = MVR 1,060, area
    // defaulted from the parcel size, expiry computed from start + duration.
    $antenna = Lease::where('agreement_number', 'AG-IMP-001')->first();

    expect($antenna->monthlyRent()->format())->toBe('MVR 1,060.00')
        ->and($antenna->area_sqft)->toBe(2000)
        ->and($antenna->expiry_date->toDateString())->toBe('2034-01-01')
        ->and($antenna->csr_amount_laari)->toBe(900_000)
        ->and($antenna->tenant->type)->toBe(TenantType::Organisation)
        ->and($antenna->tenant->company_reg_no)->toBe('C-250/2002');

    // The ABID flat-rent lease: d/m/Y date parsed, individual inferred,
    // 0.5%/day fine imported as 50 bps.
    $shop = Lease::where('agreement_number', 'AG-IMP-002')->first();

    expect($shop->monthlyRent()->format())->toBe('MVR 500.00')
        ->and($shop->start_date->toDateString())->toBe('2021-06-01')
        ->and($shop->tenant->type)->toBe(TenantType::Individual)
        ->and($shop->tenant->national_id)->toBe('A118342')
        ->and($shop->fineRules()->first()->percent_daily_bps)->toBe(50)
        ->and($shop->notes)->toContain('0.5%/day');

    // Terminated status and notes carried over.
    $cafe = Lease::where('agreement_number', 'AG-IMP-003')->first();

    expect($cafe->status)->toBe(LeaseStatus::Terminated)
        ->and($cafe->notes)->toBe('Vacated 2023');
});

it('reports the reconciliation totals and rejected rows with reasons', function () {
    $report = runImport();

    // 1,060 + 500 + 750 = MVR 2,310 monthly rent to tick against the workbook.
    expect($report->monthlyRent()->format())->toBe('MVR 2,310.00')
        ->and($report->rejected)->toHaveCount(3);

    $reasonsByReference = collect($report->rejected)->keyBy('reference');

    expect(implode(' ', $reasonsByReference['AG-IMP-004']['reasons']))->toContain('land_number is required')
        ->and(implode(' ', $reasonsByReference['AG-IMP-005']['reasons']))->toContain('already has another active lease')
        ->and(implode(' ', $reasonsByReference['AG-IMP-006']['reasons']))->toContain('not a recognisable date');
});

it('leaves nothing behind from rejected rows', function () {
    runImport();

    // The parcel-conflict row (AG-IMP-005) must not have created its tenant.
    expect(Tenant::where('company_reg_no', 'C-999/2010')->exists())->toBeFalse()
        ->and(Lease::where('agreement_number', 'AG-IMP-005')->exists())->toBeFalse()
        ->and(Property::where('land_number', 'L-9106')->exists())->toBeFalse();
});

it('writes nothing in dry-run mode but reports the same outcome', function () {
    $report = runImport(dryRun: true);

    expect($report->dryRun)->toBeTrue()
        ->and($report->imported())->toBe(3)
        ->and($report->rejected)->toHaveCount(3)
        ->and($report->monthlyRent()->format())->toBe('MVR 2,310.00')
        ->and(Property::count())->toBe(0)
        ->and(Tenant::count())->toBe(0)
        ->and(Lease::count())->toBe(0)
        ->and(FineRule::count())->toBe(0);
});

it('is re-runnable without duplicating records', function () {
    runImport();
    $second = runImport();

    expect($second->entities['leases'])->toBe(['created' => 0, 'updated' => 3])
        ->and($second->entities['properties'])->toBe(['created' => 0, 'updated' => 3])
        ->and($second->entities['tenants'])->toBe(['created' => 0, 'updated' => 3])
        ->and($second->entities['fine_rules'])->toBe(['created' => 0, 'updated' => 0]) // rules never stack
        ->and(Lease::count())->toBe(3)
        ->and(Property::count())->toBe(3)
        ->and(Tenant::count())->toBe(3)
        ->and(FineRule::count())->toBe(2);
});

it('runs through the artisan command with a printed report', function () {
    artisan('import:register', ['file' => fixturePath(), '--dry-run' => true])
        ->expectsOutputToContain('DRY RUN')
        ->expectsOutputToContain('Monthly rent of imported leases: MVR 2,310.00')
        ->expectsOutputToContain('Rejected rows')
        ->assertSuccessful();

    expect(Lease::count())->toBe(0);

    artisan('import:register', ['file' => fixturePath()])->assertSuccessful();

    expect(Lease::count())->toBe(3);
});

it('fails cleanly when the file does not exist', function () {
    artisan('import:register', ['file' => '/nonexistent.csv'])->assertFailed();
});
