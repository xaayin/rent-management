<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The register's free-text notes column (PRD Appendix A).
        Schema::table('leases', function (Blueprint $table) {
            $table->text('notes')->nullable()->after('termination_reason');
        });
    }

    public function down(): void
    {
        Schema::table('leases', function (Blueprint $table) {
            $table->dropColumn('notes');
        });
    }
};
