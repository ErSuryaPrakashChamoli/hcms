<?php

namespace App\Domain\Offboarding\Services;

use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Audit\Models\AuditEvent;
use App\Domain\Identity\Models\User;
use App\Domain\Offboarding\Enums\DeletionStatus;
use App\Domain\Offboarding\Models\TenantDeletion;
use App\Domain\Offboarding\Support\OffboardingAudit;
use App\Domain\Platform\Models\Tenant;
use App\Domain\Platform\Services\TenantSuspensions;
use App\Support\Tenancy\TenantContext;

/**
 * SaaS.12: after a restore, no legitimately deleted data is silently back.
 *
 * Each deletion register entry (outside the database) is compared with the database:
 * - CONSISTENT: the database holds the same completed deletion (same certificate).
 * - REAPPLIED: the restored data predated it, and a later completed deletion of the tenant re-applied it.
 * - NOT_PRESENT: the tenant is not in this database at all (the restore predates it): nothing came back.
 * - RESURRECTED: the tenant is here but the deletion is not, so the data it removed is back. Containment suspends an
 *   operating tenant (sign-in, sessions, API keys and scheduled work stop) and records that the deletion must be
 *   re-applied. Nothing is deleted automatically.
 * - IDENTITY_MISMATCH: a tenant with the id but another slug. It may not be the same customer, so nothing is done; an
 *   operator investigates.
 * - INVALID: the entry fails its hash or signature. It is never acted on.
 *
 * Re-application is the ordinary deletion workflow. It honours legal holds, retention (legal minimums, policy and
 * contract), financial and statutory protection, maker-checker approval and the cooling-off; the plan records the
 * deletions it re-applies (`reapplies`), and so does the new certificate. The other direction is checked too: a
 * completed deletion without a register entry is UNREGISTERED (the offboarding tick writes it).
 */
final class DeletionReconciliation
{
    public const CONSISTENT = 'consistent';

    public const REAPPLIED = 'reapplied';

    public const NOT_PRESENT = 'not_present';

    public const RESURRECTED = 'resurrected';

    public const IDENTITY_MISMATCH = 'identity_mismatch';

    public const INVALID = 'invalid';

    public const CONTAINMENT_REASON = 'The restored database predates a certified deletion (deletion register): contained until the deletion is re-applied.';

    public function __construct(
        private readonly DeletionRegister $register,
        private readonly TenantContext $tenants,
        private readonly OffboardingAudit $audit,
    ) {}

    /**
     * @return array{entries: list<array<string, mixed>>, unregistered: list<string>, summary: array<string, int>}
     */
    public function check(): array
    {
        $entries = [];
        $registered = [];
        foreach ($this->register->entries() as $item) {
            $registered[] = (string) $item['reference'];
            $entries[] = $item['valid'] ? $this->classify($item['entry']) : ['reference' => $item['reference'], 'path' => $item['path'],
                'outcome' => self::INVALID, 'detail' => $item['problem']];
        }
        $unregistered = $this->tenants->bypass(fn () => TenantDeletion::query()->where('status', DeletionStatus::Completed->value)
            ->whereNotNull('certificate_sha256')->whereNotIn('reference', $registered)->orderBy('id')->pluck('reference')->all());
        $summary = array_fill_keys([self::CONSISTENT, self::REAPPLIED, self::NOT_PRESENT, self::RESURRECTED, self::IDENTITY_MISMATCH, self::INVALID], 0);
        foreach ($entries as $entry) {
            $summary[$entry['outcome']]++;
        }
        $summary['resurrected_operating'] = count(array_filter($entries, fn (array $e) => $e['outcome'] === self::RESURRECTED && $e['operating']));
        $summary['unregistered'] = count($unregistered);

        return ['entries' => $entries, 'unregistered' => $unregistered, 'summary' => $summary];
    }

    /** True when nothing needs a person: no data back without containment, no doubtful entry, nothing unregistered. */
    public static function clean(array $summary): bool
    {
        return $summary[self::RESURRECTED] === 0 && $summary[self::IDENTITY_MISMATCH] === 0 && $summary[self::INVALID] === 0 && $summary['unregistered'] === 0;
    }

    /**
     * Contains every resurrected tenant: suspends an operating one, and records once per deletion that it must be
     * re-applied (both audit chains). Never deletes, never touches a tenant whose identity is in doubt.
     *
     * @return array{suspended: int, recorded: int}
     */
    public function contain(?User $actor, string $reason = self::CONTAINMENT_REASON): array
    {
        $result = ['suspended' => 0, 'recorded' => 0];
        foreach ($this->check()['entries'] as $entry) {
            if ($entry['outcome'] !== self::RESURRECTED) {
                continue;
            }
            $tenant = Tenant::query()->findOrFail($entry['tenant_id']);
            if ($tenant->status->operating() && app(TenantSuspensions::class)->suspend($tenant, $reason, $actor)) {
                $result['suspended']++;
            }
            $known = AuditEvent::query()->withoutTenancy()->whereNull('tenant_id')->where('action', AuditAction::DeletionReapplicationRequired->value)
                ->where('metadata->deletion', $entry['reference'])->exists();
            if (! $known) {
                $this->audit->record(AuditAction::DeletionReapplicationRequired, $tenant->refresh(), null, $reason, $actor, [
                    'deletion' => $entry['reference'], 'certificate_sha256' => $entry['certificate_sha256'], 'restored_tenant_status' => $entry['restored_tenant_status'],
                    'restored_deletion_status' => $entry['restored_deletion_status'], 'final' => $entry['final']]);
                $result['recorded']++;
            }
        }

        return $result;
    }

    /**
     * The deletions of this tenant that the restored data predates: what a new deletion of it re-applies.
     *
     * @return list<array{reference: string, certificate_sha256: string, approved_by: mixed, approved_at: ?string, completed_at: ?string}>
     */
    public function reappliesFor(Tenant $tenant): array
    {
        return array_values(array_map(fn (array $e) => ['reference' => $e['reference'], 'certificate_sha256' => $e['certificate_sha256'],
            'approved_by' => $e['approved_by'], 'approved_at' => $e['approved_at'], 'completed_at' => $e['completed_at']],
            array_filter($this->check()['entries'], fn (array $e) => $e['outcome'] === self::RESURRECTED && (int) $e['tenant_id'] === (int) $tenant->getKey())));
    }

    /** @param  array<string, mixed>  $entry  a valid register entry */
    private function classify(array $entry): array
    {
        $certificate = $entry['certificate'];
        $base = ['reference' => $entry['reference'], 'tenant_id' => (int) $entry['tenant']['id'], 'tenant_slug' => $entry['tenant']['slug'],
            'certificate_sha256' => $entry['certificate_sha256'], 'final' => (bool) $entry['final'],
            'approved_by' => $certificate['deletion']['approved_by'] ?? null, 'approved_at' => $certificate['deletion']['approved_at'] ?? null,
            'completed_at' => $certificate['deletion']['completed_at'] ?? null];
        $deletion = $this->tenants->bypass(fn () => TenantDeletion::query()->where('reference', $entry['reference'])->first());
        if ($deletion?->status === DeletionStatus::Completed && hash_equals((string) $deletion->certificate_sha256, (string) $entry['certificate_sha256'])) {
            return $base + ['outcome' => self::CONSISTENT, 'detail' => 'the database holds this completed deletion'];
        }
        $tenant = Tenant::query()->find($base['tenant_id']);
        if ($tenant === null) {
            return $base + ['outcome' => self::NOT_PRESENT, 'detail' => 'the tenant is not in this database'];
        }
        if ($tenant->slug !== $base['tenant_slug']) {
            return $base + ['outcome' => self::IDENTITY_MISMATCH, 'detail' => 'a tenant with this id has another address: not acted on'];
        }
        $reapplied = $this->tenants->bypass(fn () => TenantDeletion::query()->where('tenant_id', $tenant->getKey())->where('status', DeletionStatus::Completed->value)
            ->get(['id', 'reference', 'plan']))->first(fn (TenantDeletion $d) => in_array($entry['reference'], array_column($d->plan['reapplies'] ?? [], 'reference'), true));
        if ($reapplied !== null) {
            return $base + ['outcome' => self::REAPPLIED, 'detail' => "re-applied by deletion {$reapplied->reference}", 'reapplied_by' => $reapplied->reference];
        }

        return $base + ['outcome' => self::RESURRECTED, 'detail' => 'the restored data predates this deletion', 'operating' => $tenant->status->operating(),
            'restored_tenant_status' => $tenant->status->value, 'restored_deletion_status' => $deletion?->status->value ?? 'absent'];
    }
}
