<?php

namespace App\Domain\Enterprise\Models;

use App\Domain\Identity\Models\User;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One event delivery (the outbox row): pending → delivered | dead_letter (after max attempts; replayable). Phase 14: correlation id, one row per endpoint and event.
 * HROne parity SV-26: the last attempt's duration, every attempt (webhook_delivery_attempts) and the acknowledgement of a dead letter.
 */
#[Fillable(['tenant_id', 'webhook_endpoint_id', 'event', 'event_id', 'correlation_id', 'payload', 'status', 'attempts', 'replay_count', 'response_code', 'response_excerpt', 'duration_ms', 'next_attempt_at', 'delivered_at', 'dead_lettered_at', 'acknowledged_at', 'acknowledged_by'])]
class WebhookDelivery extends Model
{
    use BelongsToTenant;

    public const STATUSES = ['pending' => 'Pending', 'delivered' => 'Delivered', 'dead_letter' => 'Dead letter', 'failed' => 'Failed (before Phase 14)'];

    /** Terminal failures: replayable, and acknowledged to leave the endpoint's failure count. */
    public const FAILED = ['dead_letter', 'failed'];

    protected $attributes = ['status' => 'pending'];

    protected function casts(): array
    {
        return ['payload' => 'array', 'attempts' => 'integer', 'response_code' => 'integer', 'duration_ms' => 'integer', 'next_attempt_at' => 'datetime', 'delivered_at' => 'datetime',
            'dead_lettered_at' => 'datetime', 'acknowledged_at' => 'datetime', 'replay_count' => 'integer'];
    }

    public function endpoint(): BelongsTo
    {
        return $this->belongsTo(WebhookEndpoint::class, 'webhook_endpoint_id');
    }

    /** SV-26: every HTTP attempt, oldest first. */
    public function attemptLog(): HasMany
    {
        return $this->hasMany(WebhookDeliveryAttempt::class)->orderBy('attempt')->orderBy('id');
    }

    public function acknowledger(): BelongsTo
    {
        return $this->belongsTo(User::class, 'acknowledged_by');
    }

    public function isFailed(): bool
    {
        return in_array($this->status, self::FAILED, true);
    }

    public function isAcknowledged(): bool
    {
        return ($this->getAttributes()['acknowledged_at'] ?? null) !== null;
    }
}
