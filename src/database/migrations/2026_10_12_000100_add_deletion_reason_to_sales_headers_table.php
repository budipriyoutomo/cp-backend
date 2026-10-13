<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Alasan admin membatalkan draft Sales Input.
 *
 * Koreksi Status Produksi terkunci begitu Sales Input tanggal itu ada, draft
 * sekalipun. Membatalkan draft (soft delete) adalah jalan keluarnya, dan karena
 * itu menghapus pekerjaan operator, alasannya disimpan di baris itu sendiri —
 * bersama `deleted_at`/`deleted_by` yang sudah ada — bukan hanya di log.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales_headers', function (Blueprint $table) {
            $table->text('deletion_reason')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('sales_headers', function (Blueprint $table) {
            $table->dropColumn('deletion_reason');
        });
    }
};
