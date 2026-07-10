<?php

declare(strict_types=1);

namespace App\Services\Import;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use InvalidArgumentException;
use OpenSpout\Reader\XLSX\Options;
use OpenSpout\Reader\XLSX\Reader;

/**
 * Reads the council's actual workbook — "Kuli Binthakuge Dhaftaru" — and maps
 * its right-to-left Thaana register into the canonical row format consumed by
 * RegisterImporter (PRD Appendix A).
 *
 * Real-world quirks handled here so the rest of the pipeline stays clean:
 *  - headers on rows 8–9, data from row 10; columns run right-to-left;
 *  - dates written as Dhivehi month names with spelling variants;
 *  - rates as "N laari per ft²", "N rufiyaa per ft²" or "N per month" (flat);
 *  - the lease date and rent-start date differ → grace months;
 *  - CSR as "N per year" (fixed) or "N% of income" (percent of revenue);
 *  - land numbers that repeat per zone → a synthesised unique land number
 *    from name + number + size (to be corrected by staff after migration);
 *  - decimal areas (e.g. 3,332.94 ft²) → imported as flat rent at the stated
 *    monthly amount, since per-ft² maths requires whole laari.
 */
class WorkbookRegisterReader
{
    /** Column letters (A=0) in the register sheet. */
    private const int COL_NOTES = 0;         // A — free-text notes

    private const int COL_STATUS = 1;        // B — Active / TERMINTED

    private const int COL_CSR = 2;           // C — CSR terms

    private const int COL_PAY_TERMS = 3;     // D — "before the 10th of each month"

    private const int COL_MONTHLY_RENT = 4;  // E — stated monthly rent (MVR)

    private const int COL_LAND_NO = 5;       // F — zone plot number (not unique)

    private const int COL_SIZE = 6;          // G — "2000 އަކަފޫޓް"

    private const int COL_RATE = 7;          // H — rate text

    private const int COL_RENT_START = 8;    // I — rent start date

    private const int COL_EXPIRY = 9;        // J — expiry date

    private const int COL_LEASED = 10;       // K — leased date

    private const int COL_DURATION = 11;     // L — "10އަހަރަށް"

    private const int COL_AGREEMENT = 12;    // M — agreement number

    private const int COL_EMAIL = 13;        // N

    private const int COL_PHONE = 14;        // O

    private const int COL_REGISTRY = 15;     // P — A…/C-… registry number

    private const int COL_TENANT = 16;       // Q — tenant name

    private const int COL_PROPERTY = 17;     // R — place name

    private const array DHIVEHI_MONTHS = [
        1 => ['ޖެނުއަރ', 'ޖަނަވަރ'],
        2 => ['ފެބްރުއަރ', 'ފެބުރުއަރ'],
        3 => ['މާރިޗ', 'މާރޗ', 'މާޗ'],
        4 => ['އޭޕްރ', 'އެޕްރ'],
        5 => ['މެއި', 'މޭ '],
        6 => ['ޖޫން'],
        7 => ['ޖުލައި'],
        8 => ['އޮގަސްޓ', 'އޯގަސްޓ'],
        9 => ['ސެޕްޓެމްބަރ', 'ސެޕްޓެންބަރ'],
        10 => ['އޮކްޓ', 'އޮކަޓ'],
        11 => ['ނޮވެމްބަރ', 'ނޮވެންބަރ'],
        12 => ['ޑިސެމ', 'ޑިސެން'],
    ];

    /** Usage-type keywords found in the register's place names. */
    private const array USAGE_KEYWORDS = [
        'vacant_land' => ['ހުސްބިނ', 'ހުސް ބިނ'],
        'telecom_antenna' => ['އެންޓަނާ'],
        'boat_shed' => ['އޮޑިހަރުގެ'],
        'cafe_restaurant' => ['ކެފޭ', 'ރެސްޓޯރަންޓ'],
        'tea_shop' => ['ސައި ހޮޓާ', 'ސައިހޮޓާ'],
        'agricultural' => ['ދަނޑުވެރި'],
        'commercial' => ['ވިޔަފާރި'],
    ];

    /**
     * @return array<int, array<string, string>> canonical rows keyed by sheet line
     */
    public function rows(string $path): array
    {
        // Preserve empty rows so line numbers in the reconciliation report
        // match the Excel row numbers staff see.
        $reader = new Reader(new Options(SHOULD_PRESERVE_EMPTY_ROWS: true));
        $reader->open($path);

        $rows = [];

        try {
            foreach ($reader->getSheetIterator() as $sheet) {
                // The register is the "…Dhaftaru" sheet; fall back to sheet 1.
                if (stripos($sheet->getName(), 'dhaftaru') === false && $sheet->getIndex() !== 0) {
                    continue;
                }

                foreach ($sheet->getRowIterator() as $line => $row) {
                    if ($line < 10) {
                        continue; // title + header rows
                    }

                    $cells = array_map($this->cellToString(...), $row->toArray());
                    $cells = array_pad($cells, 19, '');

                    if ($this->isBlank($cells)) {
                        continue;
                    }

                    $rows[$line] = $this->mapRow($cells);
                }

                break;
            }
        } finally {
            $reader->close();
        }

        if ($rows === []) {
            throw new InvalidArgumentException('No register rows found in the workbook.');
        }

        return $rows;
    }

    /**
     * @param  list<string>  $cells
     * @return array<string, string>
     */
    private function mapRow(array $cells): array
    {
        $propertyName = trim($cells[self::COL_PROPERTY]);
        $sizeSqft = $this->parseSize($cells[self::COL_SIZE]);
        $rate = $this->parseRate($cells[self::COL_RATE]);
        $monthlyRent = $this->parseNumber($cells[self::COL_MONTHLY_RENT]);

        $leased = $this->parseDate($cells[self::COL_LEASED]);
        $rentStart = $this->parseDate($cells[self::COL_RENT_START]) ?? $leased;
        $expiry = $this->parseDate($cells[self::COL_EXPIRY]);

        [$basis, $rateLaari, $areaSqft, $flatMvr] = $this->rentBasis($rate, $sizeSqft, $monthlyRent);
        [$csrType, $csrAmount, $csrPercent] = $this->parseCsr($cells[self::COL_CSR]);

        $graceMonths = ($leased !== null && $rentStart !== null && $rentStart->greaterThan($leased))
            ? (int) $leased->diffInMonths($rentStart)
            : 0;

        $duration = $this->parseDuration($cells[self::COL_DURATION], $leased, $expiry);

        return [
            'property_name' => $propertyName,
            // Zone plot numbers repeat across the register, so uniqueness is
            // synthesised from name + number + size. Staff should replace
            // these with real parcel numbers after migration.
            'land_number' => $this->syntheticLandNumber($propertyName, $cells[self::COL_LAND_NO], $sizeSqft),
            'size_sqft' => $sizeSqft !== null ? (string) (int) round($sizeSqft) : '',
            'usage_type' => $this->usageType($propertyName),
            'tenant_name' => trim($cells[self::COL_TENANT]),
            'registry_no' => trim($cells[self::COL_REGISTRY]),
            'mobile' => trim($cells[self::COL_PHONE]),
            'email' => trim($cells[self::COL_EMAIL]),
            'contact_person' => '',
            'agreement_number' => trim($cells[self::COL_AGREEMENT]),
            'agreement_date' => $leased?->toDateString() ?? '',
            'start_date' => $leased?->toDateString() ?? '',
            'rent_start_date' => $rentStart?->toDateString() ?? '',
            'duration_years' => $duration,
            'expiry_date' => $expiry?->toDateString() ?? '',
            'rent_basis' => $basis,
            'rate_laari' => $rateLaari,
            'area_sqft' => $areaSqft,
            'flat_rent_mvr' => $flatMvr,
            'due_day' => $this->parseDueDay($cells[self::COL_PAY_TERMS]),
            'grace_months' => (string) $graceMonths,
            'csr_type' => $csrType,
            'csr_amount_mvr' => $csrAmount,
            'csr_percent' => $csrPercent,
            'csr_month' => $csrType !== '' && $rentStart !== null ? (string) $rentStart->month : '',
            'fine_method' => '',
            'fine_rate' => '',
            'status' => $this->parseStatus($cells[self::COL_STATUS]),
            'notes' => trim($cells[self::COL_NOTES]),
            // The register has no unique parcel IDs, so a same-name/size clash
            // between two active leases means two real plots: split rather
            // than reject (the importer warns so staff assign real numbers).
            'parcel_split_ok' => '1',
        ];
    }

    /**
     * Decide the rent basis by reconciling rate × area against the stated
     * monthly rent, falling back to a flat amount when the maths cannot be
     * integer-exact (decimal areas) or no rate exists.
     *
     * @param  array{type: string, laari: int}|null  $rate
     * @return array{0: string, 1: string, 2: string, 3: string} [basis, rate_laari, area_sqft, flat_mvr]
     */
    private function rentBasis(?array $rate, ?float $sizeSqft, ?float $monthlyRent): array
    {
        if ($rate !== null && $rate['type'] === 'per_sqft' && $sizeSqft !== null && floor($sizeSqft) === $sizeSqft) {
            $computedLaari = (int) $sizeSqft * $rate['laari'];

            // Trust the per-ft² terms when no stated rent contradicts them.
            if ($monthlyRent === null || (int) round($monthlyRent * 100) === $computedLaari) {
                return ['per_sqft', (string) $rate['laari'], (string) (int) $sizeSqft, ''];
            }
        }

        if ($rate !== null && $rate['type'] === 'flat') {
            return ['flat', '', '', number_format($rate['laari'] / 100, 2, '.', '')];
        }

        if ($monthlyRent !== null) {
            return ['flat', '', '', number_format($monthlyRent, 2, '.', '')];
        }

        return ['', '', '', ''];
    }

    /**
     * "އަކަފޫޓަކަށް 53 ލާރި" → 53 laari/ft² · "އަކަފޫޓަކަށް 1 ރުފިޔާ" → 100
     * laari/ft² · "1500 މަހަކަށް" → flat MVR 1,500/month.
     *
     * @return array{type: string, laari: int}|null
     */
    private function parseRate(string $text): ?array
    {
        $number = $this->parseNumber($text);

        if ($number === null) {
            return null;
        }

        if (mb_strpos($text, 'މަހަކަށް') !== false) {
            return ['type' => 'flat', 'laari' => (int) round($number * 100)];
        }

        if (mb_strpos($text, 'ރުފިޔާ') !== false) {
            return ['type' => 'per_sqft', 'laari' => (int) round($number * 100)];
        }

        if (mb_strpos($text, 'ލާރި') !== false) {
            return ['type' => 'per_sqft', 'laari' => (int) round($number)];
        }

        return null;
    }

    private function parseSize(string $text): ?float
    {
        return $this->parseNumber($text);
    }

    /**
     * CSR terms: "އަހަރަކު 9000 ރ" → fixed MVR 9,000/year;
     * "އާމްދަނީގެ 1%" → 1% of income (declared revenue recorded later).
     *
     * @return array{0: string, 1: string, 2: string} [type, amount_mvr, percent]
     */
    private function parseCsr(string $text): array
    {
        $number = $this->parseNumber($text);

        if ($number === null) {
            return ['', '', ''];
        }

        if (mb_strpos($text, '%') !== false) {
            return ['percent_revenue', '', number_format($number, 2, '.', '')];
        }

        return ['fixed_annual', number_format($number, 2, '.', ''), ''];
    }

    /**
     * "10އަހަރަށް" → 10; otherwise derived from the leased → expiry span.
     */
    private function parseDuration(string $text, ?CarbonImmutable $leased, ?CarbonImmutable $expiry): string
    {
        $number = $this->parseNumber($text);

        if ($number !== null && $number >= 1) {
            return (string) (int) $number;
        }

        if ($leased !== null && $expiry !== null && $expiry->greaterThan($leased)) {
            return (string) max(1, (int) round($leased->diffInMonths($expiry) / 12));
        }

        return '';
    }

    /**
     * "…10 ވަނަ ދުވަހ…" → due day 10 (register default).
     */
    private function parseDueDay(string $text): string
    {
        if (preg_match('/(\d{1,2})\s*ވަނަ/u', $text, $m) === 1) {
            return $m[1];
        }

        return '10';
    }

    private function parseStatus(string $text): string
    {
        return stripos(trim($text), 'term') === 0 ? 'terminated' : 'active';
    }

    /**
     * "01 ޖެނުއަރީ 2022" (with typo variants and missing spaces) → 2022-01-01.
     */
    private function parseDate(string $text): ?CarbonImmutable
    {
        $text = trim($text);

        if ($text === '') {
            return null;
        }

        if (preg_match('/(\d{1,2})\s*(\D+?)\s*(\d{4})/u', $text, $m) !== 1) {
            return null;
        }

        foreach (self::DHIVEHI_MONTHS as $month => $variants) {
            foreach ($variants as $variant) {
                if (mb_strpos($m[2], $variant) !== false) {
                    $day = min((int) $m[1], (int) CarbonImmutable::create((int) $m[3], $month, 1)->daysInMonth);

                    return CarbonImmutable::create((int) $m[3], $month, $day)->startOfDay();
                }
            }
        }

        return null;
    }

    private function usageType(string $propertyName): string
    {
        foreach (self::USAGE_KEYWORDS as $usage => $keywords) {
            foreach ($keywords as $keyword) {
                if (mb_strpos($propertyName, $keyword) !== false) {
                    return $usage;
                }
            }
        }

        return 'other';
    }

    private function syntheticLandNumber(string $name, string $plotNo, ?float $sizeSqft): string
    {
        if ($name === '') {
            return '';
        }

        $parts = [$name];

        if (trim($plotNo) !== '') {
            $parts[] = '#'.trim($plotNo);
        }

        if ($sizeSqft !== null) {
            $parts[] = ((int) round($sizeSqft)).'ft';
        }

        return implode(' · ', $parts);
    }

    private function parseNumber(string $text): ?float
    {
        if (preg_match('/(\d+(?:[.,]\d+)?)/u', str_replace(',', '', $text), $m) === 1) {
            return (float) str_replace(',', '.', $m[1]);
        }

        return null;
    }

    private function cellToString(mixed $value): string
    {
        if ($value instanceof DateTimeInterface) {
            return $value->format('Y-m-d');
        }

        if (is_float($value)) {
            // Avoid "1766.4600000000001" artefacts.
            return rtrim(rtrim(number_format($value, 4, '.', ''), '0'), '.');
        }

        return trim((string) $value);
    }

    /**
     * @param  list<string>  $cells
     */
    private function isBlank(array $cells): bool
    {
        return count(array_filter($cells, fn (string $v): bool => trim($v) !== '')) === 0;
    }
}
