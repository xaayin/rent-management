<?php

declare(strict_types=1);

use App\Enums\CsrType;
use App\Enums\LeaseStatus;
use App\Enums\RentBasis;
use App\Enums\TenantType;
use App\Models\Lease;
use App\Models\Property;
use App\Models\Tenant;
use App\Services\Import\ImportReport;
use App\Services\Import\RegisterImporter;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;

/**
 * Builds a miniature copy of the real "Kuli Binthakuge Dhaftaru" workbook —
 * right-to-left Thaana columns, headers on rows 8–9, data from row 10 — and
 * runs it through the importer.
 */
function workbookFixture(): string
{
    $path = sys_get_temp_dir().'/register-fixture-'.uniqid().'.xlsx';

    // Column indices: A=0 notes, B=1 status, C=2 CSR, D=3 pay terms, E=4 rent,
    // F=5 plot no, G=6 size, H=7 rate, I=8 rent start, J=9 expiry, K=10 leased,
    // L=11 duration, M=12 agreement, N=13 email, O=14 phone, P=15 registry,
    // Q=16 tenant, R=17 property.
    $make = function (array $cells): array {
        $row = array_fill(0, 19, '');

        foreach ($cells as $index => $value) {
            $row[$index] = $value;
        }

        return $row;
    };

    $payTerms = 'ކޮންމެ މަހެއްގެ 10 ވަނަ ދުވަހުގެ ކުރިން';

    $rows = [];

    // Rows 1–9: title/blank/header filler so data starts at row 10.
    for ($i = 1; $i <= 9; $i++) {
        $rows[] = $make([]);
    }

    // R10 — the Dhiraagu example: 53 laari/ft² × 2,000 ft², organisation.
    $rows[] = $make([
        1 => 'Active', 3 => $payTerms, 4 => 1060,
        6 => '2000 އަކަފޫޓް', 7 => 'އަކަފޫޓަކަށް 53 ލާރި',
        8 => '01 ޖެނުއަރީ 2022', 9 => '31 ޑިސެމްބަރ 2032', 10 => '01 ޖެނުއަރީ 2022',
        11 => '10އަހަރަށް', 12 => 'TEST-DHR-1', 14 => '3323800',
        15 => 'C-0024/1988', 16 => 'ދިރާގު', 17 => 'ދިރާގު އެންޓަނާ',
    ]);

    // R11 — decimal area (3,332.94 ft²) → must import as flat MVR 1,766.46.
    $rows[] = $make([
        1 => 'Active', 3 => $payTerms, 4 => 1766.46,
        6 => ' 3332.94 އަކަފޫޓް', 7 => 'އަކަފޫޓަކަށް 53 ލާރި',
        8 => '01 އޭޕްރީލް 2021', 9 => '26 ޖޫން 2035', 10 => '01 އޭޕްރީލް 2021',
        11 => '15އަހަރަށް', 12 => 'TEST-OM-1', 13 => 'legal@ooredoo.mv', 14 => '9611000',
        15 => 'C-0633/2004', 16 => 'އުރީދޫ މޯލްޑިވްސް', 17 => 'އުރީދޫ އެންޓަނާ',
    ]);

    // R12 — grace (leased Aug 2024, rent starts Feb 2025), CSR 1% of income,
    // no registry number (tenant keyed by name + phone).
    $rows[] = $make([
        1 => 'Active', 2 => 'އާމްދަނީގެ 1%', 3 => $payTerms, 4 => 750,
        6 => '1500 އަކަފޫޓް', 7 => 'އަކަފޫޓަކަށް 50 ލާރި',
        8 => '15ފެބްރުއަރީ 2025', 9 => '14އޮގަސްޓް 2049', 10 => '15އޮގަސްޓް 2024',
        11 => '25އަހަރަށް', 12 => 'TEST-AFF-1', 14 => '7774295',
        16 => 'އަފީފު މުޙައްމަދު', 17 => 'ވިޔަފާރި ބިން',
    ]);

    // R13 — flat monthly rate, terminated, fixed-annual CSR, notes.
    $rows[] = $make([
        0 => 'ބިން ދޫކޮށްލުން', 1 => 'TERMINTED', 2 => 'އަހަރަކު 9000 ރ', 3 => $payTerms, 4 => 1500,
        6 => '3000 އަކަފޫޓް', 7 => '1500 މަހަކަށް',
        8 => '08 ސެޕްޓެމްބަރ 2024', 9 => '6 މާރިޗް 2034', 10 => '7 މާރިޗް 2024',
        11 => '10އަހަރަށް', 12 => 'TEST-NSH-1', 14 => '7778888',
        15 => 'A309131', 16 => 'ނައުޝޫދު ވަހީދު', 17 => 'ސައި ހޮޓާ ބިން',
    ]);

    // R14 — same name/size as R10's parcel (no plot number), both active →
    // must split into a separate parcel; same tenant as R12 by name + phone.
    $rows[] = $make([
        1 => 'Active', 3 => $payTerms, 4 => 1060,
        6 => '2000 އަކަފޫޓް', 7 => 'އަކަފޫޓަކަށް 53 ލާރި',
        8 => '10 ޑިސެމަބަރ 2024', 9 => '10 ޑިސެމަބަރ 2039', 10 => '10 ޑިސެމަބަރ 2024',
        11 => '15އަހަރަށް', 12 => 'TEST-AFF-2', 14 => '7774295',
        16 => 'އަފީފު މުޙައްމަދު', 17 => 'ދިރާގު އެންޓަނާ',
    ]);

    // R15 — the register's "ditto" habit: agreement only, names blank → reject.
    $rows[] = $make([1 => 'Active', 3 => $payTerms, 4 => 1000, 12 => 'TEST-DITTO-1']);

    $writer = new Writer;
    $writer->openToFile($path);

    foreach ($rows as $cells) {
        $writer->addRow(Row::fromValues($cells));
    }

    $writer->close();

    return $path;
}

function importWorkbook(): ImportReport
{
    return app(RegisterImporter::class)->import(workbookFixture());
}

it('imports the Thaana workbook layout with correct money, dates and types', function () {
    $report = importWorkbook();

    expect($report->rowsProcessed)->toBe(6)
        ->and($report->imported())->toBe(5)
        ->and($report->rejected)->toHaveCount(1)
        ->and($report->rejected[0]['line'])->toBe(15)
        ->and($report->monthlyRent()->format())->toBe('MVR 6,136.46');

    // Dhiraagu: per-ft² preserved, Thaana dates translated, org inferred.
    $dhiraagu = Lease::where('agreement_number', 'TEST-DHR-1')->first();

    expect($dhiraagu->rent_basis)->toBe(RentBasis::PerSquareFoot)
        ->and($dhiraagu->rate_laari)->toBe(53)
        ->and($dhiraagu->area_sqft)->toBe(2000)
        ->and($dhiraagu->monthlyRent()->format())->toBe('MVR 1,060.00')
        ->and($dhiraagu->start_date->toDateString())->toBe('2022-01-01')
        ->and($dhiraagu->expiry_date->toDateString())->toBe('2032-12-31')
        ->and($dhiraagu->due_day)->toBe(10)
        ->and($dhiraagu->tenant->type)->toBe(TenantType::Organisation)
        ->and($dhiraagu->property->usage_type->value)->toBe('telecom_antenna');

    // Ooredoo: decimal area cannot be integer per-ft² → exact flat rent.
    $ooredoo = Lease::where('agreement_number', 'TEST-OM-1')->first();

    expect($ooredoo->rent_basis)->toBe(RentBasis::Flat)
        ->and($ooredoo->flat_amount_laari)->toBe(176_646)
        ->and($ooredoo->monthlyRent()->format())->toBe('MVR 1,766.46');

    // Grace + percent-of-revenue CSR.
    $afeef = Lease::where('agreement_number', 'TEST-AFF-1')->first();

    expect($afeef->grace_months)->toBe(6)
        ->and($afeef->csr_type)->toBe(CsrType::PercentOfRevenue)
        ->and($afeef->csr_percent_bps)->toBe(100)
        ->and($afeef->start_date->toDateString())->toBe('2024-08-15')
        ->and($afeef->rent_start_date->toDateString())->toBe('2025-02-15');

    // Flat monthly rate + terminated + fixed CSR + notes + tea-shop type.
    $naushood = Lease::where('agreement_number', 'TEST-NSH-1')->first();

    expect($naushood->rent_basis)->toBe(RentBasis::Flat)
        ->and($naushood->flat_amount_laari)->toBe(150_000)
        ->and($naushood->status)->toBe(LeaseStatus::Terminated)
        ->and($naushood->csr_type)->toBe(CsrType::FixedAnnual)
        ->and($naushood->csr_amount_laari)->toBe(900_000)
        ->and($naushood->notes)->toBe('ބިން ދޫކޮށްލުން')
        ->and($naushood->property->usage_type->value)->toBe('tea_shop');
});

it('splits ambiguous parcels instead of rejecting and reuses tenants by name and phone', function () {
    $report = importWorkbook();

    // R10 and R14 share a property name + size with no plot number: two
    // distinct parcels, both leases active.
    $split = Lease::where('agreement_number', 'TEST-AFF-2')->first();
    $original = Lease::where('agreement_number', 'TEST-DHR-1')->first();

    expect($split->status)->toBe(LeaseStatus::Active)
        ->and($split->property_id)->not->toBe($original->property_id)
        ->and(collect($report->warnings)->pluck('message')->implode(' '))->toContain('separate parcel');

    // R12 and R14 (no registry number) matched by name + phone → one tenant.
    expect(Tenant::where('name', 'އަފީފު މުޙައްމަދު')->count())->toBe(1)
        ->and($split->tenant_id)->toBe(Lease::where('agreement_number', 'TEST-AFF-1')->first()->tenant_id);

    // Data-quality gaps arrive as warnings, not rejections.
    $messages = collect($report->warnings)->pluck('message')->implode(' ');

    expect($messages)->toContain('No registry number')
        ->and($messages)->toContain('declared revenue');
});

it('re-imports the workbook without duplicating anything', function () {
    importWorkbook();

    $before = [Property::count(), Tenant::count(), Lease::count()];
    $second = app(RegisterImporter::class)->import(workbookFixture());

    expect($second->entities['leases'])->toBe(['created' => 0, 'updated' => 5])
        ->and([Property::count(), Tenant::count(), Lease::count()])->toBe($before);
});
