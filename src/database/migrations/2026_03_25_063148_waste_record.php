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
        Schema::create('waste_records', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->uuid('menu_id');
            $table->uuid('outlet_id');

            $table->string('plate_color');
            $table->integer('quantity');

            $table->text('reason');
            $table->timestamp('recorded_at');

            // fingerprint
            $table->timestamps();
            $table->fullstamps();
            $table->softDeletes();
            
            $table->index(['outlet_id', 'recorded_at']);

            $table->foreign('menu_id')->references('id')->on('menus');
            $table->foreign('outlet_id')->references('id')->on('outlets');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        //
        Schema::dropIfExists('waste_records');
    }
};
