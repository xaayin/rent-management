<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fine_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lease_id')->constrained()->restrictOnDelete();

            $table->string('method');                                  // flat_per_day | percent_per_day | tiered_monthly
            $table->string('base')->default('rent');                   // rent | rent_plus_charges (FR-FIN-03)
            $table->unsignedSmallInteger('allowance_days')->default(0);

            $table->unsignedBigInteger('flat_daily_laari')->nullable();      // flat_per_day
            $table->unsignedInteger('percent_daily_bps')->nullable();        // percent_per_day (0.5% = 50 bps)
            $table->unsignedBigInteger('first_month_laari')->nullable();     // tiered (default MVR 100)
            $table->unsignedBigInteger('subsequent_month_laari')->nullable(); // tiered (default MVR 50)

            $table->unsignedBigInteger('cap_laari')->nullable();       // optional maximum (FR-FIN-03)
            $table->date('effective_from');                            // effective-dated (FR-FIN-09)
            $table->timestamps();

            $table->index(['lease_id', 'effective_from']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fine_rules');
    }
};
