<?php

namespace App\Domain\Entitlements\Services;

use App\Domain\Entitlements\Enums\DecisionOutcome;
use App\Domain\Entitlements\Enums\DecisionReason;
use App\Domain\Entitlements\Support\Decision;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * SaaS.3: shadow observability without a write per check.
 *
 * 1. During a request or job, observed decisions are counted in memory, one entry per
 *    (tenant, day, capability, outcome, reason, surface). NOT_APPLICABLE is not recorded.
 * 2. After the response (or at the end of the job or command: Laravel `defer`, `always`), the buffer is flushed.
 * 3. Each entry passes a cache gate: at most one database write per entry per window
 *    (peopleos.entitlements.shadow.window_seconds, 10 minutes) across all processes. The write is an upsert into
 *    entitlement_shadow_observations that adds this request's count, so `occurrences` is a lower bound.
 * 4. DENY decisions and evaluation failures also produce one structured log line per window.
 *
 * Nothing here can affect the action being observed: every failure is logged and swallowed. No employee data,
 * salary, token or secret is ever recorded; only the capability, outcome, reason, surface and source ids.
 * Request-scoped (AppServiceProvider).
 */
final class ShadowRecorder
{
    /** @var array<string, array{decision: Decision, count: int}> */
    private array $buffer = [];

    private bool $scheduled = false;

    public function __construct(private readonly Cache $cache) {}

    public function record(Decision $decision): void
    {
        if ($decision->outcome === DecisionOutcome::NotApplicable || $decision->surface === null) {
            return;
        }
        if ($decision->tenantId === null) {
            Log::warning('entitlements.shadow.no_tenant', $decision->toArray());

            return;
        }
        $key = implode('|', [$decision->tenantId, $decision->effectiveOn, $decision->capability->value, $decision->outcome->value, $decision->reason->value, $decision->surface]);
        $this->buffer[$key] = ['decision' => $decision, 'count' => ($this->buffer[$key]['count'] ?? 0) + 1];

        if (! $this->scheduled) {
            $this->scheduled = true;
            defer(fn () => $this->flush(), 'entitlements.shadow.'.spl_object_id($this), always: true);
        }
    }

    /** Pending (unflushed) observations, for diagnostics and tests. */
    public function pending(): int
    {
        return array_sum(array_column($this->buffer, 'count'));
    }

    /** Writes the buffer. Returns the number of rows written. Never throws. */
    public function flush(): int
    {
        [$buffer, $this->buffer, $this->scheduled] = [$this->buffer, [], false];
        $written = 0;

        foreach ($buffer as $key => ['decision' => $decision, 'count' => $count]) {
            try {
                if (! $this->gate($key)) {
                    continue;
                }
                $this->write($decision, $count);
                $written++;
                if ($decision->outcome === DecisionOutcome::Deny || $decision->reason === DecisionReason::EvaluationFailed) {
                    Log::info('entitlements.shadow', $decision->toArray() + ['occurrences' => $count]);
                }
            } catch (Throwable $e) {
                Log::warning('entitlements.shadow_write_failed', ['tenant_id' => $decision->tenantId, 'capability' => $decision->capability->value, 'error' => $e::class]);
            }
        }

        return $written;
    }

    /** At most one write per entry per window, across processes. A cache failure lets the write through. */
    private function gate(string $key): bool
    {
        $window = max(1, (int) config('peopleos.entitlements.shadow.window_seconds', 600));
        try {
            return (bool) $this->cache->add('entitlements:shadow:'.sha1($key).':'.intdiv(time(), $window), true, $window);
        } catch (Throwable) {
            return true;
        }
    }

    private function write(Decision $decision, int $count): void
    {
        $now = now();
        DB::table('entitlement_shadow_observations')->upsert(
            [[
                'tenant_id' => $decision->tenantId, 'observed_on' => $decision->effectiveOn, 'capability' => $decision->capability->value,
                'outcome' => $decision->outcome->value, 'reason' => $decision->reason->value, 'surface' => $decision->surface,
                'occurrences' => $count, 'first_seen_at' => $now, 'last_seen_at' => $now, 'last_source' => $decision->source->value,
                'last_entitlement_id' => $decision->entitlementId, 'last_override_id' => $decision->overrideId,
            ]],
            ['tenant_id', 'observed_on', 'capability', 'outcome', 'reason', 'surface'],
            ['occurrences' => DB::raw('occurrences + '.$count), 'last_seen_at' => $now, 'last_source' => $decision->source->value,
                'last_entitlement_id' => $decision->entitlementId, 'last_override_id' => $decision->overrideId],
        );
    }
}
