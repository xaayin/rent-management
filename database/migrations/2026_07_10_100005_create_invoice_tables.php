<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invoices', function (Blueprint $table) {
            $table->id();
            $table->string('number')->unique();                       // YYYY/NNN (FR-INV-03)
            $table->foreignId('lease_id')->constrained()->restrictOnDelete();

            $table->unsignedSmallInteger('period_year');
            $table->unsignedTinyInteger('period_month');
            $table->date('period_start');
            $table->date('period_end');
            $table->date('due_date');

            $table->string('status')->default('issued');

            $table->unsignedBigInteger('rent_laari')->default(0);
            $table->unsignedBigInteger('charges_laari')->default(0);  // CSR
            $table->unsignedBigInteger('fine_laari')->default(0);     // populated in Slice 4
            $table->unsignedBigInteger('total_laari')->default(0);

            $table->timestamps();

            // Exactly one invoice per lease per billing cycle (FR-INV-06).
            $table->unique(['lease_id', 'period_year', 'period_month']);
        });

        Schema::create('invoice_line_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('invoice_id')->constrained()->cascadeOnDelete();
            $table->string('type');                                   // rent | csr | fine
            $table->string('description');
            $table->unsignedBigInteger('amount_laari');
            $table->json('meta')->nullable();                         // breakdown (rate, area, …)
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();
        });

        // Gap-free, per-year sequential source for invoice numbers (FR-INV-03).
        Schema::create('invoice_sequences', function (Blueprint $table) {
            $table->unsignedSmallInteger('year')->primary();
            $table->unsignedInteger('next_value')->default(1);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoice_line_items');
        Schema::dropIfExists('invoice_sequences');
        Schema::dropIfExists('invoices');
    }
};
