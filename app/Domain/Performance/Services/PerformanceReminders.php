<?php

namespace App\Domain\Performance\Services;

use App\Domain\Employment\Models\Employee;
use App\Domain\Performance\Events\PerformanceEvent;
use App\Domain\Performance\Models\AppraisalReview;
use App\Domain\Performance\Models\Goal;
use App\Domain\Performance\Models\ImprovementPlanCheckpoint;
use App\Domain\Performance\Models\PerformanceCheckIn;
use App\Domain\Performance\Models\PerformanceCycle;
use App\Domain\Performance\Models\PerformanceReminderLog;
use App\Domain\Platform\Services\SettingsRepository;
use Carbon\CarbonInterface;
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

    /**
     * HROne parity GO-1 ("Remind reviewers" on Review requests, HROne "Send Reminder"): remind the reviewer of a pending
     * review now, with the same notification as the scheduled reminder and sharing its log — at most once per review and
     * day, so a reviewer already reminded today is not reminded again (false). Refused for a review that is not pending,
     * an appraisal that is finalized or locked, a cycle that is not running, or a review whose stage has not opened.
     */
    public function remindReviewer(AppraisalReview $review): bool
    {
        $review->loadMissing(['appraisal.cycle', 'appraisal.employee']);
        $appraisal = $review->appraisal;
        $cycle = $appraisal?->cycle;
        if ($review->status !== 'pending' || $review->reviewer_id === null) {
            throw new \RuntimeException('Only a pending review with a reviewer can be reminded.');
        }
        if ($appraisal === null || $appraisal->isFinal() || $appraisal->isLocked()) {
            throw new \RuntimeException('The appraisal is finalized: there is nothing left to review.');
        }
        if ($cycle === null || $cycle->status !== 'active') {
            throw new \RuntimeException('The cycle is not running.');
        }
        $stageKey = match ($review->type) {
            'self' => 'self_review', 'manager' => 'manager_review', 'approver' => 'approver_review', default => 'peer_review'
        };
        if ($cycle->hasStage($stageKey) && ! $cycle->isAtOrPast($stageKey)) {
            throw new \RuntimeException('The '.config("peopleos.performance.stages.{$stageKey}", $stageKey).' stage has not opened yet.');
        }
        if (! $this->once('review_due', $review, now()->startOfDay())) {
            return false;
        }
        $stage = collect($cycle->stages ?? [])->firstWhere('key', $stageKey);
        $due = ! empty($stage['ends_on']) ? Carbon::parse($stage['ends_on'])->toDateString() : 'the end of the '.mb_strtolower((string) config("peopleos.performance.stages.{$stageKey}", 'review')).' stage';
        PerformanceEvent::dispatch('performance.reminder.review_due', $appraisal->employee, $review, ['cycle' => $cycle->name, 'due' => $due], [(int) $review->reviewer_id]);

        return true;
    }

    /** HP-8b: the goals each scheduled check-in prompt is about (goal levels). */
    public const CHECK_IN_SCOPES = ['company' => ['company', 'business'], 'team' => ['department', 'team'], 'employee' => ['employee']];

    /**
     * HROne setup parity HP-8b (Goal check-in: company / team / employee): on the days the settings
     * performance.checkin.<scope>.interval / .day name (weekly: 1 = Monday … 7 = Sunday; monthly: day 1–28), prompt
     * the people who keep those goals up to date — company / team: the employees who created open company and business
     * / department and team goals; employee: employees with active goals. Once per person, scope and day (reminder log).
     *
     * @return array{company: int, team: int, employee: int}
     */
    public function checkInPrompts(CarbonInterface $day): array
    {
        $today = Carbon::parse($day->toDateString())->startOfDay();
        $sent = ['company' => 0, 'team' => 0, 'employee' => 0];
        foreach (self::CHECK_IN_SCOPES as $scope => $levels) {
            if (! self::checkInDue($scope, $today)) {
                continue;
            }
            $goals = Goal::query()->whereIn('level', $levels);
            if ($scope === 'employee') {
                $counts = $goals->where('status', 'active')->whereNotNull('employee_id')->selectRaw('employee_id as owner, count(*) as n')->groupBy('employee_id')->toBase()->get()->pluck('n', 'owner');
                $employees = Employee::query()->employed()->whereIn('id', $counts->keys())->get();
            } else {
                $byCreator = $goals->whereIn('status', ['draft', 'active'])->whereNotNull('created_by')->selectRaw('created_by as owner, count(*) as n')->groupBy('created_by')->toBase()->get()->pluck('n', 'owner');
                $employees = Employee::query()->employed()->whereIn('user_id', $byCreator->keys())->get();
                $counts = $employees->mapWithKeys(fn (Employee $e) => [$e->id => $byCreator[$e->user_id] ?? 0]);
            }
            foreach ($employees as $employee) {
                if ($this->once("goal_check_in_{$scope}", $employee, $today)) {
                    PerformanceEvent::dispatch('performance.reminder.goal_check_in', $employee, $employee, ['scope' => $scope, 'goals' => (int) ($counts[$employee->id] ?? 0)], [$employee->id]);
                    $sent[$scope]++;
                }
            }
        }

        return $sent;
    }

    public static function checkInDue(string $scope, CarbonInterface $day): bool
    {
        $settings = app(SettingsRepository::class);
        $interval = (string) $settings->get("performance.checkin.{$scope}.interval", 'none');
        $number = max(1, (int) $settings->get("performance.checkin.{$scope}.day", 1));

        return match ($interval) {
            'weekly' => $day->dayOfWeekIso === (($number - 1) % 7) + 1,
            'monthly' => $day->day === min(28, $number),
            default => false,
        };
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
