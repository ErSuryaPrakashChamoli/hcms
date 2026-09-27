<?php

namespace App\Domain\Enterprise\Models;

use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One event delivery attempt log: pending → delivered | failed (after max attempts). */
#[Fillable(['tenant_id', 'webhook_endpoint_id', 'event', 'event_id', 'payload', 'status', 'attempts', 'response_code', 'response_excerpt', 'next_attempt_at', 'delivered_at'])]
class WebhookDelivery extends Model
{
    use BelongsToTenant;

    protected $attributes = ['status' => 'pending'];

    protected function casts(): array
    {
        return ['payload' => 'array', 'attempts' => 'integer', 'response_code' => 'integer', 'next_attempt_at' => 'datetime', 'delivered_at' => 'datetime'];
    }

    public function endpoint(): BelongsTo
    {
        return $this->belongsTo(WebhookEndpoint::class, 'webhook_endpoint_id');
    }
}
