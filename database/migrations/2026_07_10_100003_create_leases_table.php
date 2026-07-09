<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('leases', function (Blueprint $table) {
            $table->id();
            $table->string('agreement_number')->unique();            // FR-LSE-06
            $table->foreignId('property_id')->constrained()->restrictOnDelete();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();

            $table->date('agreement_date');
            $table->date('start_date');
            $table->date('rent_start_date');
            $table->unsignedSmallInteger('duration_years');
            $table->date('expiry_date');

            $table->string('rent_basis');                            // per_sqft | flat
            $table->unsignedBigInteger('rate_laari')->nullable();    // laari per ft²
            $table->unsignedInteger('area_sqft')->nullable();
            $table->unsignedBigInteger('flat_amount_laari')->nullable();

            $table->string('status')->default('draft');
            $table->date('terminated_on')->nullable();
            $table->text('termination_reason')->nullable();

            $table->timestamps();

            $table->index(['property_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('leases');
    }
};
