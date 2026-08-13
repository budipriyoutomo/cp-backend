<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Dead letters for the POS consumer.
 *
 * A message that failed to map (unknown plate color name or outlet code) used
 * to be nacked with requeue=false and no dead-letter exchange — gone for good,
 * leaving only a log line. Renaming a plate color could break POS ingest for a
 * whole day with nothing to replay.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('failed_pos_messages', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->longText('payload');          // raw message body
            $table->text('error');
            $table->unsignedInteger('attempts')->default(1);
            $table->timestamp('resolved_at')->nullable();

            $table->timestamps();

            $table->index('resolved_at');
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('failed_pos_messages');
    }
};
