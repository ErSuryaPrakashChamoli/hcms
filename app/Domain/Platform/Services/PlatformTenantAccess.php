<?php

namespace App\Domain\Platform\Services;

use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Audit\Services\AuditRecorder;
use App\Domain\Identity\Models\User;
use App\Domain\Platform\Models\Tenant;
use Illuminate\Contracts\Session\Session;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * SaaS.2: controlled platform-operator access to a tenant (replaces the unaudited "Enter").
 *
 * operator → enter(tenant, reason, optional ticket reference) → time-boxed grant in the operator's own session
 * → every request inside the tenant is linked to the grant → exit, sign-out or expiry.
 *
 * Each start and end is audited twice:
 * - on the platform chain, Markedge's record of what its operators did;
 * - on the tenant's own chain, so the customer can see who at Markedge looked at their data, when and why.
 *
 * The audit answers who, which tenant, when, why (reason and reference), under what authority (platform
 * operator), in what mode (full support access) and when access ended (exit, sign-out or expiry).
 *
 * The operator stays a platform identity: no tenant user, employee or role is created, and nothing about
 * the operator is written to the tenant except the audit events.
 */
final class PlatformTenantAccess
{
    public const SESSION_KEY = 'platform.tenant_access';

    public const MODE = 'full_support';

    public function __construct(private readonly AuditRecorder $audit) {}

    /** @return array{id: string, tenant_id: int, reason: string, reference: ?string, mode: string, entered_at: string, expires_at: string} */
    public function enter(User $operator, Tenant $tenant, string $reason, ?string $reference, Session $session): array
    {
        if (! $operator->isPlatformAdmin()) {
            throw new RuntimeException('Only platform operators can enter a tenant.');
        }
        $reason = trim($reason);
        if (Str::length($reason) < 10) {
            throw new RuntimeException('Give a reason of at least 10 characters.');
        }
        $reference = filled($reference) ? Str::limit(trim((string) $reference), 100, '') : null;

        if ($this->grant($session) !== null) {
            $this->end($operator, $session, 'switched');
        }

        $now = Carbon::now();
        $grant = [
            'id' => (string) Str::ulid(),
            'tenant_id' => (int) $tenant->getKey(),
            'reason' => Str::limit($reason, 1000, ''),
            'reference' => $reference,
            'mode' => self::MODE,
            'entered_at' => $now->toIso8601String(),
            'expires_at' => $now->copy()->addMinutes(max(1, (int) config('peopleos.platform.tenant_access_minutes', 60)))->toIso8601String(),
        ];
        $session->put(self::SESSION_KEY, $grant);
        $session->migrate(true);

        $metadata = $this->metadata($grant, $tenant) + ['tenant_status' => $tenant->status->value];
        $this->audit->record(AuditAction::PlatformAccessStarted, 'platform', null, [], $grant['reason'], approvalReference: $reference, entityLabel: "Tenant {$tenant->name}", actor: $operator, metadata: $metadata, platform: true);
        $this->audit->record(AuditAction::PlatformAccessStarted, 'platform', $tenant, [], $grant['reason'], approvalReference: $reference, tenantId: $tenant->getKey(), actor: $operator, metadata: $metadata);

        return $grant;
    }

    /** The grant held by this session, if any (expired or not). */
    public function grant(Session $session): ?array
    {
        $grant = $session->get(self::SESSION_KEY);

        return is_array($grant) && isset($grant['id'], $grant['tenant_id'], $grant['expires_at']) ? $grant : null;
    }

    /**
     * The tenant an operator may act in right now. An expired grant (or one for a tenant that no longer
     * exists) is ended and audited here, on the first request after it lapsed.
     */
    public function activeTenant(User $operator, Session $session): ?Tenant
    {
        $grant = $this->grant($session);
        if ($grant === null || ! $operator->isPlatformAdmin()) {
            return null;
        }
        if (Carbon::parse($grant['expires_at'])->lte(Carbon::now())) {
            $this->end($operator, $session, 'expired');

            return null;
        }
        $tenant = Tenant::query()->find($grant['tenant_id']);
        if ($tenant === null) {
            $this->end($operator, $session, 'tenant_missing');
        }

        return $tenant;
    }

    /** Ends the grant (explicit exit, sign-out, switch or expiry) and audits how long it lasted. */
    public function end(User $operator, Session $session, string $cause = 'exit'): void
    {
        $grant = $this->grant($session);
        $session->forget(self::SESSION_KEY);
        if ($grant === null) {
            return;
        }

        $tenant = Tenant::query()->find($grant['tenant_id']);
        $endedAt = $cause === 'expired' ? Carbon::parse($grant['expires_at']) : Carbon::now();
        $metadata = $this->metadata($grant, $tenant) + [
            'cause' => $cause,
            'ended_at' => $endedAt->toIso8601String(),
            'duration_seconds' => max(0, (int) Carbon::parse($grant['entered_at'])->diffInSeconds($endedAt)),
        ];

        $this->audit->record(AuditAction::PlatformAccessEnded, 'platform', null, [], $grant['reason'], approvalReference: $grant['reference'], entityLabel: 'Tenant '.($tenant?->name ?? "#{$grant['tenant_id']}"), actor: $operator, metadata: $metadata, platform: true);
        if ($tenant !== null) {
            $this->audit->record(AuditAction::PlatformAccessEnded, 'platform', $tenant, [], $grant['reason'], approvalReference: $grant['reference'], tenantId: $tenant->getKey(), actor: $operator, metadata: $metadata);
        }
    }

    /** @return array<string, mixed> */
    private function metadata(array $grant, ?Tenant $tenant): array
    {
        return [
            'platform_access_id' => $grant['id'],
            'subject_tenant_id' => $grant['tenant_id'],
            'subject_tenant' => $tenant?->slug,
            'authority' => 'platform_operator',
            'mode' => $grant['mode'] ?? self::MODE,
            'entered_at' => $grant['entered_at'],
            'expires_at' => $grant['expires_at'],
        ];
    }
}
