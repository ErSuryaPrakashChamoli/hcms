<?php

namespace App\Domain\Performance\Support;

use App\Domain\Employment\Models\Employee;
use App\Domain\Experience\Contracts\ExperienceTaskSource;
use App\Domain\Experience\Support\ExperienceTask;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Scopes\AccessScope;
use App\Domain\Performance\Models\AppraisalReview;
use App\Domain\Performance\Services\PerformanceWindows;
use App\Filament\Resources\Appraisals\AppraisalResource;
use Illuminate\Support\Collection;
use Throwable;

/**
 * HROne parity GO-9 (matrix 27 §27.11 #10, HROne "Performance review"): "My tasks" items for the reviews the person must
 * write — their own pending self, manager, approver, peer, upward, skip-level or external review of an appraisal that is
 * still open (cycle running, not finalized, locked or withdrawn) whose stage has opened. Due when the review's stage
 * window ends for that employee (GO-2 extensions included). Only the reviewer gets the task; it names the employee and
 * the cycle, never a rating, so it adds nothing to what the reviewer already sees (AppraisalVisibility: a reviewer always
 * sees the review they write).
 */
final class PerformanceReviewTaskSource implements ExperienceTaskSource
{
    public function __construct(private readonly PerformanceWindows $windows) {}

    public function tasksFor(User $user, ?Employee $employee): Collection
    {
        if ($employee === null) {
            return collect();
        }
        $reviews = AppraisalReview::query()
            ->with(['appraisal' => fn ($q) => $q->withoutGlobalScope(AccessScope::class)->with(['cycle', 'employee' => fn ($e) => $e->withoutGlobalScope(AccessScope::class)->with('person')])])
            ->where('reviewer_id', $employee->id)->where('status', 'pending')
            ->whereHas('appraisal', fn ($q) => $q->withoutGlobalScope(AccessScope::class)->whereNull('locked_at')->whereNotIn('status', ['finalized', 'acknowledged', 'withdrawn'])
                ->whereHas('cycle', fn ($c) => $c->where('status', 'active')))
            ->orderBy('id')->limit(500)->get()
            ->filter(function (AppraisalReview $review) {
                $cycle = $review->appraisal?->cycle;
                $stage = PerformanceWindows::stageOfReview($review->type);

                // As submitReview: a review of a stage the cycle has not reached yet cannot be written.
                return $cycle !== null && (! $cycle->hasStage($stage) || $cycle->isAtOrPast($stage));
            });

        $due = [];
        foreach ($reviews->groupBy(fn (AppraisalReview $r) => $r->appraisal->performance_cycle_id.'|'.PerformanceWindows::stageOfReview($r->type)) as $group) {
            $first = $group->first();
            $stage = PerformanceWindows::stageOfReview($first->type);
            $ends = $this->windows->stageEnds($first->appraisal->cycle, $stage, $group->map(fn (AppraisalReview $r) => (int) $r->appraisal->employee_id));
            foreach ($group as $review) {
                $due[$review->id] = $ends[(int) $review->appraisal->employee_id] ?? null;
            }
        }

        return $reviews->map(function (AppraisalReview $review) use ($due) {
            $appraisal = $review->appraisal;
            $cycle = $appraisal->cycle->name;
            $type = (string) config("peopleos.performance.review_types.{$review->type}", $review->type);
            $title = $review->type === 'self'
                ? "Self review for {$cycle}"
                : $type.' review of '.($appraisal->employee?->person?->full_name ?? 'an employee')." ({$cycle})";

            return new ExperienceTask('performance', 'review', $title, $cycle, ($due[$review->id] ?? null)?->copy()->endOfDay(),
                $this->url(fn () => AppraisalResource::getUrl('view', ['record' => $appraisal->id])),
                ['employee_id' => (int) $appraisal->employee_id, 'requested_at' => $review->created_at]);
        })->values();
    }

    private function url(\Closure $url): ?string
    {
        try {
            return $url();
        } catch (Throwable) {
            return null;
        }
    }
}
