<?php

namespace App\Domain\Audit\Services;

use App\Domain\Audit\Models\AuditEvent;
use App\Support\Tenancy\TenantContext;

final class AuditIntegrityVerifier
{
    public function __construct(private readonly TenantContext $tenants) {}

    /**
     * Walk a tenant's chain in insertion order, recomputing every hash.
     *
     * @return array{checked: int, valid: bool, broken_event_id: ?string, reason: ?string}
     */
    public function verify(?int $tenantId): array
    {
        return $this->tenants->bypass(function () use ($tenantId) {
            $checked = 0;
            $expectedPrevious = null;

            $query = AuditEvent::query()
                ->with('fieldChanges')
                ->when($tenantId === null, fn ($q) => $q->whereNull('tenant_id'), fn ($q) => $q->where('tenant_id', $tenantId))
                ->orderBy('id');

            foreach ($query->lazy(500) as $event) {
                $checked++;

                if ($event->previous_hash !== $expectedPrevious) {
                    return $this->broken($checked, $event, 'previous_hash does not link to the preceding event');
                }

                if ($event->recomputeHash() !== $event->hash) {
                    return $this->broken($checked, $event, 'stored hash does not match recomputed hash');
                }

                $expectedPrevious = $event->hash;
            }

            return ['checked' => $checked, 'valid' => true, 'broken_event_id' => null, 'reason' => null];
        });
    }

    /** @return array{checked: int, valid: bool, broken_event_id: ?string, reason: ?string} */
    private function broken(int $checked, AuditEvent $event, string $reason): array
    {
        return ['checked' => $checked, 'valid' => false, 'broken_event_id' => $event->id, 'reason' => $reason];
    }
}
