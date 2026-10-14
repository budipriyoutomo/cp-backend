<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Kategori waste: pengelompokan di atas `waste_reasons`. Satu reason menunjuk
 * satu kategori. Global, tidak per brand — sama seperti `waste_reasons`.
 *
 * `code` adalah kunci yang dikirim ke BI, jadi ia stabil: tidak boleh diubah
 * setelah dipakai (dijaga WasteCategoryService). `category_name` boleh berubah.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('waste_categories', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('code', 50)->unique();
            $table->string('category_name');
            $table->text('description')->nullable();
            $table->integer('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->fullstamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('waste_categories');
    }
};
