<?php

namespace App\Domain\Enterprise\Services;

use App\Domain\Ai\Models\AiInteraction;
use App\Domain\Analytics\Models\ReportRun;
use App\Domain\Enterprise\Models\WebhookDelivery;
use App\Domain\Entitlements\Models\EntitlementShadowObservation;
use App\Domain\Integration\Models\ApiIdempotencyKey;
use App\Domain\Integration\Services\InboundEvents;
use App\Domain\Notifications\Models\NotificationDelivery;
use App\Domain\Offboarding\Services\LegalHolds;
use App\Domain\Platform\Services\SettingsRepository;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
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
        $held = fn (string $category) => $tenantId !== null && app(LegalHolds::class)->covering($tenantId, $category) !== [];
        $out = [];
        $ai = (int) $this->settings->get('retention.ai_interactions_days', 365);
        $out['ai_interactions'] = $held('analytics') ? 'held' : ($ai > 0 ? $this->inBatches(fn () => AiInteraction::query()->where('created_at', '<', now()->subDays($ai))) : 0);

        $n = (int) $this->settings->get('retention.notification_deliveries_days', 180);
        // SaaS.8: in bounded batches (a single delete held long locks on a large delivery log).
        $out['notification_deliveries'] = $held('notifications') ? 'held' : ($n > 0 ? $this->inBatches(fn () => NotificationDelivery::query()->where('created_at', '<', now()->subDays($n))) : 0);

        $r = (int) $this->settings->get('retention.report_runs_days', 90);
        $runs = $r > 0 && ! $held('analytics') ? ReportRun::query()->where('started_at', '<', now()->subDays($r))->get() : collect();
        foreach ($runs as $run) {
            if ($run->hasFile()) {
                Storage::disk($run->disk)->delete($run->path);
            }
            $run->delete();
        }
        $out['report_runs'] = $held('analytics') ? 'held' : $runs->count();
        $out['webhook_deliveries'] = $held('integrations') ? 'held' : $this->inBatches(fn () => WebhookDelivery::query()->whereIn('status', ['delivered', 'failed', 'dead_letter'])->where('created_at', '<', now()->subDays(90)));
        // SaaS.8: the warehouse feed's daily snapshots past the tenant's window (they were never deleted).
        $out['warehouse_snapshots'] = $held('warehouse_exports') ? 'held' : app(WarehouseExport::class)->purgeSnapshots();
        // Phase 14: inbound event payload bodies are purged after the retention window; the event rows (metadata) stay.
        $out['inbound_payloads'] = $held('integrations') ? 'held' : app(InboundEvents::class)->purgePayloads();
        // SaaS.2: remembered API writes past their Idempotency-Key window (the key is reusable by then).
        $out['api_idempotency_keys'] = $held('platform_operational') ? 'held' : $this->purgeExpiredIdempotencyKeys();
        // SaaS.3: aggregated entitlement shadow observations (observability, not audit or commercial state).
        $out['entitlement_shadow_observations'] = $held('platform_operational') ? 'held' : EntitlementShadowObservation::query()
            ->where('observed_on', '<', now()->subDays((int) config('peopleos.entitlements.shadow.retention_days', 90))->toDateString())->delete();

        return $out;
    }

    /**
     * SaaS.8: deletes the rows a query matches in bounded batches (ids first, then by key), so one run never holds a long
     * delete on a large table.
     *
     * @param  \Closure(): Builder<Model>  $query
     */
    private function inBatches(\Closure $query, int $batch = 1000): int
    {
        $deleted = 0;
        // Each pass deletes the matching rows up to the batch-th matching id: at most $batch rows per statement, with no
        // long list of bound ids.
        while (($last = $query()->orderBy('id')->offset($batch - 1)->limit(1)->value('id')) !== null) {
            $deleted += $query()->where('id', '<=', $last)->delete();
        }

        return $deleted + $query()->delete();
    }

    /** Deletes expired idempotency rows in bounded batches, so one run never holds a long delete. */
    private function purgeExpiredIdempotencyKeys(int $batch = 1000): int
    {
        $deleted = 0;
        do {
            $ids = ApiIdempotencyKey::query()->where('expires_at', '<=', now())->orderBy('id')->limit($batch)->pluck('id');
            $deleted += $ids->isEmpty() ? 0 : ApiIdempotencyKey::query()->whereKey($ids)->delete();
        } while ($ids->count() === $batch);

        return $deleted;
    }
}
