<?php

declare(strict_types=1);

namespace App\Services\Import;

use App\Enums\FineMethod;
use App\Enums\LeaseStatus;
use App\Enums\RentBasis;
use App\Enums\TenantType;
use App\Enums\UsageType;
use App\Exceptions\ParcelAlreadyLeasedException;
use App\Models\FineRule;
use App\Models\Lease;
use App\Models\Property;
use App\Models\Tenant;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Throwable;

/**
 * Imports the council's lease register from a CSV export of the current
 * workbook, mapped per PRD Appendix A. Re-runnable: rows upsert by their
 * natural keys (land number, registry number, agreement number), so a re-run
 * updates rather than duplicates. Each row is its own savepoint — a rejected
 * row leaves nothing behind and the run continues.
 *
 * Expected header (order-independent; unknown columns ignored):
 *   property_name*, land_number*, size_sqft*, usage_type,
 *   tenant_name*, registry_no*, mobile*, email, contact_person,
 *   agreement_number*, agreement_date, start_date*, rent_start_date,
 *   duration_years*, expiry_date, rent_basis* (per_sqft|flat),
 *   rate_laari, area_sqft, flat_rent_mvr, due_day, grace_months,
 *   csr_type (none|fixed_annual), csr_amount_mvr, csr_month,
 *   fine_method (flat_per_day|percent_per_day|tiered_monthly), fine_rate,
 *   status (active|terminated|draft), notes
 */
class RegisterImporter
{
    private const array DATE_FORMATS = ['Y-m-d', 'd/m/Y', 'd-m-Y'];

    public function __construct(private readonly WorkbookRegisterReader $workbook) {}

    public function import(string $path, bool $dryRun = false): ImportReport
    {
        if (! is_readable($path)) {
            throw new InvalidArgumentException("Cannot read import file [{$path}].");
        }

        // The council's own workbook imports directly; CSV remains for
        // cleaned exports.
        $rows = strtolower(pathinfo($path, PATHINFO_EXTENSION)) === 'xlsx'
            ? $this->workbook->rows($path)
            : $this->parse($path);

        $report = new ImportReport;
        $report->dryRun = $dryRun;

        DB::beginTransaction();

        try {
            foreach ($rows as $line => $row) {
                $report->rowsProcessed++;

                try {
                    // Row-level savepoint: rejection rolls back only this row.
                    // Counts apply to the report only after the row commits.
                    $pending = DB::transaction(fn () => $this->importRow($row));

                    foreach ($pending['counts'] as [$entity, $created]) {
                        $report->count($entity, $created);
                    }

                    $report->monthlyRentLaari += $pending['rent_laari'];

                    foreach ([...$pending['warnings'], ...$this->rowWarnings($row)] as $warning) {
                        $report->warn($line, $this->reference($row), $warning);
                    }
                } catch (ImportRowException $e) {
                    $report->reject($line, $this->reference($row), $e->reasons);
                } catch (ParcelAlreadyLeasedException) {
                    $report->reject($line, $this->reference($row), ['This parcel already has another active lease.']);
                } catch (Throwable $e) {
                    $report->reject($line, $this->reference($row), [$e->getMessage()]);
                }
            }

            $dryRun ? DB::rollBack() : DB::commit();
        } catch (Throwable $e) {
            DB::rollBack();

            throw $e;
        }

        return $report;
    }

    /**
     * @param  array<string, string>  $row
     * @return array{counts: list<array{0: string, 1: bool}>, rent_laari: int, warnings: list<string>}
     */
    private function importRow(array $row): array
    {
        $reasons = $this->validate($row);

        if ($reasons !== []) {
            throw new ImportRowException($reasons);
        }

        $counts = [];
        $warnings = [];

        $startDate = $this->date($row['start_date']);
        $rentStart = $row['rent_start_date'] !== '' ? $this->date($row['rent_start_date']) : $startDate;
        $agreementDate = $row['agreement_date'] !== '' ? $this->date($row['agreement_date']) : $startDate;
        $duration = (int) $row['duration_years'];
        $expiry = $row['expiry_date'] !== ''
            ? $this->date($row['expiry_date'])
            : $startDate->addYears($duration);

        $landNumber = trim($row['land_number']);
        $agreementNumber = trim($row['agreement_number']);
        $status = $row['status'] !== '' ? $row['status'] : LeaseStatus::Active->value;

        // Workbook rows may share a synthesised parcel identity even though
        // they are physically distinct plots. When both would be active, split
        // into a separate parcel instead of tripping the FR-PRP-02 guard.
        if (($row['parcel_split_ok'] ?? '') === '1' && $status === LeaseStatus::Active->value) {
            $existingParcel = Property::query()->where('land_number', $landNumber)->first();

            $clash = $existingParcel?->activeLeases()
                ->where('agreement_number', '!=', $agreementNumber)
                ->exists() ?? false;

            if ($clash) {
                $landNumber .= ' · '.$agreementNumber;
                $warnings[] = 'Shares a name/size with another active lease — imported as a separate parcel; assign real land numbers in the app.';
            }
        }

        $property = Property::updateOrCreate(
            ['land_number' => $landNumber],
            [
                'name' => trim($row['property_name']),
                'size_sqft' => (int) $row['size_sqft'],
                'usage_type' => $this->usageType($row['usage_type'])->value,
                'location_notes' => null,
            ],
        );
        $counts[] = ['properties', $property->wasRecentlyCreated];

        $tenant = $this->upsertTenant($row);
        $counts[] = ['tenants', $tenant->wasRecentlyCreated];

        $isPerSqft = $row['rent_basis'] === RentBasis::PerSquareFoot->value;

        $lease = Lease::updateOrCreate(
            ['agreement_number' => trim($row['agreement_number'])],
            [
                'property_id' => $property->id,
                'tenant_id' => $tenant->id,
                'agreement_date' => $agreementDate->toDateString(),
                'start_date' => $startDate->toDateString(),
                'rent_start_date' => $rentStart->toDateString(),
                'duration_years' => $duration,
                'expiry_date' => $expiry->toDateString(),
                'rent_basis' => $row['rent_basis'],
                'rate_laari' => $isPerSqft ? (int) $row['rate_laari'] : null,
                'area_sqft' => $isPerSqft
                    ? (int) ($row['area_sqft'] !== '' ? $row['area_sqft'] : $row['size_sqft'])
                    : null,
                'flat_amount_laari' => $isPerSqft ? null : Money::fromRufiyaa($row['flat_rent_mvr'])->laari,
                'due_day' => $row['due_day'] !== '' ? (int) $row['due_day'] : 10,
                'grace_months' => $row['grace_months'] !== '' ? (int) $row['grace_months'] : 0,
                'csr_type' => $row['csr_type'] !== '' ? $row['csr_type'] : 'none',
                'csr_amount_laari' => $row['csr_type'] === 'fixed_annual' && $row['csr_amount_mvr'] !== ''
                    ? Money::fromRufiyaa($row['csr_amount_mvr'])->laari
                    : null,
                'csr_percent_bps' => $row['csr_type'] === 'percent_revenue' && ($row['csr_percent'] ?? '') !== ''
                    ? (int) round(((float) $row['csr_percent']) * 100)
                    : null,
                'csr_month' => $row['csr_month'] !== '' ? (int) $row['csr_month'] : null,
                'status' => $status,
                'notes' => $row['notes'] !== '' ? $row['notes'] : null,
            ],
        );
        $counts[] = ['leases', $lease->wasRecentlyCreated];

        if ($this->upsertFineRule($row, $lease, $rentStart)) {
            $counts[] = ['fine_rules', true];
        }

        return ['counts' => $counts, 'rent_laari' => $lease->monthlyRent()->laari, 'warnings' => $warnings];
    }

    /**
     * @param  array<string, string>  $row
     */
    private function upsertTenant(array $row): Tenant
    {
        $registry = trim($row['registry_no']);

        // Individuals carry a national ID like A118342; a C-… registration is
        // an organisation (PRD §1.2). Rows without any registry number — a
        // real gap in the register — are treated as individuals keyed by
        // name + phone so re-runs still match the same person.
        $isIndividual = $registry === '' || preg_match('/^A\d+$/i', $registry) === 1;

        $key = match (true) {
            $registry !== '' && $isIndividual => ['national_id' => $registry],
            $registry !== '' => ['company_reg_no' => $registry],
            default => ['name' => trim($row['tenant_name']), 'mobile' => trim($row['mobile'])],
        };

        return Tenant::updateOrCreate($key, [
            'type' => ($isIndividual ? TenantType::Individual : TenantType::Organisation)->value,
            'name' => trim($row['tenant_name']),
            'contact_person' => $isIndividual ? null : ($row['contact_person'] !== '' ? $row['contact_person'] : null),
            'mobile' => trim($row['mobile']),
            'email' => $row['email'] !== '' ? trim($row['email']) : null,
        ]);
    }

    /**
     * The register's fine header maps to a FineRule (Appendix A). Created only
     * when the lease has no rule yet, so re-runs never stack duplicates.
     *
     * @param  array<string, string>  $row
     */
    private function upsertFineRule(array $row, Lease $lease, CarbonImmutable $effectiveFrom): bool
    {
        if ($row['fine_method'] === '' || $lease->fineRules()->exists()) {
            return false;
        }

        $method = FineMethod::from($row['fine_method']);

        FineRule::create([
            'lease_id' => $lease->id,
            'method' => $method->value,
            'base' => 'rent',
            'flat_daily_laari' => $method === FineMethod::FlatPerDay
                ? Money::fromRufiyaa($row['fine_rate'])->laari
                : null,
            'percent_daily_bps' => $method === FineMethod::PercentPerDay
                ? (int) round(((float) $row['fine_rate']) * 100)
                : null,
            'first_month_laari' => $method === FineMethod::TieredMonthly ? FineRule::DEFAULT_FIRST_MONTH_LAARI : null,
            'subsequent_month_laari' => $method === FineMethod::TieredMonthly ? FineRule::DEFAULT_SUBSEQUENT_MONTH_LAARI : null,
            'effective_from' => $effectiveFrom->toDateString(),
        ]);

        return true;
    }

    /**
     * @param  array<string, string>  $row
     * @return list<string>
     */
    private function validate(array $row): array
    {
        $reasons = [];

        // Registry number and mobile are real gaps in the current workbook —
        // they import with warnings rather than rejections (see rowWarnings).
        foreach (['property_name', 'land_number', 'size_sqft', 'tenant_name', 'agreement_number', 'start_date', 'duration_years', 'rent_basis'] as $field) {
            if (trim($row[$field]) === '') {
                $reasons[] = "{$field} is required";
            }
        }

        if ($row['mobile'] !== '' && preg_match('/^\+?[0-9 ]{7,20}$/', trim($row['mobile'])) !== 1) {
            $reasons[] = 'mobile is not a valid phone number';
        }

        if ($row['rent_basis'] !== '' && ! in_array($row['rent_basis'], [RentBasis::PerSquareFoot->value, RentBasis::Flat->value], true)) {
            $reasons[] = 'rent_basis must be per_sqft or flat';
        }

        if ($row['rent_basis'] === RentBasis::PerSquareFoot->value && (int) $row['rate_laari'] < 1) {
            $reasons[] = 'rate_laari is required for per_sqft leases';
        }

        if ($row['rent_basis'] === RentBasis::Flat->value
            && preg_match('/^\d+(\.\d{1,2})?$/', $row['flat_rent_mvr']) !== 1) {
            $reasons[] = 'flat_rent_mvr must be a decimal MVR amount';
        }

        foreach (['start_date', 'rent_start_date', 'agreement_date', 'expiry_date'] as $field) {
            if ($row[$field] !== '' && $this->tryDate($row[$field]) === null) {
                $reasons[] = "{$field} is not a recognisable date";
            }
        }

        if ($row['status'] !== '' && ! in_array($row['status'], array_column(LeaseStatus::cases(), 'value'), true)) {
            $reasons[] = 'status must be one of draft, active, terminated, expired';
        }

        if ($row['fine_method'] !== '') {
            if (FineMethod::tryFrom($row['fine_method']) === null) {
                $reasons[] = 'fine_method must be flat_per_day, percent_per_day or tiered_monthly';
            } elseif ($row['fine_method'] !== FineMethod::TieredMonthly->value
                && preg_match('/^\d+(\.\d+)?$/', $row['fine_rate']) !== 1) {
                $reasons[] = 'fine_rate is required for the chosen fine_method';
            }
        }

        return $reasons;
    }

    /**
     * Data-quality gaps that import fine but need cleaning in the app
     * afterwards.
     *
     * @param  array<string, string>  $row
     * @return list<string>
     */
    private function rowWarnings(array $row): array
    {
        $warnings = [];

        if (trim($row['mobile']) === '') {
            $warnings[] = 'No mobile number — SMS reminders are disabled for this tenant until one is added.';
        }

        if (trim($row['registry_no']) === '') {
            $warnings[] = 'No registry number — tenant imported as an individual without a national ID.';
        }

        if ($row['csr_type'] === 'percent_revenue') {
            $warnings[] = 'CSR is a percentage of revenue — declared revenue must be recorded before it can be billed.';
        }

        return $warnings;
    }

    /**
     * @return array<int, array<string, string>> rows keyed by 1-based file line
     */
    private function parse(string $path): array
    {
        if (! is_readable($path)) {
            throw new InvalidArgumentException("Cannot read import file [{$path}].");
        }

        $handle = fopen($path, 'r');
        $header = null;
        $rows = [];
        $line = 0;

        while (($raw = fgetcsv($handle)) !== false) {
            $line++;

            if ($header === null) {
                // Normalise the header: BOM, case and spaces are forgiven.
                $header = array_map(
                    fn ($column) => str_replace(' ', '_', strtolower(trim((string) preg_replace('/^\xEF\xBB\xBF/', '', (string) $column)))),
                    $raw,
                );

                continue;
            }

            if (count(array_filter($raw, fn ($v) => trim((string) $v) !== '')) === 0) {
                continue; // blank line
            }

            $row = [];

            foreach ($header as $index => $column) {
                $row[$column] = trim((string) ($raw[$index] ?? ''));
            }

            // Every known column exists, present or not.
            foreach (['property_name', 'land_number', 'size_sqft', 'usage_type', 'tenant_name', 'registry_no', 'mobile', 'email', 'contact_person', 'agreement_number', 'agreement_date', 'start_date', 'rent_start_date', 'duration_years', 'expiry_date', 'rent_basis', 'rate_laari', 'area_sqft', 'flat_rent_mvr', 'due_day', 'grace_months', 'csr_type', 'csr_amount_mvr', 'csr_percent', 'csr_month', 'fine_method', 'fine_rate', 'status', 'notes', 'parcel_split_ok'] as $column) {
                $row[$column] ??= '';
            }

            $rows[$line] = $row;
        }

        fclose($handle);

        if ($header === null) {
            throw new InvalidArgumentException('The import file is empty.');
        }

        return $rows;
    }

    private function usageType(string $value): UsageType
    {
        $normalised = strtolower(str_replace([' ', '-', '/'], '_', trim($value)));

        return UsageType::tryFrom($normalised) ?? UsageType::Other;
    }

    private function date(string $value): CarbonImmutable
    {
        return $this->tryDate($value) ?? throw new ImportRowException(["{$value} is not a recognisable date"]);
    }

    private function tryDate(string $value): ?CarbonImmutable
    {
        foreach (self::DATE_FORMATS as $format) {
            try {
                $parsed = CarbonImmutable::createFromFormat($format, trim($value));
            } catch (Throwable) {
                continue; // wrong format — try the next one
            }

            // Round-trip check rejects overflowed dates like 31/31/2020.
            if ($parsed !== false && $parsed->format($format) === trim($value)) {
                return $parsed->startOfDay();
            }
        }

        return null;
    }

    /**
     * @param  array<string, string>  $row
     */
    private function reference(array $row): string
    {
        return $row['agreement_number'] ?: ($row['land_number'] ?: ($row['tenant_name'] ?: 'unknown'));
    }
}
