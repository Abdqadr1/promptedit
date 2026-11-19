<?php

namespace Database\Factories;

use App\Models\ProviderUsageDay;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProviderUsageDay>
 */
class ProviderUsageDayFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'date' => now()->utc()->toDateString(),
            'generations' => 0,
            'input_text_tokens' => 0,
            'input_image_tokens' => 0,
            'output_tokens' => 0,
            'reasoning_tokens' => 0,
            'cost_nanos' => 0,
            'synced_at' => now(),
        ];
    }
}
