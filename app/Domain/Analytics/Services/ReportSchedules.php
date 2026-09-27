<?php

namespace App\Domain\Analytics\Services;

use App\Domain\Analytics\Models\ReportSchedule;
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
            $run = $this->exports->export($schedule->report, null, $schedule);
            $schedule->forceFill(['last_run_at' => now(), 'next_run_at' => $schedule->computeNextRun(now())])->save();

            $users = User::query()->whereIn('id', $schedule->recipient_user_ids ?? [])->get()->filter(fn (User $u) => $u->isActive());
            if ($users->isNotEmpty()) {
                $title = $run->status === 'completed' ? "Report ready: {$schedule->report->name} ({$run->row_count} rows)" : "Report failed: {$schedule->report->name}";
                $this->notifier->send($users, ['in_app'], $title, $run->status === 'completed' ? 'Download it from Analytics → Reports → '.$schedule->report->name.' → Runs.' : ($run->error ?? 'Unknown error'), 'analytics.report.scheduled', $run);
            }
            $ran++;
        });

        return $ran;
    }
}
