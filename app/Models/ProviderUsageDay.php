<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\ProviderUsageDayFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Pixelforge's own billing for one UTC day (GET /v1/usage), stored so reports can cover any range.
 *
 * @property string $date
 * @property int $generations
 * @property int $input_text_tokens
 * @property int $input_image_tokens
 * @property int $output_tokens
 * @property int $reasoning_tokens
 * @property int $cost_nanos
 * @property CarbonImmutable $synced_at
 */
#[Fillable([
    'date', 'generations', 'input_text_tokens', 'input_image_tokens', 'output_tokens',
    'reasoning_tokens', 'cost_nanos', 'synced_at',
])]
class ProviderUsageDay extends Model
{
    /** @use HasFactory<ProviderUsageDayFactory> */
    use HasFactory;

    protected $primaryKey = 'date';

    protected $keyType = 'string';

    public $incrementing = false;

    public $timestamps = false;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'generations' => 'integer',
            'input_text_tokens' => 'integer',
            'input_image_tokens' => 'integer',
            'output_tokens' => 'integer',
            'reasoning_tokens' => 'integer',
            'cost_nanos' => 'integer',
            'synced_at' => 'datetime',
        ];
    }
}
