<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Pixelforge webhook events that have been received, keyed by event id for deduplication.
 *
 * @property string $id
 * @property string $type
 * @property string|null $generation_id
 * @property array<string, mixed> $payload
 * @property Carbon $received_at
 */
class WebhookEvent extends Model
{
    public $incrementing = false;

    public $timestamps = false;

    protected $keyType = 'string';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'received_at' => 'datetime',
        ];
    }
}
