<?php

namespace App\Domain\Enterprise\Models;

use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One event delivery (the outbox row): pending → delivered | dead_letter (after max attempts; replayable). Phase 14: correlation id, one row per endpoint and event. */
#[Fillable(['tenant_id', 'webhook_endpoint_id', 'event', 'event_id', 'correlation_id', 'payload', 'status', 'attempts', 'replay_count', 'response_code', 'response_excerpt', 'next_attempt_at', 'delivered_at', 'dead_lettered_at'])]
class WebhookDelivery extends Model
{
    use BelongsToTenant;

    protected $attributes = ['status' => 'pending'];

    protected function casts(): array
    {
        return ['payload' => 'array', 'attempts' => 'integer', 'response_code' => 'integer', 'next_attempt_at' => 'datetime', 'delivered_at' => 'datetime', 'dead_lettered_at' => 'datetime', 'replay_count' => 'integer'];
    }

    public function endpoint(): BelongsTo
    {
        return $this->belongsTo(WebhookEndpoint::class, 'webhook_endpoint_id');
    }
}
