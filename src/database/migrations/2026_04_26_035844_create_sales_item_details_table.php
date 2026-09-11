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
        Schema::create('sales_item_details', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->uuid('sales_item_id');

            $table->uuid('menu_id');
            $table->string('menu_name');

            $table->integer('total_produced')->default(0);
            $table->integer('total_sold')->default(0);
            $table->integer('total_wasted')->default(0);

            $table->integer('adjustment')->default(0);
            $table->integer('compensation')->default(0);

            // fingerprint
            $table->timestamps();
            $table->fullstamps();
            $table->softDeletes();

            $table->foreign('sales_item_id')
                ->references('id')
                ->on('sales_items')
                ->cascadeOnDelete();

            $table->index(['sales_item_id', 'menu_id']);
   
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('sales_item_details');
    }
};
