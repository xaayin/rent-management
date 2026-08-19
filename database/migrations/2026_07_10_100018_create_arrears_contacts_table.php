<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The record of chasing a tenant for arrears: what was said, and what they
     * promised. Whether a promise was KEPT is never stored — it is derived from
     * the payment ledger, so it cannot drift out of date or be ticked off by
     * mistake.
     */
    public function up(): void
    {
        Schema::create('arrears_contacts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();

            $table->date('contacted_on');
            $table->string('channel');                                   // call | sms | visit | letter | other
            $table->text('note');

            $table->date('promised_on')->nullable();                     // null = a note, not a promise
            $table->unsignedBigInteger('promised_amount_laari')->nullable(); // null = settle in full

            // What they owed when the call was made, so the history reads
            // correctly years later even as the balance moves on.
            $table->unsignedBigInteger('outstanding_at_contact_laari')->default(0);

            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['tenant_id', 'contacted_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('arrears_contacts');
    }
};
