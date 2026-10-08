<?php

namespace App\Domain\Payments\Models;

use App\Domain\Payments\Enums\ProviderEventStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use RuntimeException;

/**
 * SaaS.7: a verified webhook from a payment provider (platform-level: it arrives without a tenant; the tenant is
 * resolved from the verified provider reference of a known payment, never from the payload). Unique per provider
 * and event id, so a duplicate or replayed delivery is recognised; the encrypted payload and its SHA-256 never
 * change, so a different body under a known id is detected.
 */
#[Fillable(['provider', 'event_id', 'type', 'payload', 'payload_sha256', 'provider_reference', 'status', 'outcome', 'resolved_tenant_id', 'payment_id', 'attempts',
    'claimed_until', 'received_at', 'processed_at', 'last_error'])]
#[Hidden(['payload'])]
class PaymentProviderEvent extends Model
{
    protected static function booted(): void
    {
        static::updating(function (self $event): void {
            if ($event->isDirty(['provider', 'event_id', 'type', 'payload', 'payload_sha256', 'received_at'])) {
                throw new RuntimeException('A provider event keeps what was received.');
            }
        });
        static::deleting(function (): void {
            throw new RuntimeException('Provider events are never deleted.');
        });
    }

    protected function casts(): array
    {
        return ['payload' => 'encrypted:array', 'status' => ProviderEventStatus::class, 'attempts' => 'integer', 'claimed_until' => 'datetime',
            'received_at' => 'datetime', 'processed_at' => 'datetime'];
    }
}
