<?php

namespace App\Http\Resources;

use App\Models\Generation;
use App\Services\Pixelforge\Pricing;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

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
            'image_expired' => $this->image_path === null && $this->image_url !== null && $this->image_url_expires_at?->isPast(),
            'created_at' => $this->created_at?->toIso8601String(),
            'completed_at' => $this->completed_at?->toIso8601String(),
        ];
    }

    /**
     * Prefer our stored copy; fall back to Pixelforge's signed URL while it is still valid.
     */
    private function imageSrc(): ?string
    {
        if ($this->image_path !== null) {
            return Storage::disk(config('services.pixelforge.image_disk'))->url($this->image_path);
        }

        if ($this->image_url !== null && ! $this->image_url_expires_at?->isPast()) {
            return $this->image_url;
        }

        return null;
    }
}
