<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Warna tampil plate color.
 *
 * Sampai sekarang warnanya dicocokkan dari NAMA di frontend
 * (`plate-color-badge.tsx`): peta mati berisi "blue", "gold", "choco motive",
 * dan seterusnya. Warna di luar daftar itu — termasuk semua nama berbahasa
 * Indonesia yang dipakai instalasi ini — jatuh ke abu-abu netral.
 *
 * Nama warna juga bukan kunci yang sah: dua brand boleh sama-sama punya "Merah"
 * dengan harga berbeda, dan tidak ada yang menjamin keduanya ingin rona yang
 * sama.
 *
 * Nullable: baris lama yang namanya tidak dikenal peta lama tetap NULL, dan
 * badge jatuh ke warna cadangan. Lebih baik kosong dan jujur daripada terisi
 * tebakan.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('plate_colors', function (Blueprint $table) {
            $table->string('color_hex', 7)->nullable()->after('platename');
        });
    }

    public function down(): void
    {
        Schema::table('plate_colors', function (Blueprint $table) {
            $table->dropColumn('color_hex');
        });
    }
};
