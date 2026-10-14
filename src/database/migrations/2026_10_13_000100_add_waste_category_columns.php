<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Kolom kategori waste. Semuanya nullable dan hanya ditambahkan — tidak ada
 * kolom lama yang diubah, jadi alur yang sudah berjalan tidak terpengaruh
 * selama kolom ini masih kosong.
 *
 * - `waste_reasons.waste_category_id` — mapping reason → kategori. NULL = belum
 *   dikategorikan (reason lama sebelum backfill).
 * - `waste_reasons.is_system` — reason yang dipakai kode (Auto-waste, Import
 *   backdate). Tidak boleh dihapus atau di-rename.
 * - `waste_records.waste_reason_id` / `waste_category_id` — snapshot saat waste
 *   dicatat. Laporan dan payload BI membaca kolom ini, bukan mapping terkini,
 *   supaya memindahkan reason ke kategori lain tidak mengubah laporan lama.
 *   `reason` (teks) tetap ditulis seperti sebelumnya.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('waste_reasons', function (Blueprint $table) {
            $table->uuid('waste_category_id')->nullable()->after('description');
            $table->boolean('is_system')->default(false)->after('is_active');

            $table->foreign('waste_category_id')->references('id')->on('waste_categories');
            $table->index('waste_category_id');
        });

        Schema::table('waste_records', function (Blueprint $table) {
            $table->uuid('waste_reason_id')->nullable()->after('reason');
            $table->uuid('waste_category_id')->nullable()->after('waste_reason_id');

            $table->foreign('waste_reason_id')->references('id')->on('waste_reasons');
            $table->foreign('waste_category_id')->references('id')->on('waste_categories');
            $table->index('waste_category_id');
        });
    }

    public function down(): void
    {
        Schema::table('waste_records', function (Blueprint $table) {
            $table->dropForeign(['waste_reason_id']);
            $table->dropForeign(['waste_category_id']);
            $table->dropIndex(['waste_category_id']);
            $table->dropColumn(['waste_reason_id', 'waste_category_id']);
        });

        Schema::table('waste_reasons', function (Blueprint $table) {
            $table->dropForeign(['waste_category_id']);
            $table->dropIndex(['waste_category_id']);
            $table->dropColumn(['waste_category_id', 'is_system']);
        });
    }
};
