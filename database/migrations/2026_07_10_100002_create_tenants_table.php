<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenants', function (Blueprint $table) {
            $table->id();
            $table->string('type');
            $table->string('name');
            $table->string('national_id')->nullable()->unique();     // individuals
            $table->string('company_reg_no')->nullable()->unique();  // organisations
            $table->string('contact_person')->nullable();            // organisations
            $table->string('mobile');
            $table->string('email')->nullable();
            $table->text('postal_address')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenants');
    }
};
