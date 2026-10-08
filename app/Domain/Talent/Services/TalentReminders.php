<?php

namespace App\Domain\Talent\Services;

use App\Domain\Identity\Scopes\AccessScope;
use App\Domain\Succession\Events\SuccessionEvent;
use App\Domain\Succession\Models\CriticalPosition;
use App\Domain\Succession\Models\ReadinessAssessment;
use App\Domain\Succession\Models\SuccessionPlan;
use App\Domain\Talent\Events\TalentEvent;
use App\Domain\Talent\Models\TalentReminderLog;
use App\Domain\Talent\Models\TalentReviewSession;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;

/**
 * Phase 9 talent and succession reminders for the current tenant, throttled through
 * talent_reminder_logs: critical-position reviews due or overdue, succession-plan reviews due,
 * readiness labels about to expire, and talent reviews coming up. Recipients are the people who own
 * the record (never the employee concerned). Re-running never re-sends within the window.
 */
final class TalentReminders
{
    /** @return array{position_reviews: int, plan_reviews: int, readiness_expiring: int, talent_reviews: int} */
    public function tick(): array
    {
        $today = now()->startOfDay();
        $horizon = $today->copy()->addDays((int) config('peopleos.talent.review_reminder_days', 14));
        $every = 7;
        $sent = ['position_reviews' => 0, 'plan_reviews' => 0, 'readiness_expiring' => 0, 'talent_reviews' => 0];

        CriticalPosition::query()->where('status', 'active')->whereNotNull('next_review_on')->whereDate('next_review_on', '<=', $horizon)
            ->chunkById(500, function ($positions) use (&$sent, $every) {
                foreach ($positions as $position) {
                    if ($position->created_by && $this->once('position_review', $position, $every)) {
                        SuccessionEvent::dispatch('succession.reminder.position_review', null, $position, ['title' => $position->title, 'due_on' => $position->next_review_on->toDateString()], [], [$position->created_by]);
                        $sent['position_reviews']++;
                    }
                }
            });

        SuccessionPlan::query()->with('position:id,title')->where('status', '!=', 'closed')->whereNotNull('review_date')->whereDate('review_date', '<=', $horizon)
            ->chunkById(500, function ($plans) use (&$sent, $every) {
                foreach ($plans as $plan) {
                    if ($plan->owner_user_id && $this->once('plan_review', $plan, $every)) {
                        SuccessionEvent::dispatch('succession.reminder.plan_review', null, $plan, ['title' => $plan->position?->title, 'due_on' => $plan->review_date->toDateString()], [], [$plan->owner_user_id]);
                        $sent['plan_reviews']++;
                    }
                }
            });

        ReadinessAssessment::query()->withoutGlobalScope(AccessScope::class)->with('position:id,title')->where('status', 'current')->whereNotNull('effective_to')
            ->whereDate('effective_to', '>=', $today)->whereDate('effective_to', '<=', $horizon)
            ->chunkById(500, function ($rows) use (&$sent) {
                foreach ($rows as $row) {
                    if ($row->assessed_by && $this->once('readiness_expiring', $row, null)) {
                        // The employee is not told: readiness is confidential.
                        SuccessionEvent::dispatch('succession.reminder.readiness_expiring', null, $row, ['title' => $row->position?->title, 'expires_on' => $row->effective_to->toDateString()], [], [$row->assessed_by]);
                        $sent['readiness_expiring']++;
                    }
                }
            });

        TalentReviewSession::query()->whereIn('status', ['draft', 'in_progress'])->whereNotNull('scheduled_for')->whereDate('scheduled_for', '>=', $today)->whereDate('scheduled_for', '<=', $horizon)
            ->chunkById(500, function ($sessions) use (&$sent) {
                foreach ($sessions as $session) {
                    if ($this->once('talent_review', $session, null)) {
                        TalentEvent::dispatch('talent.reminder.review_scheduled', null, $session, ['name' => $session->name, 'scheduled_for' => $session->scheduled_for->toDateString()], [], array_values(array_map('intval', $session->participants ?? [])));
                        $sent['talent_reviews']++;
                    }
                }
            });

        return $sent;
    }

    /** True the first time (or once per $everyDays window when given) for this reminder and subject. */
    private function once(string $reminder, Model $subject, ?int $everyDays): bool
    {
        $type = class_basename($subject);
        $logged = TalentReminderLog::query()->where('reminder', $reminder)->where('subject_type', $type)->where('subject_id', $subject->getKey())
            ->when($everyDays !== null, fn ($q) => $q->whereDate('reminded_on', '>', Carbon::now()->subDays($everyDays)->toDateString()));
        if ($logged->exists()) {
            return false;
        }
        try {
            TalentReminderLog::query()->create(['reminder' => $reminder, 'subject_type' => $type, 'subject_id' => $subject->getKey(), 'reminded_on' => now()->toDateString()]);

            return true;
        } catch (UniqueConstraintViolationException) {
            return false;
        }
    }
}
