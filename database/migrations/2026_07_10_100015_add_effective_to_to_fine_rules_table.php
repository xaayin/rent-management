<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Fine rules become explicit PERIODS. `effective_to` null keeps the old
     * open-ended meaning, so every existing row carries over unchanged: a rule
     * that ran "from D onwards" still does.
     */
    public function up(): void
    {
        Schema::table('fine_rules', function (Blueprint $table) {
            $table->date('effective_to')->nullable()->after('effective_from');
            $table->index(['lease_id', 'effective_to']);
        });
    }

    public function down(): void
    {
        Schema::table('fine_rules', function (Blueprint $table) {
            $table->dropIndex(['lease_id', 'effective_to']);
            $table->dropColumn('effective_to');
        });
    }
};
