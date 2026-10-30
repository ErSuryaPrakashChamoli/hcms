<?php

namespace App\Domain\Enterprise\Models;

use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * HROne parity SV-26: one HTTP attempt of a webhook delivery (status code, duration, response excerpt). Append-only,
 * written by Webhooks::attempt(); deleted with its delivery (retention).
 */
#[Fillable(['tenant_id', 'webhook_delivery_id', 'attempt', 'response_code', 'duration_ms', 'response_excerpt', 'signed_with_previous', 'attempted_at'])]
class WebhookDeliveryAttempt extends Model
{
    use BelongsToTenant;

    public $timestamps = false;

    protected function casts(): array
    {
        return ['attempt' => 'integer', 'response_code' => 'integer', 'duration_ms' => 'integer', 'signed_with_previous' => 'boolean', 'attempted_at' => 'datetime'];
    }

    public function delivery(): BelongsTo
    {
        return $this->belongsTo(WebhookDelivery::class, 'webhook_delivery_id');
    }
}
