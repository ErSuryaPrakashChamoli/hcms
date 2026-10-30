<?php

namespace App\Domain\Enterprise\Services;

use App\Domain\Ai\Models\AiInteraction;
use App\Domain\Analytics\Models\ReportRun;
use App\Domain\Enterprise\Models\WebhookDelivery;
use App\Domain\Entitlements\Models\EntitlementShadowObservation;
use App\Domain\Integration\Models\ApiIdempotencyKey;
use App\Domain\Integration\Models\ApiRequestLog;
use App\Domain\Integration\Services\InboundEvents;
use App\Domain\Integration\Services\IntegrationLogExports;
use App\Domain\Notifications\Models\NotificationDelivery;
use App\Domain\Offboarding\Services\LegalHolds;
use App\Domain\Platform\Services\SettingsRepository;
use App\Support\Tenancy\TenantContext;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/** Custom retention (§110): purges operational logs older than the tenant's window. Audit events are never purged. */
final class Retention
{
    public function __construct(private readonly SettingsRepository $settings) {}

    /**
     * @return array<string, int|string> purged count per log, or "held" where a legal hold covers its category (SaaS.9:
     *                                   nothing a hold covers is purged, not even operational logs past their window)
     */
    public function purge(): array
    {
        $tenantId = app(TenantContext::class)->id();
        // SaaS.9: every batch runs under the tenant's exit lock and re-checks the holds first (LegalHolds::lockFor), so a
        // hold placed while the purge runs stops it before its next batch. Null = held: nothing more is purged.
        $guard = fn (string $category, Closure $work) => $tenantId === null ? $work()
            : DB::transaction(fn () => LegalHolds::heldUnderLock($tenantId, $category) === [] ? $work() : null);
        $out = [];
        $ai = (int) $this->settings->get('retention.ai_interactions_days', 365);
        $out['ai_interactions'] = $ai > 0 ? $this->inBatches($guard, 'analytics', fn () => AiInteraction::query()->where('created_at', '<', now()->subDays($ai))) : 0;

        $n = (int) $this->settings->get('retention.notification_deliveries_days', 180);
        // SaaS.8: in bounded batches (a single delete held long locks on a large delivery log).
        $out['notification_deliveries'] = $n > 0 ? $this->inBatches($guard, 'notifications', fn () => NotificationDelivery::query()->where('created_at', '<', now()->subDays($n))) : 0;

        $r = (int) $this->settings->get('retention.report_runs_days', 90);
        $out['report_runs'] = 0;
        foreach ($r > 0 ? ReportRun::query()->where('started_at', '<', now()->subDays($r))->get() : [] as $run) {
            $done = $guard('analytics', function () use ($run) {
                if ($run->hasFile()) {
                    Storage::disk($run->disk)->delete($run->path);
                }

                return (int) $run->delete();
            });
            if ($done === null) {
                $out['report_runs'] = 'held';
                break;
            }
            $out['report_runs'] += $done;
        }
        $out['webhook_deliveries'] = $this->inBatches($guard, 'integrations', fn () => WebhookDelivery::query()->whereIn('status', ['delivered', 'failed', 'dead_letter'])->where('created_at', '<', now()->subDays(90)));
        // HROne parity SV-26: each delivery's attempts go with it (foreign key cascade); previous webhook secrets past their
        // grace period are cleared; queued log exports (rows and files) follow the report runs' window.
        $out['webhook_previous_secrets'] = $guard('integrations', fn () => app(Webhooks::class)->expirePreviousSecrets()) ?? 'held';
        $out['integration_log_exports'] = $r > 0 ? ($guard('integrations', fn () => app(IntegrationLogExports::class)->purge($r)) ?? 'held') : 0;
        // HROne parity CA-11: API call logs past the tenant's window.
        $calls = (int) $this->settings->get('retention.api_request_logs_days', 90);
        $out['api_request_logs'] = $calls > 0 ? $this->inBatches($guard, 'api', fn () => ApiRequestLog::query()->where('created_at', '<', now()->subDays($calls))) : 0;
        // SaaS.8: the warehouse feed's daily snapshots past the tenant's window (they were never deleted).
        $out['warehouse_snapshots'] = $guard('warehouse_exports', fn () => app(WarehouseExport::class)->purgeSnapshots()) ?? 'held';
        // Phase 14: inbound event payload bodies are purged after the retention window; the event rows (metadata) stay.
        $out['inbound_payloads'] = $guard('integrations', fn () => app(InboundEvents::class)->purgePayloads()) ?? 'held';
        // SaaS.2: remembered API writes past their Idempotency-Key window (the key is reusable by then).
        $out['api_idempotency_keys'] = $this->purgeExpiredIdempotencyKeys($guard);
        // SaaS.3: aggregated entitlement shadow observations (observability, not audit or commercial state).
        $out['entitlement_shadow_observations'] = $guard('platform_operational', fn () => EntitlementShadowObservation::query()
            ->where('observed_on', '<', now()->subDays((int) config('peopleos.entitlements.shadow.retention_days', 90))->toDateString())->delete()) ?? 'held';

        return $out;
    }

    /**
     * SaaS.8: deletes the rows a query matches in bounded batches (ids first, then by key), so one run never holds a long
     * delete on a large table. SaaS.9: each batch through the hold guard; "held" once a hold covers the category.
     *
     * @param  Closure(string, Closure): mixed  $guard  the work's result, or null when held
     * @param  Closure(): Builder<Model>  $query
     */
    private function inBatches(Closure $guard, string $category, Closure $query, int $batch = 1000): int|string
    {
        $deleted = 0;
        do {
            // Each pass deletes the matching rows up to the batch-th matching id: at most $batch rows per statement, with
            // no long list of bound ids; the last pass deletes what remains.
            $pass = $guard($category, function () use ($query, $batch) {
                $last = $query()->orderBy('id')->offset($batch - 1)->limit(1)->value('id');

                return ['deleted' => $last === null ? $query()->delete() : $query()->where('id', '<=', $last)->delete(), 'more' => $last !== null];
            });
            if ($pass === null) {
                return 'held';
            }
            $deleted += $pass['deleted'];
        } while ($pass['more']);

        return $deleted;
    }

    /**
     * Deletes expired idempotency rows in bounded batches, so one run never holds a long delete.
     *
     * @param  Closure(string, Closure): mixed  $guard  the work's result, or null when held
     */
    private function purgeExpiredIdempotencyKeys(Closure $guard, int $batch = 1000): int|string
    {
        $deleted = 0;
        do {
            $n = $guard('platform_operational', function () use ($batch) {
                $ids = ApiIdempotencyKey::query()->where('expires_at', '<=', now())->orderBy('id')->limit($batch)->pluck('id');

                return $ids->isEmpty() ? 0 : ApiIdempotencyKey::query()->whereKey($ids)->delete();
            });
            if ($n === null) {
                return 'held';
            }
            $deleted += $n;
        } while ($n === $batch);

        return $deleted;
    }
}
