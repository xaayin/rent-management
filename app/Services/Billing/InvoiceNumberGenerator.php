<?php

declare(strict_types=1);

namespace App\Services\Billing;

use Illuminate\Support\Facades\DB;

/**
 * Issues sequential, per-year invoice numbers in the format `YYYY/NNN`
 * (FR-INV-03). Numbers are drawn from a locked counter row so they are never
 * reused, even under concurrent generation.
 */
class InvoiceNumberGenerator
{
    public function next(int $year): string
    {
        $sequence = DB::transaction(function () use ($year): int {
            DB::table('invoice_sequences')->insertOrIgnore(['year' => $year, 'next_value' => 1]);

            $row = DB::table('invoice_sequences')->where('year', $year)->lockForUpdate()->first();
            $value = (int) $row->next_value;

            DB::table('invoice_sequences')->where('year', $year)->update(['next_value' => $value + 1]);

            return $value;
        });

        return sprintf('%d/%03d', $year, $sequence);
    }
}
