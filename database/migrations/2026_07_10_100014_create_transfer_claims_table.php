<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tenant bank-transfer claims (T3).
 *
 * A tenant working off-island transfers rent to the council's BML account and
 * submits the reference here; Finance confirms it into a real receipt. This
 * table holds the CLAIM only — the money lives in payments/receipts once
 * confirmed, linked back via receipt_id. No amount is ever moved by this table
 * alone, so it carries no allocation columns.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('transfer_claims', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();

            $table->bigInteger('amount_laari');           // what the tenant says they sent
            $table->date('transfer_date');
            $table->string('bank_reference');
            $table->text('note')->nullable();

            $table->string('status')->index();            // App\Enums\TransferClaimStatus

            // Set on confirm — the receipt the claim became.
            $table->foreignId('receipt_id')->nullable()->constrained()->nullOnDelete();

            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->text('decision_note')->nullable();     // required when rejecting

            $table->timestamps();

            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('transfer_claims');
    }
};
