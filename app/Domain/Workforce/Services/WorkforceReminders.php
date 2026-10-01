<?php

namespace App\Domain\Workforce\Services;

use App\Domain\Identity\Scopes\AccessScope;
use App\Domain\Workforce\Events\WorkforceEvent;
use App\Domain\Workforce\Models\WorkforcePlan;
use App\Domain\Workforce\Models\WorkforcePlanVersion;
use App\Domain\Workforce\Models\WorkforceReminderLog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;

/**
 * Phase 10 workforce reminders for the current tenant, throttled through workforce_reminder_logs:
 * plan versions waiting for review or approval, active plans whose period is ending, and positions
 * left vacant. Recipients are the record owners. Re-running never re-sends within the window; a
 * reminder never triggers another reminder.
 */
final class WorkforceReminders
{
    public function __construct(private readonly WorkforceSnapshot $snapshot) {}

    /** @return array{pending_approval: int, plan_expiry: int, vacancies: int} */
    public function tick(): array
    {
        $today = now()->startOfDay();
        $sent = ['pending_approval' => 0, 'plan_expiry' => 0, 'vacancies' => 0];

        WorkforcePlanVersion::query()->whereIn('status', ['submitted', 'under_review'])
            ->where('submitted_at', '<=', $today->copy()->subDays((int) config('peopleos.workforce.approval_reminder_days', 3)))
            ->chunkById(500, function ($versions) use (&$sent) {
                foreach ($versions as $version) {
                    $plan = WorkforcePlan::query()->withoutGlobalScope(AccessScope::class)->find($version->workforce_plan_id);
                    if ($plan?->owner_user_id && $this->once('pending_approval', $version, 7)) {
                        WorkforceEvent::dispatch('workforce.reminder.pending_approval', null, $version, ['plan' => $plan->code, 'version' => $version->version, 'status' => $version->status], [(int) $plan->owner_user_id]);
                        $sent['pending_approval']++;
                    }
                }
            });

        WorkforcePlanVersion::query()->where('status', 'active')->whereDate('period_end', '>=', $today)
            ->whereDate('period_end', '<=', $today->copy()->addDays((int) config('peopleos.workforce.plan_expiry_reminder_days', 30)))
            ->chunkById(500, function ($versions) use (&$sent) {
                foreach ($versions as $version) {
                    $plan = WorkforcePlan::query()->withoutGlobalScope(AccessScope::class)->find($version->workforce_plan_id);
                    if ($plan?->owner_user_id && $this->once('plan_expiry', $version, null)) {
                        WorkforceEvent::dispatch('workforce.reminder.plan_expiry', null, $version, ['plan' => $plan->code, 'period_end' => $version->period_end->toDateString()], [(int) $plan->owner_user_id]);
                        $sent['plan_expiry']++;
                    }
                }
            });

        $threshold = $today->copy()->subDays((int) config('peopleos.workforce.vacancy_reminder_days', 30))->toDateString();
        $this->snapshot->vacancies($today)->withoutGlobalScope(AccessScope::class)->where('position_versions.effective_from', '<=', $threshold.' 23:59:59')
            ->with('position:id,code,created_by')->orderBy('position_versions.id')->get()
            ->each(function ($version) use (&$sent) {
                $position = $version->position;
                if ($position?->created_by && $this->once('vacancy', $position, 30)) {
                    WorkforceEvent::dispatch('workforce.reminder.vacancy', null, $position, ['code' => $position->code], [(int) $position->created_by]);
                    $sent['vacancies']++;
                }
            });

        return $sent;
    }

    private function once(string $reminder, Model $subject, ?int $everyDays): bool
    {
        $type = class_basename($subject);
        $logged = WorkforceReminderLog::query()->where('reminder', $reminder)->where('subject_type', $type)->where('subject_id', $subject->getKey())
            ->when($everyDays !== null, fn ($q) => $q->whereDate('reminded_on', '>', Carbon::now()->subDays($everyDays)->toDateString()));
        if ($logged->exists()) {
            return false;
        }
        try {
            WorkforceReminderLog::query()->create(['reminder' => $reminder, 'subject_type' => $type, 'subject_id' => $subject->getKey(), 'reminded_on' => now()->toDateString()]);

            return true;
        } catch (UniqueConstraintViolationException) {
            return false;
        }
    }
}
