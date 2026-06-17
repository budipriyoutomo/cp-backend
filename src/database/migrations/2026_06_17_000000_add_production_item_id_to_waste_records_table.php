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
        Schema::table('waste_records', function (Blueprint $table) {
            $table->uuid('production_item_id')->nullable()->after('id');

            $table->foreign('production_item_id')
                ->references('id')
                ->on('production_items')
                ->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('waste_records', function (Blueprint $table) {
            $table->dropForeign(['production_item_id']);
            $table->dropColumn('production_item_id');
        });
    }
};
