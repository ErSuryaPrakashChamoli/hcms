<?php

namespace App\Domain\Experience\Services;

use App\Domain\Experience\Models\UxMetric;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * UX instrumentation: anonymous, aggregate counters per tenant and day (for example
 * command.open, command.select, search.intent, drawer.open, approval.decided, home.view). It
 * stores no user, no query text and no record: only a metric name, a count and optionally a
 * duration. It never breaks the experience: failures are swallowed.
 */
final class UxMetrics
{
    public const METRICS = ['home.view', 'command.open', 'command.search', 'command.select', 'search.intent', 'search.no_results', 'drawer.open',
        'approval.decided', 'approval.failed', 'action.started', 'action.completed', 'action.failed', 'ai.asked', 'notification.opened', 'leave.requested'];

    public function __construct(private readonly TenantContext $tenants) {}

    public function record(string $metric, ?int $milliseconds = null): void
    {
        if (! in_array($metric, self::METRICS, true) || ! $this->tenants->has()) {
            return;
        }
        try {
            $day = now()->toDateString();
            $row = UxMetric::query()->firstOrCreate(['day' => $day, 'metric' => $metric], ['count' => 0, 'total_ms' => 0]);
            UxMetric::query()->whereKey($row->id)->update(['count' => DB::raw('count + 1'), 'total_ms' => DB::raw('total_ms + '.max(0, (int) $milliseconds))]);
        } catch (Throwable) {
            // Instrumentation must never affect the experience.
        }
    }
}
