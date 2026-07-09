<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\CsrType;
use App\Enums\FineBase;
use App\Enums\FineMethod;
use App\Enums\LeaseStatus;
use App\Enums\PropertyStatus;
use App\Enums\RentBasis;
use App\Enums\TenantType;
use App\Enums\UsageType;
use App\Models\FineRule;
use App\Models\Lease;
use App\Models\Property;
use App\Models\Tenant;
use App\Support\Money;
use Illuminate\Database\Seeder;

/**
 * A handful of records mirroring the PRD's worked examples so the registry is
 * not empty on first run.
 */
class DemoRegistrySeeder extends Seeder
{
    public function run(): void
    {
        // The Dhiraagu antenna example: 2,000 ft² × 53 laari = MVR 1,060/month.
        $antennaSite = Property::create([
            'name' => 'Dhiraagu Antenna Site',
            'land_number' => 'L-2001',
            'size_sqft' => 2000,
            'usage_type' => UsageType::TelecomAntenna->value,
            'location_notes' => 'North shore, adjacent to the harbour road.',
            'status' => PropertyStatus::Active->value,
        ]);

        $shop = Property::create([
            'name' => 'Boduthakurufaanu Magu Shop',
            'land_number' => 'L-3050',
            'size_sqft' => 800,
            'usage_type' => UsageType::Commercial->value,
            'location_notes' => 'Ground-floor unit on the main road.',
            'status' => PropertyStatus::Active->value,
        ]);

        Property::create([
            'name' => 'Reclaim Area Vacant Plot',
            'land_number' => 'L-4100',
            'size_sqft' => 3500,
            'usage_type' => UsageType::VacantLand->value,
            'location_notes' => 'Unallocated; available for lease.',
            'status' => PropertyStatus::Active->value,
        ]);

        $dhiraagu = Tenant::create([
            'type' => TenantType::Organisation->value,
            'name' => 'Dhiraagu PLC',
            'company_reg_no' => 'C-250/2002',
            'contact_person' => 'Aishath Nadhiya',
            'mobile' => '+9607771234',
            'email' => 'leases@dhiraagu.example',
            'postal_address' => 'Dhiraagu HQ, Malé',
        ]);

        $abid = Tenant::create([
            'type' => TenantType::Individual->value,
            'name' => 'Abid Ibrahim',
            'national_id' => 'A118342',
            'mobile' => '+9607789900',
            'email' => null,
            'postal_address' => 'Malé City',
        ]);

        $antennaLease = Lease::create([
            'agreement_number' => 'AG-0012/2019',
            'property_id' => $antennaSite->id,
            'tenant_id' => $dhiraagu->id,
            'agreement_date' => '2018-12-15',
            'start_date' => '2019-01-01',
            'rent_start_date' => '2019-01-01',
            'duration_years' => 15,
            'expiry_date' => '2034-01-01',
            'rent_basis' => RentBasis::PerSquareFoot->value,
            'rate_laari' => 53,
            'area_sqft' => 2000,
            'due_day' => 10,
            // CSR: a fixed MVR 9,000/year, billed each January.
            'csr_type' => CsrType::FixedAnnual->value,
            'csr_amount_laari' => Money::fromRufiyaa(9000)->laari,
            'csr_month' => 1,
            'status' => LeaseStatus::Active->value,
        ]);

        $shopLease = Lease::create([
            'agreement_number' => 'AG-0044/2021',
            'property_id' => $shop->id,
            'tenant_id' => $abid->id,
            'agreement_date' => '2021-05-20',
            'start_date' => '2021-06-01',
            'rent_start_date' => '2021-06-01',
            'duration_years' => 10,
            'expiry_date' => '2031-06-01',
            'rent_basis' => RentBasis::Flat->value,
            'flat_amount_laari' => Money::fromRufiyaa(500)->laari,
            'status' => LeaseStatus::Active->value,
        ]);

        // Fine rules mirroring the PRD worked examples: the antenna lease uses
        // the tiered defaults (MVR 100 / MVR 50); the ABID shop lease uses
        // 0.5%/day of rent, as in the current ledger.
        FineRule::create([
            'lease_id' => $antennaLease->id,
            'method' => FineMethod::TieredMonthly->value,
            'base' => FineBase::Rent->value,
            'first_month_laari' => FineRule::DEFAULT_FIRST_MONTH_LAARI,
            'subsequent_month_laari' => FineRule::DEFAULT_SUBSEQUENT_MONTH_LAARI,
            'effective_from' => '2019-01-01',
        ]);

        FineRule::create([
            'lease_id' => $shopLease->id,
            'method' => FineMethod::PercentPerDay->value,
            'base' => FineBase::Rent->value,
            'percent_daily_bps' => 50,
            'effective_from' => '2021-06-01',
        ]);
    }
}
