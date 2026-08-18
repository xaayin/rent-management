<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Voiding an invoice raised in error (FR-AUD-02 stays intact: the row and
     * its YYYY/NNN number are kept, so the sequence has no unexplainable hole).
     *
     * The one-invoice-per-lease-per-month guarantee moves from a composite
     * unique to a nullable `period_key`, which is NULL once an invoice is
     * voided. NULLs do not collide in MySQL or SQLite — the same trick the
     * approvals table uses for `pending_key`, and a partial index is not
     * portable. A voided month therefore becomes billable again, while two
     * LIVE invoices for one month remain impossible at the database level.
     */
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->timestamp('cancelled_at')->nullable()->after('status');
            $table->text('cancellation_reason')->nullable()->after('cancelled_at');
            $table->foreignId('cancelled_by')->nullable()->after('cancellation_reason')
                ->constrained('users')->nullOnDelete();
            $table->string('period_key')->nullable()->after('period_month');
        });

        // Backfill in PHP: string concatenation differs per driver.
        DB::table('invoices')->orderBy('id')->select('id', 'lease_id', 'period_year', 'period_month')
            ->chunk(500, function ($rows): void {
                foreach ($rows as $row) {
                    DB::table('invoices')->where('id', $row->id)->update([
                        'period_key' => $row->lease_id.'-'.$row->period_year.'-'.$row->period_month,
                    ]);
                }
            });

        Schema::table('invoices', function (Blueprint $table) {
            $table->unique('period_key');
        });

        // MySQL will not drop the composite unique while it is the only index
        // supporting the lease_id foreign key — give the key its own index first.
        Schema::table('invoices', function (Blueprint $table) {
            $table->index('lease_id', 'invoices_lease_id_index');
        });

        // Separate statement: SQLite cannot add and drop indexes in one pass.
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropUnique(['lease_id', 'period_year', 'period_month']);
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->unique(['lease_id', 'period_year', 'period_month']);
        });

        Schema::table('invoices', function (Blueprint $table) {
            $table->dropIndex('invoices_lease_id_index');
            $table->dropUnique(['period_key']);
        });

        Schema::table('invoices', function (Blueprint $table) {
            $table->dropColumn('period_key');
            $table->dropConstrainedForeignId('cancelled_by');
            $table->dropColumn(['cancelled_at', 'cancellation_reason']);
        });
    }
};
