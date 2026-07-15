<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Supervisor-approval requests for the PRD §6.1 `A` cells (terminate a lease,
 * reverse a payment, and later waive a fine).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('approval_requests', function (Blueprint $table) {
            $table->id();

            $table->string('action');                       // App\Enums\ApprovalAction
            $table->morphs('subject');                      // the Lease / Payment acted on
            $table->string('status')->index();              // App\Enums\ApprovalStatus

            // The requester's justification, and anything else needed to carry
            // the action out on approval (kept so approving replays exactly
            // what was asked for, not today's inputs).
            $table->text('reason');
            $table->json('payload')->nullable();

            $table->foreignId('requested_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('requested_at');

            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->text('decision_note')->nullable();      // required when rejecting

            /*
             * Set while Pending and NULL once decided. A unique index over a
             * nullable column ignores NULLs on both MySQL and SQLite, so this
             * enforces "at most one open request per subject + action" in the
             * database — without a partial index, which MySQL 8 lacks. Tests
             * run on SQLite and production on MySQL, so it has to hold on both.
             */
            $table->string('pending_key')->nullable()->unique();

            $table->timestamps();

            $table->index(['status', 'requested_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('approval_requests');
    }
};
