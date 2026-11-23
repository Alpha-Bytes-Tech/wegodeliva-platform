<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * An inbound gateway webhook with a valid signature, stored once (spec 06 §6.3).
 *
 * @property int $id
 * @property string $gateway
 * @property string $event_type
 * @property string $event_key
 * @property string|null $reference
 * @property array<string, mixed> $payload
 * @property Carbon|null $processed_at
 * @property string|null $processing_error
 */
class WebhookEvent extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = ['*'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['payload' => 'array', 'processed_at' => 'datetime'];
    }
}
