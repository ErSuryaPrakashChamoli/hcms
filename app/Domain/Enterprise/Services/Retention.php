<?php

namespace App\Domain\Enterprise\Services;

use App\Domain\Ai\Models\AiInteraction;
use App\Domain\Analytics\Models\ReportRun;
use App\Domain\Enterprise\Models\WebhookDelivery;
use App\Domain\Notifications\Models\NotificationDelivery;
use App\Domain\Platform\Services\SettingsRepository;
use Illuminate\Support\Facades\Storage;

/** Custom retention (§110): purges operational logs older than the tenant's window. Audit events are never purged. */
final class Retention
{
    public function __construct(private readonly SettingsRepository $settings) {}

    /** @return array<string, int> */
    public function purge(): array
    {
        $out = [];
        $ai = (int) $this->settings->get('retention.ai_interactions_days', 365);
        $out['ai_interactions'] = $ai > 0 ? AiInteraction::query()->where('created_at', '<', now()->subDays($ai))->delete() : 0;

        $n = (int) $this->settings->get('retention.notification_deliveries_days', 180);
        $out['notification_deliveries'] = $n > 0 ? NotificationDelivery::query()->where('created_at', '<', now()->subDays($n))->delete() : 0;

        $r = (int) $this->settings->get('retention.report_runs_days', 90);
        $runs = $r > 0 ? ReportRun::query()->where('started_at', '<', now()->subDays($r))->get() : collect();
        foreach ($runs as $run) {
            if ($run->hasFile()) {
                Storage::disk($run->disk)->delete($run->path);
            }
            $run->delete();
        }
        $out['report_runs'] = $runs->count();
        $out['webhook_deliveries'] = WebhookDelivery::query()->whereIn('status', ['delivered', 'failed'])->where('created_at', '<', now()->subDays(90))->delete();

        return $out;
    }
}
