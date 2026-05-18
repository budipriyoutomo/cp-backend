<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('closing_reports', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->uuid('outlet_id');
            $table->date('date');
            $table->string('status')->default('draft');
            $table->string('kitchen_leader')->nullable();
            $table->string('operation_leader')->nullable();
            $table->json('waste_photo_urls')->nullable();
            $table->text('notes')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->uuid('submitted_by')->nullable();

            $table->timestamps();
            $table->fullstamps();
            $table->softDeletes();

            $table->foreign('outlet_id')->references('id')->on('outlets')->cascadeOnDelete();
            $table->unique(['outlet_id', 'date']);
            $table->index(['outlet_id', 'date', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('closing_reports');
    }
};
