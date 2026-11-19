<?php

namespace App\Models;

use App\Enums\GenerationQuality;
use App\Enums\GenerationSize;
use App\Enums\GenerationStatus;
use Carbon\CarbonImmutable;
use Database\Factories\GenerationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $id
 * @property int $user_id
 * @property string|null $provider_id
 * @property string $prompt
 * @property GenerationSize $size
 * @property GenerationQuality $quality
 * @property list<string> $reference_images
 * @property GenerationStatus $status
 * @property string|null $error_code
 * @property string|null $error_message
 * @property int $estimated_min_charge_nanos
 * @property int $estimated_max_charge_nanos
 * @property int|null $provider_cost_nanos
 * @property int|null $charge_nanos
 * @property array<string, int>|null $usage
 * @property array<string, string>|null $rates
 * @property string|null $image_url
 * @property CarbonImmutable|null $image_url_expires_at
 * @property string|null $image_path
 * @property CarbonImmutable|null $submitted_at
 * @property CarbonImmutable|null $completed_at
 * @property CarbonImmutable|null $last_synced_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read User $user
 */
#[Fillable([
    'user_id', 'provider_id', 'prompt', 'size', 'quality', 'reference_images', 'status',
    'error_code', 'error_message', 'estimated_min_charge_nanos', 'estimated_max_charge_nanos',
    'provider_cost_nanos', 'charge_nanos', 'usage', 'rates', 'image_url', 'image_url_expires_at',
    'image_path', 'submitted_at', 'completed_at', 'last_synced_at',
])]
class Generation extends Model
{
    /** @use HasFactory<GenerationFactory> */
    use HasFactory, HasUlids;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'size' => GenerationSize::class,
            'quality' => GenerationQuality::class,
            'status' => GenerationStatus::class,
            'reference_images' => 'array',
            'usage' => 'array',
            'rates' => 'array',
            'estimated_min_charge_nanos' => 'integer',
            'estimated_max_charge_nanos' => 'integer',
            'provider_cost_nanos' => 'integer',
            'charge_nanos' => 'integer',
            'image_url_expires_at' => 'datetime',
            'submitted_at' => 'datetime',
            'completed_at' => 'datetime',
            'last_synced_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @param  Builder<self>  $query
     */
    public function scopeUnfinished(Builder $query): void
    {
        $query->whereNotIn('status', [GenerationStatus::Succeeded, GenerationStatus::Failed]);
    }
}
