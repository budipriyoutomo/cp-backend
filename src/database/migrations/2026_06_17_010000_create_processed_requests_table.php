<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Stores the outcome of mutating requests carrying an X-Client-Request-Id,
     * so replays from the offline queue return the original response instead of
     * re-executing (idempotency).
     */
    public function up(): void
    {
        Schema::create('processed_requests', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('request_id')->unique();
            $table->string('method', 10);
            $table->string('path');
            $table->unsignedBigInteger('user_id')->nullable();
            $table->integer('response_status');
            $table->longText('response_body')->nullable();
            $table->timestamps();

            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('processed_requests');
    }
};
