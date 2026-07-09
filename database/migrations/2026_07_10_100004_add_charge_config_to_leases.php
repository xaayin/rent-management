<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leases', function (Blueprint $table) {
            $table->unsignedSmallInteger('grace_months')->default(0)->after('flat_amount_laari');
            $table->string('billing_cycle')->default('monthly')->after('grace_months');
            $table->unsignedTinyInteger('due_day')->default(10)->after('billing_cycle');

            $table->string('csr_type')->default('none')->after('due_day');
            $table->unsignedBigInteger('csr_amount_laari')->nullable()->after('csr_type');
            $table->unsignedInteger('csr_percent_bps')->nullable()->after('csr_amount_laari');
            $table->unsignedBigInteger('csr_declared_revenue_laari')->nullable()->after('csr_percent_bps');
            $table->unsignedTinyInteger('csr_month')->nullable()->after('csr_declared_revenue_laari');

            $table->unsignedBigInteger('security_deposit_laari')->nullable()->after('csr_month');
        });
    }

    public function down(): void
    {
        Schema::table('leases', function (Blueprint $table) {
            $table->dropColumn([
                'grace_months', 'billing_cycle', 'due_day',
                'csr_type', 'csr_amount_laari', 'csr_percent_bps',
                'csr_declared_revenue_laari', 'csr_month', 'security_deposit_laari',
            ]);
        });
    }
};
