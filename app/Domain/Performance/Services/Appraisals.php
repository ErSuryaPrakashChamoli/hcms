<?php

namespace App\Domain\Performance\Services;

use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Audit\Services\AuditRecorder;
use App\Domain\Configuration\Services\EmployeeRuleContext;
use App\Domain\Configuration\Services\RuleEngine;
use App\Domain\Employment\Models\Employee;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Scopes\AccessScope;
use App\Domain\Lifecycle\Services\Timeline;
use App\Domain\Performance\Events\PerformanceEvent;
use App\Domain\Performance\Models\Appraisal;
use App\Domain\Performance\Models\AppraisalReview;
use App\Domain\Performance\Models\Goal;
use App\Domain\Performance\Models\PerformanceCycle;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * The appraisal engine (§34–§35): launch a cycle (one appraisal per eligible employee, reviews per
 * stage), move through stages, collect reviews, score, calibrate, finalize, acknowledge.
 */
final class Appraisals
{
    public function __construct(
        private readonly Goals $goals,
        private readonly RuleEngine $rules,
        private readonly EmployeeRuleContext $context,
        private readonly AuditRecorder $audit,
        private readonly Timeline $timeline,
        private readonly PerformanceTemplates $templates,
    ) {}

    // --- Cycle -------------------------------------------------------------------------------

    public function eligible(PerformanceCycle $cycle): Collection
    {
        return Employee::query()->with(['person', 'currentManager'])->employed()
            ->where(fn ($q) => $q->whereNull('joining_date')->orWhere('joining_date', '<=', $cycle->period_end->toDateString().' 23:59:59'))
            ->orderBy('employee_code')->get()
            ->filter(fn (Employee $e) => empty($cycle->eligibility) || $this->rules->matches($cycle->eligibility, $this->context->build($e, $cycle->period_end)))
            ->values();
    }

    /** Phase 7: a draft cycle is scheduled for a launch date; its configuration freezes. */
    public function schedule(PerformanceCycle $cycle, string $date, ?User $actor = null): PerformanceCycle
    {
        if ($cycle->status !== 'draft') {
            throw new RuntimeException('Only a draft cycle can be scheduled.');
        }
        if (empty($cycle->stages)) {
            throw new RuntimeException('Define at least one stage before scheduling.');
        }
        $cycle->update(['status' => 'scheduled', 'scheduled_for' => $date]);
        $this->audit->record(AuditAction::Update, 'performance', $cycle, [['field' => 'status', 'before' => 'draft', 'after' => 'scheduled']], null, actor: $actor, metadata: ['scheduled_for' => $date]);

        return $cycle->refresh();
    }

    public function launch(PerformanceCycle $cycle, ?User $actor = null): PerformanceCycle
    {
        if (! in_array($cycle->status, ['draft', 'scheduled'], true)) {
            throw new RuntimeException('Only a draft or scheduled cycle can be launched.');
        }
        if (empty($cycle->stages)) {
            throw new RuntimeException('Define at least one stage before launching.');
        }

        return DB::transaction(function () use ($cycle, $actor) {
            $locked = PerformanceCycle::query()->whereKey($cycle->id)->lockForUpdate()->firstOrFail();
            if (! in_array($locked->status, ['draft', 'scheduled'], true)) {
                throw new RuntimeException('The cycle was launched by someone else.');
            }
            $before = $locked->status;
            // Pin the template version: the scale, competencies, workflow and weights as they are now.
            $version = $this->templates->pinFor($cycle, $actor);
            $employees = $this->eligible($cycle);
            $first = $cycle->stageKeys()[0];

            foreach ($employees as $employee) {
                $appraisal = Appraisal::query()->firstOrCreate(
                    ['performance_cycle_id' => $cycle->id, 'employee_id' => $employee->id],
                    ['manager_id' => $employee->currentManager?->manager_id, 'status' => 'pending', 'performance_template_version_id' => $version->id],
                );
                $this->ensureReviews($cycle, $appraisal);
            }
            Appraisal::query()->withoutGlobalScope(AccessScope::class)->where('performance_cycle_id', $cycle->id)->whereNull('performance_template_version_id')->update(['performance_template_version_id' => $version->id]);

            $cycle->update(['status' => 'active', 'current_stage' => $first, 'launched_at' => now(), 'performance_template_version_id' => $version->id]);
            $this->audit->record(AuditAction::Update, 'performance', $cycle, [['field' => 'status', 'before' => $before, 'after' => 'active']], null, actor: $actor, metadata: ['appraisals' => $employees->count(), 'template_version_id' => $version->id, 'template_checksum' => $version->checksum]);
            PerformanceEvent::dispatch('performance.cycle.published', null, $cycle, ['cycle' => $cycle->name, 'template_version' => $version->version], []);
            PerformanceEvent::dispatch('performance.cycle.launched', null, $cycle, ['cycle' => $cycle->name, 'stage' => config("peopleos.performance.stages.{$first}"), 'appraisals' => $employees->count()], $employees->pluck('id')->all());
            $this->onStageEntered($cycle, $first);

            return $cycle->refresh();
        });
    }

    /** Add the reviews a cycle's stages call for (self, manager; peers are added by request). */
    public function ensureReviews(PerformanceCycle $cycle, Appraisal $appraisal): void
    {
        if ($cycle->hasStage('self_review')) {
            $appraisal->reviews()->firstOrCreate(['type' => 'self'], ['reviewer_id' => $appraisal->employee_id, 'status' => 'pending']);
        }
        if ($cycle->hasStage('manager_review') && $appraisal->manager_id) {
            $appraisal->reviews()->firstOrCreate(['type' => 'manager'], ['reviewer_id' => $appraisal->manager_id, 'status' => 'pending']);
        }
    }

    public function advance(PerformanceCycle $cycle, ?User $actor = null): PerformanceCycle
    {
        if ($cycle->status !== 'active') {
            throw new RuntimeException('Only an active cycle moves between stages.');
        }

        $next = $cycle->nextStage();
        if ($next === null) {
            return $this->close($cycle, $actor);
        }

        $previous = $cycle->current_stage;
        $cycle->update(['current_stage' => $next]);
        $this->audit->record(AuditAction::Update, 'performance', $cycle, [['field' => 'current_stage', 'before' => $previous, 'after' => $next]], null, actor: $actor);
        $this->onStageEntered($cycle, $next);
        PerformanceEvent::dispatch('performance.cycle.stage_changed', null, $cycle, ['cycle' => $cycle->name, 'stage' => config("peopleos.performance.stages.{$next}")], $cycle->appraisals()->pluck('employee_id')->all());

        return $cycle->refresh();
    }

    private function onStageEntered(PerformanceCycle $cycle, string $stage): void
    {
        if ($stage === 'self_review' || ($stage === 'manager_review' && ! $cycle->hasStage('self_review'))) {
            // Goals become the fixed basis of the review once reviewing starts.
            $this->goals->lockForCycle($cycle->id, $cycle->appraisals()->pluck('employee_id')->all());
        }

        if (in_array($stage, ['self_review', 'manager_review', 'peer_review'], true)) {
            $type = match ($stage) {
                'self_review' => 'self', 'manager_review' => 'manager', default => 'peer'
            };
            $cycle->appraisals()->where('status', 'pending')->update(['status' => 'in_review']);

            AppraisalReview::query()->with('appraisal.employee')->whereIn('appraisal_id', $cycle->appraisals()->pluck('id'))->where('type', $type)->where('status', 'pending')->get()
                ->each(fn (AppraisalReview $review) => PerformanceEvent::dispatch('performance.review.assigned', $review->appraisal->employee, $review, ['cycle' => $cycle->name, 'type' => config("peopleos.performance.review_types.{$type}")], array_filter([$review->reviewer_id])));
        }

        if ($stage === 'calibration') {
            $cycle->appraisals()->whereNotIn('status', ['finalized', 'acknowledged'])->get()->each(function (Appraisal $a) {
                $this->score($a);
                $a->update(['status' => 'calibration']);
            });
        }

        if ($stage === 'final') {
            $cycle->appraisals()->whereNotIn('status', ['finalized', 'acknowledged'])->get()->each(fn (Appraisal $a) => $this->score($a));
        }
    }

    public function close(PerformanceCycle $cycle, ?User $actor = null): PerformanceCycle
    {
        return DB::transaction(function () use ($cycle, $actor) {
            $locked = PerformanceCycle::query()->whereKey($cycle->id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== 'active') {
                throw new RuntimeException('Only an active cycle can be closed.');
            }
            $all = Appraisal::query()->withoutGlobalScope(AccessScope::class)->where('performance_cycle_id', $cycle->id);
            $open = (clone $all)->whereNotIn('status', ['finalized', 'acknowledged'])->count();
            if ($open > 0) {
                throw new RuntimeException("{$open} appraisal(s) are not finalized yet.");
            }

            $cycle->update(['status' => 'closed', 'closed_at' => now()]);
            // Phase 7: every appraisal (and its reviews and ratings) locks with the cycle.
            (clone $all)->whereNull('locked_at')->update(['locked_at' => now()]);
            Goal::query()->where('performance_cycle_id', $cycle->id)->where('status', 'active')->update(['status' => 'completed']);
            $this->audit->record(AuditAction::Update, 'performance', $cycle, [['field' => 'status', 'before' => 'active', 'after' => 'closed']], null, actor: $actor, metadata: ['appraisals_locked' => (clone $all)->count()]);

            return $cycle->refresh();
        });
    }

    /** Phase 7: a closed cycle is archived — read-only from then on. */
    public function archive(PerformanceCycle $cycle, ?User $actor = null): PerformanceCycle
    {
        if ($cycle->status !== 'closed') {
            throw new RuntimeException('Only a closed cycle can be archived.');
        }
        $cycle->update(['status' => 'archived', 'archived_at' => now()]);
        $this->audit->record(AuditAction::Update, 'performance', $cycle, [['field' => 'status', 'before' => 'closed', 'after' => 'archived']], null, actor: $actor);

        return $cycle->refresh();
    }

    // --- Reviews -----------------------------------------------------------------------------

    public function addPeerReview(Appraisal $appraisal, Employee $reviewer, string $type = 'peer', bool $anonymous = false): AppraisalReview
    {
        $appraisal->loadMissing(['cycle', 'employee']);
        if ($appraisal->isFinal()) {
            throw new RuntimeException('The appraisal is finalized.');
        }
        if ($reviewer->id === $appraisal->employee_id) {
            throw new RuntimeException('An employee cannot be their own peer reviewer.');
        }

        $review = $appraisal->reviews()->firstOrCreate(['type' => $type, 'reviewer_id' => $reviewer->id], ['status' => 'pending', 'is_anonymous' => $anonymous]);
        PerformanceEvent::dispatch('performance.review.assigned', $appraisal->employee, $review, ['cycle' => $appraisal->cycle->name, 'type' => config("peopleos.performance.review_types.{$type}")], [$reviewer->id]);

        return $review;
    }

    /**
     * @param  array<string, float>  $goalRatings  goal id => rating
     * @param  array<string, float>  $competencyRatings  competency id => rating
     */
    public function submitReview(AppraisalReview $review, array $goalRatings, array $competencyRatings, ?float $overall, array $text = [], ?User $actor = null): AppraisalReview
    {
        // Always judge against the stored state: a stale loaded relation must not bypass finalization.
        $appraisal = $review->appraisal()->with(['cycle.templateVersion', 'employee'])->firstOrFail();
        $review->setRelation('appraisal', $appraisal);
        $cycle = $appraisal->cycle;

        if ($appraisal->isFinal()) {
            throw new RuntimeException('The appraisal is finalized.');
        }
        if ($review->isSubmitted() && ! $cycle->setting('allow_resubmit', false)) {
            throw new RuntimeException('This review was already submitted.');
        }

        $stage = match ($review->type) {
            'self' => 'self_review', 'manager' => 'manager_review', default => 'peer_review'
        };
        if ($cycle->hasStage($stage) && ! $cycle->isAtOrPast($stage)) {
            throw new RuntimeException('The '.config("peopleos.performance.stages.{$stage}").' stage has not opened yet.');
        }

        $scale = $cycle->ratingScale();
        $validate = function (array $ratings) use ($scale) {
            foreach ($ratings as $value) {
                if ($value !== null && ($value < $scale->min() || $value > $scale->max())) {
                    throw new RuntimeException("Ratings must be between {$scale->min()} and {$scale->max()}.");
                }
            }
        };
        $validate($goalRatings);
        $validate($competencyRatings);
        $validate([$overall]);

        return DB::transaction(function () use ($review, $goalRatings, $competencyRatings, $overall, $text, $appraisal, $actor, $cycle) {
            // Phase 7: serialize concurrent submissions and re-check the stored state under the lock.
            $current = Appraisal::query()->withoutGlobalScope(AccessScope::class)->whereKey($appraisal->id)->lockForUpdate()->firstOrFail();
            if ($current->isFinal() || $current->isLocked()) {
                throw new RuntimeException('The appraisal is finalized.');
            }
            if (AppraisalReview::query()->whereKey($review->id)->lockForUpdate()->value('status') === 'submitted' && ! $cycle->setting('allow_resubmit', false)) {
                throw new RuntimeException('This review was already submitted.');
            }
            $review->ratings()->delete();
            foreach ($goalRatings as $id => $rating) {
                if ($rating !== null) {
                    $review->ratings()->create(['subject_type' => 'goal', 'subject_id' => (int) $id, 'rating' => $rating, 'comment' => $text['goal_comments'][$id] ?? null]);
                }
            }
            foreach ($competencyRatings as $id => $rating) {
                if ($rating !== null) {
                    $review->ratings()->create(['subject_type' => 'competency', 'subject_id' => (int) $id, 'rating' => $rating, 'comment' => $text['competency_comments'][$id] ?? null]);
                }
            }

            $review->update([
                'overall_rating' => $overall,
                'strengths' => $text['strengths'] ?? null,
                'improvements' => $text['improvements'] ?? null,
                'comments' => $text['comments'] ?? null,
                'status' => 'submitted',
                'submitted_at' => now(),
            ]);

            $this->audit->record(AuditAction::Submitted, 'performance', $review, [], null, actor: $actor, metadata: ['appraisal_id' => $appraisal->id, 'type' => $review->type]);
            $this->score($appraisal->refresh());
            PerformanceEvent::dispatch('performance.review.submitted', $appraisal->employee, $review, ['cycle' => $appraisal->cycle->name, 'type' => config("peopleos.performance.review_types.{$review->type}")], array_filter([$review->type === 'self' ? $appraisal->manager_id : ($review->type === 'manager' ? null : $appraisal->manager_id)]));

            return $review->refresh();
        });
    }

    // --- Scoring -----------------------------------------------------------------------------

    /**
     * Manager ratings drive the computed score: goals weighted by goal weight (equal if none),
     * competencies averaged, blended by the cycle weights, expressed on the cycle's scale.
     */
    public function score(Appraisal $appraisal): Appraisal
    {
        $appraisal->loadMissing(['cycle.templateVersion', 'reviews.ratings', 'employee']);
        $cycle = $appraisal->cycle;
        $scale = $cycle->ratingScale();
        $goals = $this->goals->forEmployee($appraisal->employee, $cycle->id)->where('status', '!=', 'cancelled');

        $manager = $appraisal->review('manager');
        $self = $appraisal->review('self');
        $peers = $appraisal->reviews->whereIn('type', ['peer', 'upward', 'skip_level', 'external'])->where('status', 'submitted');

        $source = $manager?->isSubmitted() ? $manager : null;
        $goalScore = $source ? $this->weightedGoalScore($source, $goals, $scale) : null;
        $competencyScore = $source ? $this->averageScore($source->ratings->where('subject_type', 'competency'), $scale) : null;

        $computed = null;
        if ($goalScore !== null || $competencyScore !== null) {
            $wGoals = $goalScore === null ? 0 : $cycle->weight('goals');
            $wComp = $competencyScore === null ? 0 : $cycle->weight('competencies');
            $blend = ($wGoals + $wComp) > 0 ? (($goalScore ?? 0) * $wGoals + ($competencyScore ?? 0) * $wComp) / ($wGoals + $wComp) : null;
            $computed = $blend === null ? null : round($scale->min() + ($scale->max() - $scale->min()) * $blend / 100, 2);
        }
        if ($computed === null && $manager?->overall_rating !== null) {
            $computed = (float) $manager->overall_rating;
        }

        $appraisal->update([
            'goal_score' => $goalScore === null ? null : round($goalScore, 2),
            'competency_score' => $competencyScore === null ? null : round($competencyScore, 2),
            'self_rating' => $self?->overall_rating,
            'manager_rating' => $manager?->overall_rating ?? $computed,
            'peer_rating' => $peers->isEmpty() ? null : round($peers->avg('overall_rating'), 2),
            'computed_rating' => $computed,
        ]);

        return $appraisal;
    }

    /** Percent (0–100) of scale achieved, weighted by goal weights. */
    private function weightedGoalScore(AppraisalReview $review, Collection $goals, $scale): ?float
    {
        $ratings = $review->ratings->where('subject_type', 'goal')->keyBy('subject_id');
        $rated = $goals->filter(fn ($g) => $ratings->has($g->id));
        if ($rated->isEmpty()) {
            return null;
        }
        $totalWeight = $rated->sum('weight') ?: $rated->count();
        $range = $scale->max() - $scale->min();

        return $rated->sum(function ($g) use ($ratings, $scale, $range, $rated) {
            $w = $rated->sum('weight') > 0 ? $g->weight : 1;

            return $range > 0 ? (((float) $ratings[$g->id]->rating - $scale->min()) / $range * 100) * $w : 0;
        }) / $totalWeight;
    }

    private function averageScore(Collection $ratings, $scale): ?float
    {
        if ($ratings->isEmpty()) {
            return null;
        }
        $range = $scale->max() - $scale->min();

        return $range > 0 ? $ratings->avg(fn ($r) => ((float) $r->rating - $scale->min()) / $range * 100) : null;
    }

    // --- Calibration and finalization -------------------------------------------------------

    /** Calibrate one appraisal (delegates to Calibrations, which keeps the immutable history). */
    public function calibrate(Appraisal $appraisal, float $rating, string $note, ?User $actor = null, ?float $expected = null): Appraisal
    {
        if ($appraisal->isFinal()) {
            throw new RuntimeException('The appraisal is finalized.');
        }
        app(Calibrations::class)->adjust($appraisal, $rating, $note, $expected, null, $actor);

        return $appraisal->refresh();
    }

    public function finalize(Appraisal $appraisal, ?User $actor = null, ?float $rating = null, ?string $summary = null, bool $promotion = false, bool $pip = false): Appraisal
    {
        $appraisal->loadMissing(['cycle.templateVersion', 'employee']);
        $cycle = $appraisal->cycle;

        if ($appraisal->isFinal()) {
            throw new RuntimeException('The appraisal is already finalized.');
        }
        if ($cycle->status !== 'active') {
            throw new RuntimeException('The cycle is not active.');
        }
        if ($cycle->hasStage('final') && ! $cycle->isAtOrPast('final') && ! $cycle->isAtOrPast('calibration')) {
            throw new RuntimeException('Finalization opens at the calibration or final stage.');
        }
        $manager = $appraisal->review('manager');
        if ($manager && ! $manager->isSubmitted() && $rating === null) {
            throw new RuntimeException('The manager review is not submitted; provide a final rating explicitly.');
        }

        $this->score($appraisal);
        $final = $rating ?? $appraisal->refresh()->effectiveRating();
        if ($final === null) {
            throw new RuntimeException('No rating available to finalize.');
        }
        $scale = $cycle->ratingScale();
        if ($final < $scale->min() || $final > $scale->max()) {
            throw new RuntimeException("Ratings must be between {$scale->min()} and {$scale->max()}.");
        }

        return DB::transaction(function () use ($appraisal, $cycle, $scale, $final, $summary, $promotion, $pip, $actor) {
            $current = Appraisal::query()->withoutGlobalScope(AccessScope::class)->whereKey($appraisal->id)->lockForUpdate()->firstOrFail();
            if ($current->isFinal() || $current->isLocked()) {
                throw new RuntimeException('The appraisal is already finalized.');
            }
            $appraisal->update([
                'final_rating' => $final,
                'final_label' => $scale->labelFor($final),
                'manager_summary' => $summary ?? $appraisal->manager_summary,
                'promotion_recommended' => $promotion,
                'pip_recommended' => $pip,
                'status' => 'finalized',
                'finalized_by' => $actor?->id ?? auth()->id(),
                'finalized_at' => now(),
            ]);

            $this->audit->record(AuditAction::Approved, 'performance', $appraisal, [['field' => 'final_rating', 'before' => null, 'after' => $final]], $summary, actor: $actor, metadata: ['cycle' => $cycle->code, 'promotion' => $promotion, 'pip' => $pip]);
            $this->timeline->record($appraisal->employee, 'performance', "{$cycle->name}: {$appraisal->final_label}", $cycle->period_end, $summary, $appraisal, ['rating' => $final]);

            PerformanceEvent::dispatch('performance.appraisal.finalized', $appraisal->employee, $appraisal, ['cycle' => $cycle->name, 'rating' => $final, 'label' => $appraisal->final_label], [$appraisal->employee_id]);
            if ($promotion) {
                PerformanceEvent::dispatch('performance.promotion.recommended', $appraisal->employee, $appraisal, ['cycle' => $cycle->name, 'rating' => $final], array_filter([$appraisal->manager_id]));
            }

            return $appraisal->refresh();
        });
    }

    public function acknowledge(Appraisal $appraisal, ?string $comment = null): Appraisal
    {
        if ($appraisal->status !== 'finalized') {
            throw new RuntimeException('Only a finalized appraisal can be acknowledged.');
        }

        $appraisal->update(['status' => 'acknowledged', 'acknowledged_at' => now(), 'employee_comment' => $comment]);

        return $appraisal;
    }

    /** Rating distribution for a cycle, keyed by scale label. */
    public function distribution(PerformanceCycle $cycle): array
    {
        $scale = $cycle->ratingScale();
        $counts = collect($scale->levels)->mapWithKeys(fn ($l) => [$l['label'] => 0])->all();

        foreach ($cycle->appraisals()->get() as $appraisal) {
            $label = $scale->labelFor($appraisal->effectiveRating());
            if ($label !== null) {
                $counts[$label]++;
            }
        }

        return $counts;
    }
}
