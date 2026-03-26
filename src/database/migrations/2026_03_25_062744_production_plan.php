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
        //
        Schema::create('production_plans', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->date('date');
            $table->string('time_slot'); // contoh: 08:00-09:00
            $table->uuid('outlet_id');

            // fingerprint
            $table->timestamps();
            $table->fullstamps();
            $table->softDeletes();
            
            $table->index(['outlet_id', 'date']);
            $table->foreign('outlet_id')->references('id')->on('outlets');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        //
        Schema::dropIfExists('production_plans');
    }
};
