<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Introduces the receipt as its own record, so ONE handover of money gets ONE
 * receipt number even when it settles several invoices (FR-PAY-01).
 *
 * Until now `payments.receipt_number` was unique, which hard-wired "one payment
 * row = one receipt = one invoice". A tenant clearing five invoices in one go
 * had to be entered five times and walked away with five receipt numbers for a
 * single handover. The ledger keeps one append-only row per invoice — that
 * invariant carries statements, reversals and the fine freeze — but those rows
 * now hang off a shared receipt.
 *
 * Receipts deliberately do NOT own payment_date/method/reference: reversal rows
 * have no receipt and still need their own, so those stay on the payment.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('receipts', function (Blueprint $table) {
            $table->id();

            // Still the locked YYYY/NNN sequence — unique here now, which is
            // the guarantee `payments.receipt_number` used to provide.
            $table->string('number')->unique();

            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::table('payments', function (Blueprint $table) {
            // Null for reversal entries — exactly as receipt_number was.
            $table->foreignId('receipt_id')->nullable()->after('id')->constrained()->restrictOnDelete();
        });

        $this->backfillReceipts();

        // The unique index has to go before the column: SQLite refuses to drop
        // a column an index still references, and the tests run on SQLite.
        Schema::table('payments', function (Blueprint $table) {
            $table->dropUnique('payments_receipt_number_unique');
        });

        Schema::table('payments', function (Blueprint $table) {
            $table->dropColumn('receipt_number');
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->string('receipt_number')->nullable()->unique();
        });

        // Put the numbers back on the rows before the receipts disappear.
        foreach (DB::table('receipts')->orderBy('id')->get() as $receipt) {
            DB::table('payments')
                ->where('receipt_id', $receipt->id)
                ->update(['receipt_number' => $receipt->number]);
        }

        Schema::table('payments', function (Blueprint $table) {
            $table->dropForeign(['receipt_id']);
            $table->dropColumn('receipt_id');
        });

        Schema::dropIfExists('receipts');
    }

    /**
     * Give every already-issued receipt number its own receipt row.
     *
     * Uses the query builder, not Eloquent: the Payment model throws on any
     * update by design (append-only), and this is the one legitimate exception —
     * a structural move that changes where the number is stored, not what it is.
     * Every existing number is carried over verbatim, so no receipt is reissued
     * or renumbered.
     */
    private function backfillReceipts(): void
    {
        DB::table('payments')
            ->whereNotNull('receipt_number')
            ->orderBy('id')
            ->chunkById(200, function ($payments): void {
                foreach ($payments as $payment) {
                    $tenantId = DB::table('invoices')
                        ->join('leases', 'leases.id', '=', 'invoices.lease_id')
                        ->where('invoices.id', $payment->invoice_id)
                        ->value('leases.tenant_id');

                    $receiptId = DB::table('receipts')->insertGetId([
                        'number' => $payment->receipt_number,
                        'tenant_id' => $tenantId,
                        'recorded_by' => $payment->recorded_by,
                        'created_at' => $payment->created_at,
                        'updated_at' => $payment->updated_at,
                    ]);

                    DB::table('payments')->where('id', $payment->id)->update(['receipt_id' => $receiptId]);
                }
            });
    }
};
