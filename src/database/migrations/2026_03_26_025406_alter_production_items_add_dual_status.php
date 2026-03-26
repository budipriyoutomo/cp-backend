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
        Schema::table('production_items', function (Blueprint $table) {

            // 🔥 rename status → belt_status (kalau sudah ada)
            if (Schema::hasColumn('production_items', 'status')) {
                $table->renameColumn('status', 'belt_status');
            }

            // 🔥 final status
            $table->string('final_status')->nullable()->after('belt_status');

            $table->timestamp('sold_at')->nullable();
            $table->timestamp('wasted_at')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        //
        Schema::table('production_items', function (Blueprint $table) {

            // 🔥 rename belt_status → status (kalau sudah ada)
            if (Schema::hasColumn('production_items', 'belt_status')) {
                $table->renameColumn('belt_status', 'status');
            }

            $table->dropColumn('final_status');
            $table->dropColumn('sold_at');
            $table->dropColumn('wasted_at');
        });
    }
};
