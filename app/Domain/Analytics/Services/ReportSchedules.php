<?php

namespace App\Domain\Analytics\Services;

use App\Domain\Analytics\Models\ReportRun;
use App\Domain\Analytics\Models\ReportSchedule;
use App\Domain\Entitlements\Enums\Capability;
use App\Domain\Entitlements\Services\Entitlements;
use App\Domain\Identity\Models\User;
use App\Domain\Notifications\Services\Notifier;

/** Runs due schedules, stores the export and tells the recipients. */
final class ReportSchedules
{
    public function __construct(private readonly ReportExports $exports, private readonly Notifier $notifier) {}

    public function runDue(): int
    {
        $ran = 0;

        ReportSchedule::query()->with('report')->where('status', 'active')->where('next_run_at', '<=', now())->get()->each(function (ReportSchedule $schedule) use (&$ran) {
            // Phase 14: claim the slot first (conditional update), so overlapping runs never export twice.
            $claimed = ReportSchedule::query()->whereKey($schedule->id)->where('next_run_at', $schedule->getRawOriginal('next_run_at'))
                ->update(['last_run_at' => now(), 'next_run_at' => $schedule->computeNextRun(now()), 'updated_at' => now()]);
            if ($claimed !== 1) {
                return;
            }
            app(Entitlements::class)->observe(Capability::AnalyticsScheduledReports, 'analytics.report.scheduled'); // SaaS.3: shadow only
            $run = $this->runAsOwner($schedule);

            $users = User::forCurrentTenant()->whereIn('id', $schedule->recipient_user_ids ?? [])->get()->filter(fn (User $u) => $u->isActive());
            if ($users->isNotEmpty()) {
                $title = $run->status === 'completed' ? "Report ready: {$schedule->report->name} ({$run->row_count} rows)" : "Report failed: {$schedule->report->name}";
                $this->notifier->send($users, ['in_app'], $title, $run->status === 'completed' ? 'Download it from Analytics → Reports → '.$schedule->report->name.' → Runs.' : ($run->error ?? 'Unknown error'), 'analytics.report.scheduled', $run);
            }
            $ran++;
        });

        return $ran;
    }

    /**
     * Phase 14: a scheduled run uses the report owner's permissions, sensitive-field rights and
     * organisation scope, exactly as if the owner ran it. Before, it ran as nobody: every field,
     * unscoped. No usable owner, no run.
     */
    private function runAsOwner(ReportSchedule $schedule): ReportRun
    {
        $owner = $schedule->report->owner_id ? User::query()->forCurrentTenant()->find($schedule->report->owner_id) : null;
        if ($owner === null || ! $owner->isActive()) {
            return ReportRun::create(['report_id' => $schedule->report_id, 'report_schedule_id' => $schedule->id, 'format' => 'csv', 'status' => 'failed',
                'started_at' => now(), 'finished_at' => now(), 'error' => 'The report owner is not an active user; scheduled runs use the owner\'s access.']);
        }
        $previous = auth()->user();
        auth()->setUser($owner);
        try {
            return $this->exports->export($schedule->report, $owner, $schedule);
        } finally {
            $previous ? auth()->setUser($previous) : auth()->forgetUser();
        }
    }
}
