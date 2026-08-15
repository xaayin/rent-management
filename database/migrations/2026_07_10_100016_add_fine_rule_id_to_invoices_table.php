<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Records WHICH fine period actually produced an invoice's current fine
     * (FR-FIN-12 auditability). This is a record of what happened, not a pin:
     * the applier rewrites it on every refresh, so it always agrees with the
     * fine currently carried on the invoice.
     */
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->foreignId('fine_rule_id')->nullable()->after('fine_laari')
                ->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropConstrainedForeignId('fine_rule_id');
        });
    }
};
