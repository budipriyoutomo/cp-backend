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
        Schema::create('menus', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('menuname');
            $table->string('description')->nullable();
            $table->string('image')->nullable();
            $table->decimal('price',15,2)->default(0);
            $table->integer('shelf_life')->nullable();
            
            $table->uuid('plate_color_id');
            $table->foreign('plate_color_id')->references('id')->on('plate_colors')->cascadeOnDelete();

            $table->boolean('is_active')->default(true);

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
        Schema::dropIfExists('menus');
    }
};
