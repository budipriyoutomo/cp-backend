<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Penanda waktu — lingkaran warna yang menempel ke piring supaya staf tahu
 * piring itu dibuat di slot yang mana.
 *
 * Sebelum ini daftarnya dikunci mati di frontend: lima warna, ditulis ulang di
 * tiga berkas, dan dipilih dari sisa bagi nomor slot. Akibatnya siklus penuh
 * hanya 150 menit sementara 206 dari 285 menu punya `shelf_life` 180 menit —
 * dua batch berwarna sama bisa ada di belt bersamaan, yang justru hal yang
 * seharusnya dicegah penanda.
 *
 * Sekarang jumlah dan warnanya ditentukan per brand, jadi brand yang menu-nya
 * berumur panjang bisa menambah penanda keenam.
 *
 * `brand_id` di sini NOT NULL, beda dengan `menus`/`plate_colors` yang masih
 * boleh NULL sebagai keadaan transisi. Tabel ini baru, tidak ada baris warisan
 * yang perlu ditampung, dan setelan tanpa pemilik tidak punya arti.
 */
return new class extends Migration
{
    private const LABEL_UNIQUE = 'time_markers_brand_label_unique';

    public function up(): void
    {
        Schema::create('time_markers', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->uuid('brand_id');
            $table->string('label', 50);

            // '#RRGGBB'. Warna teks di atasnya TIDAK disimpan — ia dihitung dari
            // kecerahan warna ini, jadi tidak ada dua nilai yang bisa menyimpang.
            $table->string('color_hex', 7);

            // Urutan tampil. Sengaja BUKAN unique: menukar urutan dua baris
            // melanggar unique di tengah proses, dan SQLite tidak punya
            // constraint yang bisa ditunda sampai akhir transaksi.
            $table->integer('sort_order')->default(0);

            $table->boolean('is_active')->default(true);

            $table->timestamps();
            $table->fullstamps();
            $table->softDeletes();

            $table->index(['brand_id', 'sort_order']);
            $table->foreign('brand_id')->references('id')->on('brands');
        });

        // Partial index, mengikuti plate_colors_brand_platename_unique: baris
        // soft-deleted harus dikecualikan, karena menghapus "Biru" lalu
        // membuatnya lagi adalah operasi yang sah.
        DB::statement(
            'CREATE UNIQUE INDEX ' . self::LABEL_UNIQUE . ' ON time_markers (brand_id, label) '
            . 'WHERE deleted_at IS NULL'
        );
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS ' . self::LABEL_UNIQUE);

        Schema::dropIfExists('time_markers');
    }
};
