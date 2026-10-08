<?php

namespace App\Domain\Audit\Services;

use App\Domain\Audit\Models\AuditVerificationRun;
use App\Domain\Platform\Models\Tenant;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * SaaS.8: audit-chain verification as a scheduled, recorded job.
 *
 * peopleos:audit:verify existed but was never scheduled, and the readiness page re-hashed every audit event of every
 * tenant on each page load. Now the daily schedule verifies every chain (each tenant, then the platform chain) and
 * records one row per chain; the readiness page and the operations page read the latest rows.
 *
 * status() is FAIL when the latest result of any chain is broken, STALE when a chain was never verified or its latest
 * result is older than peopleos.audit.verification_max_age_hours, and PASS otherwise.
 */
final class AuditVerifications
{
    public const PASS = 'PASS';

    public const FAIL = 'FAIL';

    public const STALE = 'STALE';

    public function __construct(private readonly AuditIntegrityVerifier $verifier) {}

    /**
     * @param  list<int|null>|null  $tenantIds  null = every tenant and the platform chain
     * @return list<AuditVerificationRun>
     */
    public function verify(?array $tenantIds = null, string $trigger = 'command'): array
    {
        $tenantIds ??= [...Tenant::query()->orderBy('id')->pluck('id')->all(), null];
        $runs = [];
        foreach ($tenantIds as $tenantId) {
            $started = now();
            $clock = hrtime(true);
            try {
                $result = $this->verifier->verify($tenantId);
            } catch (Throwable $e) {
                report($e);
                $result = ['checked' => 0, 'valid' => false, 'broken_event_id' => null, 'reason' => 'verification failed: '.class_basename($e)];
            }
            $runs[] = AuditVerificationRun::query()->create([
                'subject_tenant_id' => $tenantId,
                'chain' => $tenantId === null ? 'platform' : 'tenant',
                'valid' => $result['valid'],
                'checked' => $result['checked'],
                'broken_event_id' => $result['broken_event_id'],
                'reason' => $result['reason'] === null ? null : mb_substr($result['reason'], 0, 255),
                'trigger' => $trigger,
                'started_at' => $started,
                'finished_at' => now(),
                'duration_ms' => (int) ((hrtime(true) - $clock) / 1_000_000),
            ]);
        }

        return $runs;
    }

    /** A tenant's id from its id or slug (the command's --tenant). */
    public function tenantId(string $idOrSlug): int
    {
        return Tenant::query()->where('id', $idOrSlug)->orWhere('slug', $idOrSlug)->firstOrFail()->id;
    }

    /**
     * The latest result of every chain that exists now (every tenant and the platform chain).
     *
     * @return array{state: string, checked: int, chains: int, verified: int, broken: list<string>, never: list<string>, stale: list<string>, oldest: ?string}
     */
    public function status(): array
    {
        $latestIds = AuditVerificationRun::query()->select(DB::raw('MAX(id) as id'))->groupBy('chain', 'subject_tenant_id')->pluck('id');
        $latest = AuditVerificationRun::query()->whereKey($latestIds)->get()->keyBy(fn (AuditVerificationRun $r) => $r->subject_tenant_id ?? 'platform');
        $tenants = Tenant::query()->orderBy('id')->pluck('slug', 'id');
        $chains = $tenants->all() + ['platform' => 'platform'];
        $maxAge = now()->subHours(max(1, (int) config('peopleos.audit.verification_max_age_hours', 48)));

        $broken = $never = $stale = [];
        $checked = 0;
        $oldest = null;
        foreach ($chains as $key => $label) {
            $run = $latest->get($key);
            if ($run === null) {
                $never[] = $label;

                continue;
            }
            $checked += $run->checked;
            $oldest = $oldest === null || $run->finished_at->lt($oldest) ? $run->finished_at : $oldest;
            if (! $run->valid) {
                $broken[] = $label;
            } elseif ($run->finished_at->lt($maxAge)) {
                $stale[] = $label;
            }
        }
        $state = $broken !== [] ? self::FAIL : ($never !== [] || $stale !== [] ? self::STALE : self::PASS);

        return ['state' => $state, 'checked' => $checked, 'chains' => count($chains), 'verified' => count($chains) - count($never), 'broken' => $broken,
            'never' => $never, 'stale' => $stale, 'oldest' => $oldest?->toIso8601String()];
    }
}
