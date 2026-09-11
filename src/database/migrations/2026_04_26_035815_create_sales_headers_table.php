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
        Schema::create('sales_headers', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->string('outlet_id');
            $table->date('date');
            $table->string('status')->default('draft'); // draft | submitted

            // fingerprint
            $table->timestamps();
            $table->fullstamps();
            $table->softDeletes();

            $table->index(['outlet_id', 'date']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('sales_headers');
    }
};
