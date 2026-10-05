<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Outbox untuk pesan `closingreport.submitted` ke BI.
 *
 * Baris ditulis di transaksi yang sama dengan submit, lalu dipublish setelah
 * commit. Kalau RabbitMQ mati, submit tetap sukses dan baris ini menunggu
 * dikirim ulang oleh scheduler — tanpa outbox, pesan hilang begitu saja.
 *
 * Satu closing report = satu pesan (unique), jadi re-submit atau request yang
 * diputar ulang tidak bisa mengantre pesan kedua.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('closing_report_outbox', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->uuid('closing_report_id')->unique();
            $table->json('payload');
            $table->string('status')->default('pending');
            $table->unsignedInteger('attempts')->default(0);
            $table->text('last_error')->nullable();
            $table->timestamp('next_attempt_at')->nullable();
            $table->timestamp('published_at')->nullable();

            $table->timestamps();

            $table->foreign('closing_report_id')
                ->references('id')->on('closing_reports')
                ->cascadeOnDelete();

            $table->index(['status', 'next_attempt_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('closing_report_outbox');
    }
};
