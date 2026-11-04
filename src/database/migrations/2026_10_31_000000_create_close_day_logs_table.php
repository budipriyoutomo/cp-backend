<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Jejak audit tombol Tutup Hari di `/kitchen/conveyor`.
 *
 * Tutup Hari menandai semua sisa piring hari itu sebagai `sold` lewat satu
 * UPDATE massal. Yang tersisa di `production_items` hanya `sold_at` dan
 * `updated_by` — tidak bisa dibedakan dari penutupan lain, dan tertimpa kalau
 * piring itu dikoreksi. Tabel ini menjawab: kapan ditekan, oleh siapa, di
 * outlet mana, dan berapa piring yang ikut tertutup.
 *
 * Satu baris = satu kali tombol benar-benar dieksekusi server, termasuk yang
 * menutup 0 piring. Request ulang dari antrean offline (X-Client-Request-Id
 * yang sama) diputar ulang middleware Idempotency dan tidak menambah baris.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('close_day_logs', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->uuid('outlet_id');
            // Hari produksi yang ditutup — `today()` saat dieksekusi.
            $table->date('business_date');
            $table->unsignedInteger('closed_count')->default(0);
            $table->timestamp('executed_at');

            $table->string('client_request_id')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();

            $table->timestamps();
            $table->fullstamps();
            $table->softDeletes();

            $table->foreign('outlet_id')->references('id')->on('outlets');
            $table->index(['outlet_id', 'business_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('close_day_logs');
    }
};
