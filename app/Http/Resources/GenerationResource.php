<?php

namespace App\Http\Resources;

use App\Models\Generation;
use App\Services\Pixelforge\Pricing;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Generation
 */
class GenerationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'prompt' => $this->prompt,
            'size' => $this->size->value,
            'quality' => $this->quality->value,
            'reference_images' => $this->reference_images,
            'status' => $this->status->value,
            'is_terminal' => $this->status->isTerminal(),
            'error_code' => $this->error_code,
            'error_message' => $this->error_message,
            'estimated_min_charge' => Pricing::formatNanos($this->estimated_min_charge_nanos),
            'estimated_max_charge' => Pricing::formatNanos($this->estimated_max_charge_nanos),
            'charge' => $this->charge_nanos === null ? null : Pricing::formatNanos($this->charge_nanos),
            'charge_exact' => $this->charge_nanos === null ? null : Pricing::formatNanos($this->charge_nanos, 9),
            'image_src' => $this->imageSrc(),
            'image_stored' => $this->image_path !== null,
            'image_url_expires_at' => $this->image_url_expires_at?->toIso8601String(),
            'provider_id' => $this->provider_id,
            'usage' => $this->usage,
            'provider_cost' => $this->when(
                (bool) $request->user()?->is_admin && $this->provider_cost_nanos !== null,
                fn (): string => Pricing::formatNanos((int) $this->provider_cost_nanos, 9),
            ),
            'created_at' => $this->created_at?->toIso8601String(),
            'submitted_at' => $this->submitted_at?->toIso8601String(),
            'completed_at' => $this->completed_at?->toIso8601String(),
        ];
    }

    /**
     * Prefer our stored copy (served privately to its owner); otherwise hand the browser Pixelforge's signed URL whatever its
     * stated expiry. The page itself detects a URL that no longer loads and shows a placeholder.
     */
    private function imageSrc(): ?string
    {
        if ($this->image_path !== null) {
            return route('generations.image', $this->id, absolute: false);
        }

        return $this->image_url;
    }
}
