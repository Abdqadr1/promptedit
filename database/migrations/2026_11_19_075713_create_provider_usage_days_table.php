<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * One row per UTC day as reported by Pixelforge's GET /v1/usage. A missing row means the day
     * has not been synced yet (Pixelforge reports zero-usage days explicitly).
     */
    public function up(): void
    {
        Schema::create('provider_usage_days', function (Blueprint $table) {
            $table->date('date')->primary();
            $table->unsignedInteger('generations');
            $table->unsignedBigInteger('input_text_tokens');
            $table->unsignedBigInteger('input_image_tokens');
            $table->unsignedBigInteger('output_tokens');
            $table->unsignedBigInteger('reasoning_tokens');
            $table->unsignedBigInteger('cost_nanos');
            $table->timestamp('synced_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('provider_usage_days');
    }
};
