<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fase 1 dari rencana brand (docs/brand-feature-plan.md).
 *
 * Tabel master saja. Belum ada kolom `brand_id` di menus / outlets /
 * plate_colors — itu Fase 2, bersama migration datanya. Dipisah supaya fase ini
 * bisa dirilis tanpa menyentuh satu baris pun data yang sudah ada.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('brands', function (Blueprint $table) {
            $table->uuid('id')->primary();
            // `code` adalah kunci natural: dipakai seeder untuk updateOrCreate,
            // dan nanti jadi pegangan saat migrasi data Fase 2.
            $table->string('code')->unique();
            $table->string('name');
            $table->string('description')->nullable();
            $table->string('logo')->nullable();
            $table->boolean('is_active')->default(true);

            $table->timestamps();
            $table->fullstamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('brands');
    }
};
