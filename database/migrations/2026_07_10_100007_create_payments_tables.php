<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            // Null for reversal entries — only real payments get a receipt.
            $table->string('receipt_number')->nullable()->unique();
            $table->foreignId('invoice_id')->constrained()->restrictOnDelete();

            // Signed: a reversal is an appended row with negated amounts.
            $table->bigInteger('amount_laari');
            $table->bigInteger('principal_allocated_laari')->default(0);  // rent + CSR charges
            $table->bigInteger('fine_allocated_laari')->default(0);

            $table->date('payment_date');
            $table->string('method');
            $table->string('reference')->nullable();

            $table->string('type')->default('payment');                   // payment | reversal
            $table->foreignId('reversed_payment_id')->nullable()->constrained('payments')->restrictOnDelete();
            $table->text('reversal_reason')->nullable();

            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['invoice_id', 'type']);
        });

        // Gap-free, per-year sequential source for receipt numbers (FR-PAY-01,
        // locked format YYYY/NNN — a separate sequence from invoice numbers).
        Schema::create('receipt_sequences', function (Blueprint $table) {
            $table->unsignedSmallInteger('year')->primary();
            $table->unsignedInteger('next_value')->default(1);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payments');
        Schema::dropIfExists('receipt_sequences');
    }
};
