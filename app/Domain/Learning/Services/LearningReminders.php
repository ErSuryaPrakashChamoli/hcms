<?php

namespace App\Domain\Learning\Services;

use App\Domain\Development\Events\DevelopmentEvent;
use App\Domain\Development\Models\DevelopmentPlanItem;
use App\Domain\Identity\Scopes\AccessScope;
use App\Domain\Learning\Events\LearningEvent;
use App\Domain\Learning\Models\LearningEnrolment;
use App\Domain\Learning\Models\LearningReminderLog;
use App\Domain\Skills\Events\SkillEvent;
use App\Domain\Skills\Models\SkillAssessment;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;

/**
 * Phase 8 learning reminders for the current tenant, throttled through learning_reminder_logs:
 * learning due soon (once), mandatory learning overdue (at most once per throttle window),
 * skill assessments left in draft, and development milestones coming up. Re-running never re-sends.
 * Expiry and overdue status changes stay in peopleos:learning:tick (no duplicate scheduler).
 */
final class LearningReminders
{
    /** @return array{due: int, overdue_mandatory: int, assessments: int, milestones: int} */
    public function tick(): array
    {
        $today = now()->startOfDay();
        $lead = (int) config('peopleos.learning.reminder_days_before', 3);
        $throttle = (int) config('peopleos.learning.overdue_reminder_every_days', 7);
        $sent = ['due' => 0, 'overdue_mandatory' => 0, 'assessments' => 0, 'milestones' => 0];

        LearningEnrolment::query()->with(['employee.person', 'course'])->whereIn('status', ['assigned', 'enrolled', 'approved', 'in_progress'])
            ->whereDate('due_on', '>=', $today)->whereDate('due_on', '<=', $today->copy()->addDays($lead))
            ->chunkById(500, function ($enrolments) use (&$sent) {
                foreach ($enrolments as $e) {
                    if ($this->once('due', $e, null)) {
                        LearningEvent::dispatch('learning.reminder.due', $e->employee, $e, ['course' => $e->course->title, 'due_on' => $e->due_on->toDateString()]);
                        $sent['due']++;
                    }
                }
            });

        LearningEnrolment::query()->with(['employee.person', 'employee.currentManager', 'course'])->where('status', 'overdue')->where('is_mandatory', true)
            ->chunkById(500, function ($enrolments) use (&$sent, $throttle) {
                foreach ($enrolments as $e) {
                    if ($this->once('overdue_mandatory', $e, $throttle)) {
                        LearningEvent::dispatch('learning.reminder.overdue_mandatory', $e->employee, $e, ['course' => $e->course->title, 'due_on' => $e->due_on?->toDateString()]);
                        if ($managerId = $e->employee?->currentManager?->manager_id) {
                            DevelopmentEvent::dispatch('learning.reminder.team_overdue', $e->employee, $e, ['course' => $e->course->title, 'employee' => $e->employee->person?->full_name], [$managerId]);
                        }
                        $sent['overdue_mandatory']++;
                    }
                }
            });

        SkillAssessment::query()->withoutGlobalScope(AccessScope::class)->with(['employee.person', 'skill'])->where('status', 'draft')->where('created_at', '<=', $today->copy()->subDays($throttle))
            ->whereNotNull('assessor_employee_id')
            ->chunkById(500, function ($assessments) use (&$sent, $throttle) {
                foreach ($assessments as $a) {
                    if ($this->once('assessment_due', $a, $throttle)) {
                        SkillEvent::dispatch('skill.reminder.assessment_due', $a->employee, $a, ['skill' => $a->skill?->name, 'employee' => $a->employee?->person?->full_name], [$a->assessor_employee_id]);
                        $sent['assessments']++;
                    }
                }
            });

        $days = (int) config('peopleos.development.milestone_reminder_days', 7);
        DevelopmentPlanItem::query()->with('plan.employee')->whereIn('item_type', ['milestone', 'review'])->where('status', 'open')
            ->whereDate('due_on', '>=', $today)->whereDate('due_on', '<=', $today->copy()->addDays($days))
            ->chunkById(500, function ($items) use (&$sent) {
                foreach ($items as $item) {
                    $plan = $item->plan;
                    if ($plan === null || $plan->status !== 'active' || ! $this->once('milestone', $item, null)) {
                        continue;
                    }
                    DevelopmentEvent::dispatch('development.reminder.milestone_due', $plan->employee, $item, ['title' => $item->title, 'due_on' => $item->due_on->toDateString()], array_values(array_filter([$plan->employee_id, $plan->owner_employee_id])));
                    $sent['milestones']++;
                }
            });

        return $sent;
    }

    /** True the first time (or once per $everyDays window when given) for this reminder and subject. */
    private function once(string $reminder, Model $subject, ?int $everyDays): bool
    {
        $type = class_basename($subject);
        if ($everyDays !== null && LearningReminderLog::query()->where('reminder', $reminder)->where('subject_type', $type)->where('subject_id', $subject->getKey())
            ->whereDate('reminded_on', '>', Carbon::now()->subDays($everyDays)->toDateString())->exists()) {
            return false;
        }
        if ($everyDays === null && LearningReminderLog::query()->where('reminder', $reminder)->where('subject_type', $type)->where('subject_id', $subject->getKey())->exists()) {
            return false;
        }
        try {
            LearningReminderLog::query()->create(['reminder' => $reminder, 'subject_type' => $type, 'subject_id' => $subject->getKey(), 'reminded_on' => now()->toDateString()]);

            return true;
        } catch (UniqueConstraintViolationException) {
            return false;
        }
    }
}
