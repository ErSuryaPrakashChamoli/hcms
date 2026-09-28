<?php

namespace App\Domain\Performance\Services;

use App\Domain\Performance\Events\PerformanceEvent;
use App\Domain\Performance\Models\AppraisalReview;
use App\Domain\Performance\Models\ImprovementPlanCheckpoint;
use App\Domain\Performance\Models\PerformanceCheckIn;
use App\Domain\Performance\Models\PerformanceCycle;
use App\Domain\Performance\Models\PerformanceReminderLog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;

/**
 * Phase 7 reminders for the current tenant: reviews due in the open stage, check-ins waiting for
 * the manager, PIP checkpoints coming up. Each subject is reminded at most once per day (the
 * reminder log is unique), so re-running the command never re-sends.
 */
final class PerformanceReminders
{
    /** @return array{reviews: int, check_ins: int, checkpoints: int} */
    public function tick(): array
    {
        $today = now()->startOfDay();
        $lead = (int) config('peopleos.performance.reminder_days_before', 3);
        $sent = ['reviews' => 0, 'check_ins' => 0, 'checkpoints' => 0];

        PerformanceCycle::query()->where('status', 'active')->get()->each(function (PerformanceCycle $cycle) use ($today, $lead, &$sent) {
            $stage = collect($cycle->stages ?? [])->firstWhere('key', $cycle->current_stage);
            $type = match ($cycle->current_stage) {
                'self_review' => 'self', 'manager_review' => 'manager', 'peer_review' => 'peer', default => null
            };
            if ($type === null || empty($stage['ends_on']) || Carbon::parse($stage['ends_on'])->gt($today->copy()->addDays($lead))) {
                return;
            }
            AppraisalReview::query()->with('appraisal.employee')->whereHas('appraisal', fn ($q) => $q->where('performance_cycle_id', $cycle->id)->whereNull('locked_at'))
                ->where('type', $type)->where('status', 'pending')->whereNotNull('reviewer_id')->get()
                ->each(function (AppraisalReview $review) use ($cycle, $stage, $today, &$sent) {
                    if ($this->once('review_due', $review, $today)) {
                        PerformanceEvent::dispatch('performance.reminder.review_due', $review->appraisal->employee, $review, ['cycle' => $cycle->name, 'due' => $stage['ends_on']], [$review->reviewer_id]);
                        $sent['reviews']++;
                    }
                });
        });

        PerformanceCheckIn::query()->with('employee')->where('status', 'submitted')->whereNotNull('manager_id')->where('submitted_at', '<=', now()->subDays($lead))->get()
            ->each(function (PerformanceCheckIn $checkIn) use ($today, &$sent) {
                if ($this->once('check_in_response', $checkIn, $today)) {
                    PerformanceEvent::dispatch('performance.reminder.check_in_response', $checkIn->employee, $checkIn, ['period' => $checkIn->period_date->toDateString()], [$checkIn->manager_id]);
                    $sent['check_ins']++;
                }
            });

        ImprovementPlanCheckpoint::query()->with('plan.employee')->where('status', 'pending')->whereBetween('due_date', [$today->toDateString(), $today->copy()->addDays($lead)->toDateString()])->get()
            ->filter(fn (ImprovementPlanCheckpoint $c) => $c->plan?->isOpen() && $c->plan->manager_id)
            ->each(function (ImprovementPlanCheckpoint $checkpoint) use ($today, &$sent) {
                if ($this->once('pip_checkpoint', $checkpoint, $today)) {
                    PerformanceEvent::dispatch('performance.reminder.pip_checkpoint', $checkpoint->plan->employee, $checkpoint, ['due' => $checkpoint->due_date->toDateString()], [$checkpoint->plan->manager_id]);
                    $sent['checkpoints']++;
                }
            });

        return $sent;
    }

    private function once(string $reminder, Model $subject, Carbon $day): bool
    {
        try {
            PerformanceReminderLog::query()->create(['reminder' => $reminder, 'subject_type' => class_basename($subject), 'subject_id' => $subject->getKey(), 'reminded_on' => $day->toDateString()]);

            return true;
        } catch (UniqueConstraintViolationException) {
            return false;
        }
    }
}
