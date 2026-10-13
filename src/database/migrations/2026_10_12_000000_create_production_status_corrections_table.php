<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Jejak Koreksi Status Produksi (sold ⇄ waste).
 *
 * `final_status` dianggap final di seluruh sistem; koreksi adalah satu-satunya
 * jalan mengubahnya, jadi setiap perubahan harus bisa ditelusuri: siapa, kapan,
 * kenapa, dan nilai apa yang ditimpa. `production_items.updated_by` tidak cukup
 * — ia tertimpa perubahan berikutnya dan tidak menyimpan nilai lama.
 *
 * Header = satu kali admin menekan Simpan. Item = satu piring yang diubah,
 * lengkap dengan stempel lamanya supaya koreksi bisa dibalik kelak.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('production_status_corrections', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->uuid('outlet_id');
            $table->date('production_date');
            $table->text('reason');
            $table->uuid('waste_reason_id')->nullable();

            $table->unsignedInteger('total_to_waste')->default(0);
            $table->unsignedInteger('total_to_sold')->default(0);

            $table->timestamps();
            $table->fullstamps();
            $table->softDeletes();

            $table->foreign('outlet_id')->references('id')->on('outlets');
            $table->foreign('waste_reason_id')->references('id')->on('waste_reasons');
            $table->index(['outlet_id', 'production_date']);
        });

        Schema::create('production_status_correction_items', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->uuid('correction_id');
            $table->uuid('production_item_id');
            $table->uuid('menu_id')->nullable();
            $table->string('plate_color')->nullable();

            $table->string('from_status');
            $table->string('to_status');
            $table->timestamp('old_sold_at')->nullable();
            $table->timestamp('old_wasted_at')->nullable();

            // waste_records yang dibuat (→ waste) atau dihapus (→ sold).
            $table->uuid('waste_record_id')->nullable();

            $table->timestamps();
            $table->fullstamps();
            $table->softDeletes();

            $table->foreign('correction_id')
                ->references('id')->on('production_status_corrections')
                ->cascadeOnDelete();
            $table->foreign('production_item_id')->references('id')->on('production_items');
            $table->index('production_item_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('production_status_correction_items');
        Schema::dropIfExists('production_status_corrections');
    }
};
