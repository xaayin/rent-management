<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Advance billing: one invoice may cover several billing months
        // (paid-upfront quarters, years, or the whole lease term).
        Schema::table('invoices', function (Blueprint $table) {
            $table->unsignedSmallInteger('period_months')->default(1)->after('period_month');
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropColumn('period_months');
        });
    }
};
