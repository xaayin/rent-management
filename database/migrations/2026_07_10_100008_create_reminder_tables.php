<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // One editable rule per reminder kind (FR-NOT-02/03): toggle, offset
        // days relative to the due date, and the English message template.
        Schema::create('reminder_rules', function (Blueprint $table) {
            $table->id();
            $table->string('kind')->unique();          // pre_due | on_due | overdue
            $table->boolean('enabled')->default(true);
            $table->unsignedSmallInteger('days')->default(0);
            $table->text('template');
            $table->timestamps();
        });

        // Every attempted send, with its outcome (FR-NOT-05, §5.5.28).
        Schema::create('notification_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->foreignId('invoice_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('kind')->nullable();         // pre_due | on_due | overdue | manual
            $table->string('channel')->default('sms');
            $table->string('recipient');
            $table->text('content');
            $table->string('status');                   // sent | failed
            $table->string('provider_id')->nullable();
            $table->text('error')->nullable();
            $table->timestamps();

            $table->index(['invoice_id', 'kind', 'status']);
        });

        // Per-tenant opt-out (§5.5.26, FR-NOT-09).
        Schema::table('tenants', function (Blueprint $table) {
            $table->boolean('sms_opt_out')->default(false)->after('email');
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropColumn('sms_opt_out');
        });
        Schema::dropIfExists('notification_logs');
        Schema::dropIfExists('reminder_rules');
    }
};
