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
        Schema::create('plate_colors', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('platename');
            $table->decimal('price',15,2)->default(0);
            $table->string('description')->nullable();
            $table->decimal('target_foodcost',5,2)->nullable();
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
        Schema::dropIfExists('plate_colors');
    }
};
