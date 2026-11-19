<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Money columns are integer nano-dollars (1e-9 USD) so sums are exact.
     */
    public function up(): void
    {
        Schema::create('generations', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('provider_id')->nullable()->unique();
            $table->text('prompt');
            $table->string('size');
            $table->string('quality');
            $table->json('reference_images');
            $table->string('status')->index();
            $table->string('error_code')->nullable();
            $table->text('error_message')->nullable();
            $table->unsignedSmallInteger('transient_failures')->default(0);
            $table->unsignedBigInteger('estimated_min_charge_nanos');
            $table->unsignedBigInteger('estimated_max_charge_nanos');
            $table->unsignedBigInteger('provider_cost_nanos')->nullable();
            $table->unsignedBigInteger('charge_nanos')->nullable();
            $table->json('usage')->nullable();
            $table->json('rates')->nullable();
            $table->text('image_url')->nullable();
            $table->timestamp('image_url_expires_at')->nullable();
            $table->string('image_path')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('completed_at')->nullable()->index();
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('generations');
    }
};
