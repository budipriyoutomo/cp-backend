<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('posdata', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('plate_color_id')->constrained('plate_colors')->nullOnDelete();
            $table->uuid('outlet_id')->constrained('outlets')->nullOnDelete();
            $table->date('date');
            $table->integer('sold')->default(0);
 
            $table->timestamps();
            $table->fullstamps();
            $table->softDeletes();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('posdata');
    }
};
