<?php

namespace App\Domain\Integration\Models;

use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Phase 14 (ADR-0012): one event received from an external system.
 *
 * received → processing → succeeded | retrying → … → dead_letter, or failed (refused by its handler).
 * failed / dead_letter → received again only by an explicit, audited reprocess.
 *
 * Unique per (tenant, system, idempotency key): a duplicate delivery finds the existing row and
 * never repeats the business action. The payload is encrypted and purged after processing plus
 * the retention window. Derived event-log rows: every state change is audited explicitly by
 * InboundEvents (not Auditable, which would log the payload).
 */
#[Fillable(['tenant_id', 'integration_system_id', 'event_type', 'external_event_id', 'idempotency_key', 'correlation_id', 'status', 'attempts', 'reprocess_count', 'last_error', 'payload', 'payload_sha256', 'payload_size', 'payload_metadata', 'result', 'received_at', 'processed_at', 'next_attempt_at', 'payload_purged_at'])]
#[Hidden(['payload'])]
class InboundEvent extends Model
{
    use BelongsToTenant;

    public const STATUSES = ['received' => 'Received', 'processing' => 'Processing', 'succeeded' => 'Succeeded', 'retrying' => 'Retrying', 'failed' => 'Failed (refused)', 'dead_letter' => 'Dead letter'];

    protected $attributes = ['status' => 'received', 'attempts' => 0, 'reprocess_count' => 0];

    protected static function booted(): void
    {
        static::updating(function (self $e): void {
            if (array_intersect(array_keys($e->getDirty()), ['integration_system_id', 'event_type', 'external_event_id', 'idempotency_key', 'payload_sha256']) !== []) {
                throw new \RuntimeException('A received event never changes its identity.');
            }
        });
        static::deleting(fn () => throw new \RuntimeException('Inbound events are history; their payloads are purged, the rows are kept.'));
    }

    protected function casts(): array
    {
        return [
            'payload' => 'encrypted:array', 'payload_metadata' => 'array', 'result' => 'array', 'attempts' => 'integer', 'reprocess_count' => 'integer', 'payload_size' => 'integer',
            'received_at' => 'datetime', 'processed_at' => 'datetime', 'next_attempt_at' => 'datetime', 'payload_purged_at' => 'datetime',
        ];
    }

    public function system(): BelongsTo
    {
        return $this->belongsTo(IntegrationSystem::class, 'integration_system_id');
    }

    public function isTerminal(): bool
    {
        return in_array($this->status, ['succeeded', 'failed', 'dead_letter'], true);
    }
}
