<?php

namespace App\Domain\Performance\Services;

use App\Domain\Employment\Models\Employee;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Scopes\AccessScope;
use App\Domain\Performance\Events\PerformanceEvent;
use App\Domain\Performance\Models\Appraisal;
use App\Domain\Performance\Models\PerformanceCycle;
use App\Domain\Performance\Models\PerformancePeriodReview;
use App\Domain\Performance\Support\AppraisalVisibility;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

/**
 * HROne setup parity HP-8i (PF-7; HR Desk → Performance → "Initiate review"): periodic reviews inside an appraisal cycle.
 *
 * - plan: when a cycle is launched, each appraisal whose employee's performance policy runs a review cycle shorter than the
 *   appraisal cycle gets one review per period (scheduled, with its window, rated by the appraisal's manager). No policy,
 *   or a yearly review cycle: nothing (as before).
 * - the schedule (peopleos:performance:periodic-reviews, daily) opens reviews whose window has started (unless the policy
 *   asks for manual initiation) and marks those whose window has passed unrated as missed; initiate opens them by hand
 *   (performance administrators, or holders of the policy's initiating roles).
 * - submit: the reviewer (or a performance administrator) rates an open review inside its window, on the cycle's scale
 *   (above its maximum only when the policy allows); the appraisal is scored again, so the policy's roll-up applies.
 */
final class PeriodicReviews
{
    public function __construct(private readonly PerformancePolicyRules $rules, private readonly PerformanceRelationships $relationships) {}

    /** @return int the reviews planned for the cycle's appraisals */
    public function plan(PerformanceCycle $cycle): int
    {
        $planned = 0;
        Appraisal::query()->withoutGlobalScope(AccessScope::class)->with('employee')->where('performance_cycle_id', $cycle->id)->orderBy('id')->get()
            ->each(function (Appraisal $appraisal) use ($cycle, &$planned) {
                foreach ($this->rules->periods($cycle, $this->rules->policyFor($appraisal->employee)) as $period) {
                    $review = PerformancePeriodReview::query()->withoutGlobalScope(AccessScope::class)->firstOrCreate(
                        ['appraisal_id' => $appraisal->id, 'sequence' => $period['sequence']],
                        ['performance_cycle_id' => $cycle->id, 'employee_id' => $appraisal->employee_id, 'reviewer_id' => $appraisal->manager_id, 'status' => 'scheduled'] + $period,
                    );
                    $planned += $review->wasRecentlyCreated ? 1 : 0;
                }
            });

        return $planned;
    }

    /** @return array{opened: int, missed: int} */
    public function runSchedule(): array
    {
        $today = today()->toDateString();
        $active = PerformanceCycle::query()->where('status', 'active')->pluck('id')->all();
        $base = fn () => PerformancePeriodReview::query()->withoutGlobalScope(AccessScope::class)->whereIn('performance_cycle_id', $active ?: [0])
            ->whereHas('appraisal', fn ($q) => $q->withoutGlobalScope(AccessScope::class)->whereNull('locked_at')->whereNotIn('status', ['finalized', 'acknowledged']));
        $missed = 0;
        $base()->whereIn('status', ['scheduled', 'open'])->where('window_closes_on', '<', $today)->get()
            ->each(function (PerformancePeriodReview $review) use (&$missed) {
                $review->update(['status' => 'missed']);
                $missed++;
            });
        $opened = 0;
        $base()->where('status', 'scheduled')->where('manual', false)->where('window_opens_on', '<=', $today.' 23:59:59')->where('window_closes_on', '>=', $today)->get()
            ->each(function (PerformancePeriodReview $review) use (&$opened) {
                $this->open($review, null);
                $opened++;
            });

        return ['opened' => $opened, 'missed' => $missed];
    }

    /**
     * Open the scheduled reviews of a period by hand (all employees of the cycle, or the given ones). A review whose window
     * has already passed gets a new window from today for the policy's window days.
     *
     * @param  list<int>|null  $employeeIds
     */
    public function initiate(PerformanceCycle $cycle, int $sequence, ?array $employeeIds, User $actor): int
    {
        if ($cycle->status !== 'active') {
            throw new RuntimeException('Periodic reviews open in an active cycle.');
        }
        $opened = 0;
        PerformancePeriodReview::query()->with('employee')->where('performance_cycle_id', $cycle->id)->where('sequence', $sequence)->where('status', 'scheduled')
            ->when($employeeIds !== null, fn ($q) => $q->whereIn('employee_id', $employeeIds))->orderBy('id')->get()
            ->each(function (PerformancePeriodReview $review) use ($actor, &$opened) {
                if (! $this->rules->mayInitiate($actor, $review->employee)) {
                    return;
                }
                $this->open($review, $actor);
                $opened++;
            });
        if ($opened === 0) {
            throw new RuntimeException('No scheduled periodic review that you may open was found.');
        }

        return $opened;
    }

    public function submit(PerformancePeriodReview $review, float $rating, ?string $comment, User $actor): PerformancePeriodReview
    {
        $submitted = DB::transaction(function () use ($review, $rating, $comment, $actor) {
            $locked = PerformancePeriodReview::query()->withoutGlobalScope(AccessScope::class)->with(['appraisal.cycle.templateVersion', 'appraisal.employee'])->whereKey($review->id)->lockForUpdate()->firstOrFail();
            if (! $this->mayRate($actor, $locked)) {
                throw new RuntimeException('This periodic review is not yours to rate.');
            }
            $appraisal = $locked->appraisal;
            if ($appraisal->isFinal() || $appraisal->isLocked()) {
                throw new RuntimeException('The appraisal is finalized.');
            }
            if ($locked->status !== 'open') {
                throw new RuntimeException($locked->status === 'submitted' ? 'This periodic review was already submitted.' : 'This periodic review is not open.');
            }
            if ($locked->window_closes_on->toDateString() < today()->toDateString()) {
                throw new RuntimeException('The window of this periodic review has closed.');
            }
            $scale = $appraisal->cycle->ratingScale();
            $max = $this->rules->maxRating($appraisal, $scale);
            if ($rating < $scale->min() || $rating > $max) {
                throw new RuntimeException("Ratings must be between {$scale->min()} and {$max}.");
            }
            $locked->update(['rating' => $rating, 'comment' => $comment === null ? null : mb_strimwidth($comment, 0, 2000, '…'), 'status' => 'submitted', 'submitted_by' => $actor->id, 'submitted_at' => now()]);

            return $locked;
        });
        app(Appraisals::class)->score($submitted->appraisal->refresh());

        return $submitted->refresh();
    }

    /** The reviewer (the appraisal's manager when planned) or a performance administrator. */
    public function mayRate(User $user, PerformancePeriodReview $review): bool
    {
        if ($user->hasPermission('performance.manage')) {
            return true;
        }
        $me = $this->relationships->forUser($user);

        return $me !== null && $review->reviewer_id !== null && (int) $review->reviewer_id === (int) $me->id && (int) $review->employee_id !== (int) $me->id;
    }

    /**
     * Who sees a periodic review's rating and comment: those who see the appraisal's reviews (HR, managers, the approver);
     * the employee once it is submitted, unless the performance policy keeps ratings and comments from them.
     */
    public function visibleTo(User $user, PerformancePeriodReview $review): bool
    {
        $appraisal = $review->appraisal;
        if (AppraisalVisibility::isPrivileged($appraisal, $user)) {
            return true;
        }

        return AppraisalVisibility::isOwn($appraisal, $user) && $review->status === 'submitted' && $this->rules->visibility($appraisal, 'ratings') === 'all';
    }

    private function open(PerformancePeriodReview $review, ?User $actor): void
    {
        $changes = ['status' => 'open', 'opened_by' => $actor?->id, 'opened_at' => now()];
        if ($review->window_closes_on->toDateString() < today()->toDateString() || $review->window_opens_on->toDateString() > today()->toDateString()) {
            $days = max(1, $review->window_opens_on->diffInDays($review->window_closes_on) + 1);
            $changes += ['window_opens_on' => today()->toDateString(), 'window_closes_on' => today()->copy()->addDays((int) $days - 1)->toDateString()];
        }
        $review->update($changes);
        try {
            $employee = Employee::query()->withoutGlobalScope(AccessScope::class)->with('person')->find($review->employee_id);
            PerformanceEvent::dispatch('performance.period_review.opened', $employee, $review, ['for' => $employee?->person?->full_name, 'period' => $review->label()], array_filter([(int) $review->reviewer_id]));
        } catch (Throwable $e) {
            report($e);
        }
    }
}
