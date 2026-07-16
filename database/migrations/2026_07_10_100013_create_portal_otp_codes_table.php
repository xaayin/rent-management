<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One-time sign-in codes for the tenant portal (T2).
 *
 * Keyed by NORMALISED mobile, not tenant id: the code is requested before we
 * know which tenant is asking, and one mobile can legitimately map to several
 * tenant records (the legacy import keyed tenants by name + mobile).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('portal_otp_codes', function (Blueprint $table) {
            $table->id();
            $table->string('mobile', 32)->index();      // normalised digits
            $table->string('code_hash');
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->timestamp('expires_at');
            $table->timestamp('consumed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('portal_otp_codes');
    }
};
