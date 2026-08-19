<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Invoices gain a KIND (rent | csr) so an annual CSR charge can be its own
     * document; leases gain the agreement term saying which way their CSR is
     * billed. Every existing invoice is a rent demand, so the default is
     * exactly the current behaviour and no backfill is needed. The kind also
     * folds into the derived `period_key` (model saving hook), giving one live
     * CSR invoice per lease per YEAR the same way rent gets one per month.
     */
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->string('kind')->default('rent')->after('lease_id');
        });

        Schema::table('leases', function (Blueprint $table) {
            $table->string('csr_billing')->default('with_rent')->after('csr_month');
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropColumn('kind');
        });

        Schema::table('leases', function (Blueprint $table) {
            $table->dropColumn('csr_billing');
        });
    }
};
