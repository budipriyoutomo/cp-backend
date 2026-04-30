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
        Schema::create('sales_items', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->uuid('sales_id');
            $table->string('plate_color_id');

            $table->integer('pos_sold')->default(0);
            $table->integer('production_sold')->default(0);
            $table->integer('production_waste')->default(0);

            $table->integer('adjustment')->default(0);
            $table->integer('compensation')->default(0);
            $table->integer('selisih')->default(0);

            // fingerprint
            $table->timestamps();
            $table->uuid('created_by')->nullable();
            $table->uuid('updated_by')->nullable();
            $table->softDeletes();
            $table->uuid('deleted_by')->nullable();

            $table->foreign('sales_id')->references('id')->on('sales_headers')->cascadeOnDelete();

            $table->index(['sales_id', 'plate_color_id']);
    

        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('sales_items');
    }
};
