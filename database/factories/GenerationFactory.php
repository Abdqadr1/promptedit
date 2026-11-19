<?php

namespace Database\Factories;

use App\Enums\GenerationQuality;
use App\Enums\GenerationSize;
use App\Enums\GenerationStatus;
use App\Models\Generation;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Generation>
 */
class GenerationFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'prompt' => fake()->sentence(),
            'size' => GenerationSize::Square,
            'quality' => GenerationQuality::Medium,
            'reference_images' => [],
            'status' => GenerationStatus::Pending,
            'estimated_min_charge_nanos' => 57_506_250,
            'estimated_max_charge_nanos' => 86_256_250,
        ];
    }

    /**
     * Accepted by Pixelforge and waiting in its queue.
     */
    public function queued(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => GenerationStatus::Queued,
            'provider_id' => 'gen_'.Str::random(20),
            'rates' => self::rates(),
            'submitted_at' => now(),
            'last_synced_at' => now(),
        ]);
    }

    /**
     * Finished successfully with the given exact costs (nano-dollars).
     */
    public function succeeded(int $providerCostNanos = 70_000_000, ?int $chargeNanos = null): static
    {
        return $this->queued()->state(fn (array $attributes) => [
            'status' => GenerationStatus::Succeeded,
            'provider_cost_nanos' => $providerCostNanos,
            'charge_nanos' => $chargeNanos ?? intdiv($providerCostNanos * 125 + 99, 100),
            'image_url' => 'https://cdn.example.test/'.Str::random(10).'.png',
            'image_url_expires_at' => now()->addDay(),
            'completed_at' => now(),
        ]);
    }

    /**
     * Failed at Pixelforge; never billed.
     */
    public function failed(): static
    {
        return $this->queued()->state(fn (array $attributes) => [
            'status' => GenerationStatus::Failed,
            'error_code' => 'generation_failed',
            'error_message' => 'The model could not produce an image.',
            'provider_cost_nanos' => 0,
            'charge_nanos' => 0,
            'completed_at' => now(),
        ]);
    }

    /**
     * @return array<string, string>
     */
    public static function rates(): array
    {
        return [
            'input_text_tokens' => '5.00',
            'input_image_tokens' => '10.00',
            'output_tokens' => '40.00',
            'reasoning_tokens' => '12.50',
        ];
    }
}
