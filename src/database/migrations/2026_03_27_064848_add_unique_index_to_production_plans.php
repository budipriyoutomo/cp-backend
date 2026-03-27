<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
   public function up()
    {
        Schema::table('production_plans', function (Blueprint $table) {
            $table->unique(
                ['outlet_id', 'date', 'time_slot'],
                'production_plans_unique'
            );
        });
    }

    public function down()
    {
        Schema::table('production_plans', function (Blueprint $table) {
            $table->dropUnique('production_plans_unique');
        });
    }
};
