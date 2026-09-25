<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Time slot produksi per brand.
 *
 * Sebelum ini daftarnya dihasilkan di frontend: 10:00 sampai 21:00, kelipatan
 * 30 menit, sama untuk semua brand dan semua outlet. Jam buka yang berbeda
 * tidak bisa diikuti sama sekali.
 *
 * Jam mulai dan selesai bebas per baris, bukan satu panjang tetap per brand —
 * jam ramai boleh dipecah lebih pendek daripada jam sepi.
 *
 * Penandanya dipilih per baris (`time_marker_id`), bukan dihitung dari sisa
 * bagi nomor slot. Itu yang membuat bug siklus 150 menit tidak bisa terulang:
 * yang menentukan warna adalah baris ini, dan admin melihatnya langsung.
 * Nullable supaya slot bisa dibuat dulu, penandanya menyusul.
 *
 * Catatan untuk pembaca berikutnya: `production_plans.time_slot` TIDAK menunjuk
 * ke tabel ini. Ia tetap teks seperti "10:00-10:30" dan hanya divalidasi ke
 * daftar ini saat plan disimpan. Konsekuensinya disengaja — mengubah jam sebuah
 * slot tidak boleh mengubah plan yang sudah tersimpan, sama seperti `wasted_at`
 * pada carry-over: laporan hari lalu tidak berubah karena setelan hari ini.
 * Plan lama yang labelnya tidak lagi ada di master ditampilkan apa adanya
 * sebagai "slot tidak dikenal".
 */
return new class extends Migration
{
    private const START_UNIQUE = 'time_slots_brand_start_unique';

    public function up(): void
    {
        Schema::create('time_slots', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->uuid('brand_id');

            $table->time('start_time');
            $table->time('end_time');

            $table->uuid('time_marker_id')->nullable();

            // Lihat alasan "bukan unique" di migration time_markers.
            $table->integer('sort_order')->default(0);

            $table->boolean('is_active')->default(true);

            $table->timestamps();
            $table->fullstamps();
            $table->softDeletes();

            $table->index(['brand_id', 'sort_order']);
            $table->index(['brand_id', 'start_time']);

            $table->foreign('brand_id')->references('id')->on('brands');

            // nullOnDelete supaya penanda yang benar-benar dihapus tidak
            // menahan slotnya. Jalur normal aplikasi memakai soft delete, yang
            // tidak menyentuh foreign key sama sekali — jadi ini hanya jaring
            // untuk penghapusan manual di basis data.
            $table->foreign('time_marker_id')->references('id')->on('time_markers')->nullOnDelete();
        });

        // Satu brand tidak boleh punya dua slot yang mulai di jam sama. Tumpang
        // tindih yang lebih halus (10:00-11:00 vs 10:30-11:30) tidak bisa
        // dijaga index; itu divalidasi di request, Fase 2.
        DB::statement(
            'CREATE UNIQUE INDEX ' . self::START_UNIQUE . ' ON time_slots (brand_id, start_time) '
            . 'WHERE deleted_at IS NULL'
        );
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS ' . self::START_UNIQUE);

        Schema::dropIfExists('time_slots');
    }
};
