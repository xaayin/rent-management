<?php

declare(strict_types=1);

namespace App\Services\Billing;

use Illuminate\Support\Facades\DB;

/**
 * Issues sequential, per-year receipt numbers in the locked `YYYY/NNN` format
 * (FR-PAY-01). Its own counter, independent of invoice numbering; numbers are
 * never reused and can never be edited after issue (FR-PAY-07).
 */
class ReceiptNumberGenerator
{
    public function next(int $year): string
    {
        $sequence = DB::transaction(function () use ($year): int {
            DB::table('receipt_sequences')->insertOrIgnore(['year' => $year, 'next_value' => 1]);

            $row = DB::table('receipt_sequences')->where('year', $year)->lockForUpdate()->first();
            $value = (int) $row->next_value;

            DB::table('receipt_sequences')->where('year', $year)->update(['next_value' => $value + 1]);

            return $value;
        });

        return sprintf('%d/%03d', $year, $sequence);
    }
}
