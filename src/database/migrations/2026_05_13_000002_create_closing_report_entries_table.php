<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('closing_report_entries', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->uuid('closing_report_id');
            $table->uuid('plate_color_id');

            $table->integer('produced')->default(0);
            $table->integer('sold')->default(0);
            $table->integer('waste')->default(0);
            $table->integer('pos_sold')->default(0);
            $table->integer('adjustment')->default(0);
            $table->integer('compensation')->default(0);
            $table->text('compensation_reason')->nullable();
            $table->integer('selisih')->default(0);

            $table->timestamps();
            $table->fullstamps();
            $table->softDeletes();

            $table->foreign('closing_report_id')->references('id')->on('closing_reports')->cascadeOnDelete();
            $table->foreign('plate_color_id')->references('id')->on('plate_colors')->cascadeOnDelete();
            $table->unique(['closing_report_id', 'plate_color_id'], 'closing_report_plate_color_unique');
            $table->index(['closing_report_id', 'plate_color_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('closing_report_entries');
    }
};
