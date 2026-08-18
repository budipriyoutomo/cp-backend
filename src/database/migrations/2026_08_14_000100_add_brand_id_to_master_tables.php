<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fase 2 dari docs/brand-feature-plan.md — bagian skema saja.
 *
 * Kolom dibuat nullable dengan sengaja: pengisiannya ada di migration backfill
 * berikutnya, dan baris yang tidak bisa ditentukan brand-nya harus tetap NULL
 * (lebih baik kosong dan berisik daripada terisi tebakan).
 *
 * Rencana awal memecah ini jadi tiga migration per tabel. Digabung karena
 * ketiganya satu perubahan logis — kalau salah satu gagal, tidak ada gunanya
 * dua sisanya berhasil.
 *
 * `outlets.brand` (string bebas) SENGAJA tidak dihapus di sini. Migration
 * backfill membacanya sebagai sumber kebenaran, dan OutletSeeder masih
 * menulisinya. Drop kolomnya adalah langkah terpisah setelah data diverifikasi.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('outlets', function (Blueprint $table) {
            $table->uuid('brand_id')->nullable()->after('brand');
            $table->index('brand_id');
            $table->foreign('brand_id')->references('id')->on('brands');
        });

        Schema::table('menus', function (Blueprint $table) {
            $table->uuid('brand_id')->nullable()->after('plate_color_id');
            $table->index('brand_id');
            $table->foreign('brand_id')->references('id')->on('brands');
        });

        Schema::table('plate_colors', function (Blueprint $table) {
            $table->uuid('brand_id')->nullable()->after('platename');
            $table->index('brand_id');
            $table->foreign('brand_id')->references('id')->on('brands');
        });
    }

    public function down(): void
    {
        foreach (['outlets', 'menus', 'plate_colors'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table) use ($tableName) {
                $table->dropForeign(['brand_id']);
                $table->dropIndex($tableName . '_brand_id_index');
                $table->dropColumn('brand_id');
            });
        }
    }
};
