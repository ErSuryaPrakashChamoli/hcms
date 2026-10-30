<?php

namespace App\Domain\Performance\Services;

use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Audit\Services\AuditRecorder;
use App\Domain\Configuration\Services\EmployeeRuleContext;
use App\Domain\Configuration\Services\RuleEngine;
use App\Domain\Employment\Models\Employee;
use App\Domain\Entitlements\Enums\Capability;
use App\Domain\Entitlements\Services\Entitlements;
use App\Domain\Feedback\Services\FeedbackRequests;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Scopes\AccessScope;
use App\Domain\Identity\Services\AccessScopes;
use App\Domain\Letters\Support\PolicyLetters;
use App\Domain\Lifecycle\Services\Timeline;
use App\Domain\Performance\Events\PerformanceEvent;
use App\Domain\Performance\Models\Appraisal;
use App\Domain\Performance\Models\AppraisalReview;
use App\Domain\Performance\Models\Competency;
use App\Domain\Performance\Models\Goal;
use App\Domain\Performance\Models\PerformanceCycle;
use App\Domain\Performance\Models\RatingScale;
use App\Domain\Performance\Support\RatingRules;
use App\Domain\Platform\Services\SettingsRepository;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * The appraisal engine (§34–§35): launch a cycle (one appraisal per eligible employee, reviews per
 * stage), move through stages, collect reviews, score, calibrate, finalize, acknowledge. HROne setup parity HP-8d: the
 * goal sheet policy decides per employee what each review level rates and how (KPIs, achievement %, evidence), which
 * competencies count and their block weight; competencies are weighted by their weightage when they carry one; cycles
 * with an "Approver review" stage add a third review level whose submitted review drives the score. HP-8e: goal
 * templates are mapped when the cycle reaches goal setting (or at launch without that stage). HP-8g: closing a cycle
 * checks its rating distribution against the bell curve targets when the organisation asks for it (PerformanceDistribution).
 * HP-8i: the employee's performance policy (PerformancePolicyRules) may exclude late joiners at launch, add periodic
 * reviews that roll up into the computed rating, change how review ratings are calculated (sum of all goals, the goal /
 * competency weightage, ratings above the scale maximum), carry reviewers' recommendations, ask the manager a feedback
 * form with the manager review and draft a performance letter at publication. HP-8h: closed 360 rounds linked to the
 * cycle blend into the computed rating when their policy asks for it. Without those policies nothing changes.
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
            // HP-8i: the performance policy's eligibility criteria (joined too long after the cycle started).
            ->filter(fn (Employee $e) => app(PerformancePolicyRules::class)->eligible($e, $cycle))
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
        // SaaS.3: shadow entitlement observation (never blocks; see Entitlements).
        app(Entitlements::class)->observe(Capability::Performance, 'performance.cycle.launch');
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
            // HP-8i: periodic reviews of a performance policy whose review cycle is shorter than this cycle.
            app(PeriodicReviews::class)->plan($cycle);

            $cycle->update(['status' => 'active', 'current_stage' => $first, 'launched_at' => now(), 'performance_template_version_id' => $version->id]);
            $this->audit->record(AuditAction::Update, 'performance', $cycle, [['field' => 'status', 'before' => $before, 'after' => 'active']], null, actor: $actor, metadata: ['appraisals' => $employees->count(), 'template_version_id' => $version->id, 'template_checksum' => $version->checksum]);
            PerformanceEvent::dispatch('performance.cycle.published', null, $cycle, ['cycle' => $cycle->name, 'template_version' => $version->version], []);
            PerformanceEvent::dispatch('performance.cycle.launched', null, $cycle, ['cycle' => $cycle->name, 'stage' => config("peopleos.performance.stages.{$first}"), 'appraisals' => $employees->count()], $employees->pluck('id')->all());
            if (! $cycle->hasStage('goal_setting')) {
                // HP-8e: the goal sheet policy's system mapping (at goal setting when the cycle has that stage).
                app(GoalTemplates::class)->assignForCycle($cycle);
            }
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
        // HP-8d: the approver (third level) is the manager's own line manager.
        if ($cycle->hasStage('approver_review') && $appraisal->manager_id
            && ($approver = Employee::query()->withoutGlobalScope(AccessScope::class)->with('currentManager')->find($appraisal->manager_id)?->currentManager?->manager_id)
            && (int) $approver !== (int) $appraisal->employee_id) {
            $appraisal->reviews()->firstOrCreate(['type' => 'approver'], ['reviewer_id' => $approver, 'status' => 'pending']);
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
        if ($stage === 'goal_setting') {
            // HP-8e: goal sheets mapped by the system from goal templates (goal sheet policy, goal creation "system").
            app(GoalTemplates::class)->assignForCycle($cycle);
        }

        if ($stage === 'self_review' || ($stage === 'manager_review' && ! $cycle->hasStage('self_review'))) {
            // Goals become the fixed basis of the review once reviewing starts.
            $this->goals->lockForCycle($cycle->id, $cycle->appraisals()->pluck('employee_id')->all());
        }

        if (in_array($stage, ['self_review', 'manager_review', 'peer_review', 'approver_review'], true)) {
            $type = match ($stage) {
                'self_review' => 'self', 'manager_review' => 'manager', 'approver_review' => 'approver', default => 'peer'
            };
            $cycle->appraisals()->where('status', 'pending')->update(['status' => 'in_review']);

            AppraisalReview::query()->with('appraisal.employee')->whereIn('appraisal_id', $cycle->appraisals()->pluck('id'))->where('type', $type)->where('status', 'pending')->get()
                ->each(fn (AppraisalReview $review) => PerformanceEvent::dispatch('performance.review.assigned', $review->appraisal->employee, $review, ['cycle' => $cycle->name, 'type' => config("peopleos.performance.review_types.{$type}")], array_filter([$review->reviewer_id])));
        }
        if ($stage === 'manager_review') {
            // HP-8e: the feedback form of the goal templates, asked of the manager with the manager review.
            app(GoalTemplates::class)->raiseFeedback($cycle);
            // HP-8i: the feedback template the performance policy maps on the performance review, asked of the manager.
            $this->raiseReviewFeedback($cycle);
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
            // HP-8b: with manual publishing, ratings are published before the cycle (and its appraisals) lock.
            $unpublished = (clone $all)->whereNull('published_at')->count();
            if ($unpublished > 0) {
                throw new RuntimeException("{$unpublished} finalized rating(s) are not published yet. Publish the ratings, then close the cycle.");
            }
            // HP-8g: with the distribution check on "block", a cycle whose ratings differ from its bell curve targets by
            // more than the tolerance does not close (off, the default, checks nothing; warn closes and shows the gap).
            $distribution = app(PerformanceDistribution::class);
            $distribution->assertClosable($locked);

            $cycle->update(['status' => 'closed', 'closed_at' => now()]);
            // Phase 7: every appraisal (and its reviews and ratings) locks with the cycle.
            (clone $all)->whereNull('locked_at')->update(['locked_at' => now()]);
            Goal::query()->where('performance_cycle_id', $cycle->id)->where('status', 'active')->update(['status' => 'completed']);
            $metadata = ['appraisals_locked' => (clone $all)->count()];
            if ($distribution->mode() !== 'off' && ($gap = $distribution->warning($locked)) !== null) {
                $metadata['distribution_warning'] = $gap;
            }
            $this->audit->record(AuditAction::Update, 'performance', $cycle, [['field' => 'status', 'before' => 'active', 'after' => 'closed']], null, actor: $actor, metadata: $metadata);

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

    /** HP-8b: $anonymous null = the cycle's "Peer reviews anonymous by default" option (off unless set). */
    public function addPeerReview(Appraisal $appraisal, Employee $reviewer, string $type = 'peer', ?bool $anonymous = null): AppraisalReview
    {
        $appraisal->loadMissing(['cycle', 'employee']);
        $anonymous ??= (bool) $appraisal->cycle->setting('peer_anonymous', false);
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
            'self' => 'self_review', 'manager' => 'manager_review', 'approver' => 'approver_review', default => 'peer_review'
        };
        if ($cycle->hasStage($stage) && ! $cycle->isAtOrPast($stage)) {
            throw new RuntimeException('The '.config("peopleos.performance.stages.{$stage}").' stage has not opened yet.');
        }

        $scale = $cycle->ratingScale();
        // HP-8i: the performance policy may allow goal and competency ratings above the scale's maximum.
        $policyRules = app(PerformancePolicyRules::class);
        $validate = function (array $ratings, RatingScale $scale, ?float $max = null) {
            $max ??= $scale->max();
            foreach ($ratings as $value) {
                if ($value !== null && ($value < $scale->min() || $value > $max)) {
                    throw new RuntimeException("Ratings must be between {$scale->min()} and {$max}.");
                }
            }
        };
        $validate($goalRatings, $scale, $policyRules->maxRating($appraisal, $scale));
        // HP-8c: competencies are rated on the cycle's competency scale (the rating scale unless the cycle set another).
        $validate($competencyRatings, $cycle->competencyScale(), $policyRules->maxRating($appraisal, $cycle->competencyScale(), 'competency'));
        $validate([$overall], $scale);
        // HP-8c: a goal whose KPI rating formula fixes its rating (formula_rating_mode = lock) keeps that rating.
        app(KpiRatings::class)->assertLockedRatings($appraisal, $goalRatings, $scale);
        // HP-8d: the review configuration of the employee's goal sheet policy for this level — KPI ratings roll up into
        // their KRA, achievement percentages become ratings, evidence may be mandatory; only the employee's competencies.
        $sheet = app(GoalSheetRules::class);
        $config = $sheet->reviewConfig($appraisal, $review->type);
        [$goalRatings, $detailRows, $goalPcts] = $this->reviewInputs($appraisal, $config, $goalRatings, $text, $scale);
        $validate($goalRatings, $scale, $policyRules->maxRating($appraisal, $scale));
        // HP-8i: the recommendations a manager or approver review carries, as the performance policy enables them.
        $recommendations = $policyRules->cleanRecommendations($appraisal, (array) ($text['recommendations'] ?? []), $review->type);
        $evidence = array_values(array_filter((array) ($text['evidence'] ?? [])));
        if ($config['evidence'] === 'required' && $evidence === []) {
            throw new RuntimeException('Upload evidence with this review.');
        }
        $competencyIds = $sheet->includesCompetencies($appraisal) ? $sheet->competencyIds($appraisal) : [];
        if ($competencyIds !== null) {
            $competencyRatings = array_intersect_key($competencyRatings, array_flip($competencyIds));
        }

        return DB::transaction(function () use ($review, $goalRatings, $competencyRatings, $overall, $text, $appraisal, $actor, $cycle, $detailRows, $goalPcts, $evidence, $recommendations) {
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
                    $review->ratings()->create(['subject_type' => 'goal', 'subject_id' => (int) $id, 'rating' => $rating, 'comment' => $text['goal_comments'][$id] ?? null, 'achievement_pct' => $goalPcts[(int) $id] ?? null]);
                }
            }
            foreach ($detailRows as $row) {
                $review->ratings()->create($row);
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
                'evidence' => $evidence === [] ? null : $evidence,
            ]);

            $this->audit->record(AuditAction::Submitted, 'performance', $review, [], null, actor: $actor, metadata: ['appraisal_id' => $appraisal->id, 'type' => $review->type]);
            if ($recommendations !== null) {
                $appraisal->update(['recommendations' => $recommendations]);
            }
            $this->score($appraisal->refresh());
            PerformanceEvent::dispatch('performance.review.submitted', $appraisal->employee, $review, ['cycle' => $appraisal->cycle->name, 'type' => config("peopleos.performance.review_types.{$review->type}")], array_filter([$review->type === 'self' ? $appraisal->manager_id : ($review->type === 'manager' ? null : $appraisal->manager_id)]));

            return $review->refresh();
        });
    }

    /** HROne parity GO-1: the review chain levels HR may reopen (360 / peer reviews keep their own flow). */
    public const REOPENABLE = ['self', 'manager', 'approver'];

    /** HROne parity GO-1 (owner decision PER-1 / D-GO1): whether the organisation lets HR reopen submitted reviews. */
    public static function reopenEnabled(): bool
    {
        return (bool) app(SettingsRepository::class)->get('performance.review.hr_reopen', false);
    }

    /**
     * HROne parity GO-1 (HROne "Review Reinitiate"; owner decision PER-1 / D-GO1, off by default): HR sends a submitted
     * self, manager or approver review back to its reviewer to fill in again — only while `performance.review.hr_reopen`
     * is on, by performance.manage within the access scope, with a reason, before the appraisal is finalized; never one's
     * own review nor a review of one's own appraisal. The review returns to pending with its inputs cleared: the earlier
     * submission (ratings and their comments, overall rating, strengths, areas to develop, comments, evidence, submitted
     * at) stays in the audit trail (the review's own update plus a reopen event with each rating). The appraisal is scored
     * again without it and the reviewer is told. HR never writes the review: the reviewer submits it again.
     */
    public function reopenReview(AppraisalReview $review, string $reason, User $actor): AppraisalReview
    {
        if (! self::reopenEnabled()) {
            throw new RuntimeException('Reopening submitted reviews is turned off (Setup → Performance).');
        }
        if (! $actor->hasPermission('performance.manage')) {
            throw new RuntimeException('You cannot reopen reviews.');
        }
        $reason = trim($reason);
        if ($reason === '') {
            throw new RuntimeException('Say why the review is reopened.');
        }
        if (mb_strlen($reason) > 500) {
            throw new RuntimeException('Keep the reason under 500 characters.');
        }
        if (! in_array($review->type, self::REOPENABLE, true)) {
            throw new RuntimeException('Only self, manager and approver reviews can be reopened.');
        }
        $appraisal = Appraisal::query()->withoutGlobalScope(AccessScope::class)->with(['cycle', 'employee.person'])->findOrFail($review->appraisal_id);
        if (! app(AccessScopes::class)->allows($actor, $appraisal)) {
            throw new RuntimeException('This review is outside your access.');
        }
        $me = app(PerformanceRelationships::class)->forUser($actor);
        if ($me !== null && ((int) $me->id === (int) $review->reviewer_id || (int) $me->id === (int) $appraisal->employee_id)) {
            throw new RuntimeException('You cannot reopen your own review or a review of your own appraisal.');
        }

        return DB::transaction(function () use ($review, $reason, $actor, $appraisal) {
            // As submitReview: the appraisal row first, then the review, re-checked under the locks.
            $current = Appraisal::query()->withoutGlobalScope(AccessScope::class)->whereKey($appraisal->id)->lockForUpdate()->firstOrFail();
            if ($current->isFinal() || $current->isLocked()) {
                throw new RuntimeException('The appraisal is finalized: its reviews can no longer be reopened.');
            }
            $locked = AppraisalReview::query()->whereKey($review->id)->lockForUpdate()->firstOrFail();
            if (! $locked->isSubmitted()) {
                throw new RuntimeException('Only a submitted review can be reopened.');
            }
            $earlier = $locked->submitted_at?->toDateTimeString();
            // The earlier ratings (and their comments, as sensitive values) go into the audit trail before they are cleared.
            $changes = [];
            foreach ($locked->ratings()->orderBy('id')->get() as $rating) {
                $subject = $rating->subject_type.' #'.$rating->subject_id;
                $changes[] = ['field' => 'rating · '.$subject, 'before' => rtrim(rtrim((string) $rating->rating, '0'), '.').($rating->achievement_pct !== null ? ' ('.rtrim(rtrim((string) $rating->achievement_pct, '0'), '.').'%)' : ''), 'after' => null, 'sensitive' => false];
                if (filled($rating->comment)) {
                    $changes[] = ['field' => 'comment · '.$subject, 'before' => $rating->comment, 'after' => null, 'sensitive' => true];
                }
            }
            $locked->ratings()->delete();
            // Audited by the model itself: status, submitted at, overall rating, text (sensitive) and evidence, with the reason.
            $locked->withAuditReason('Reopened by HR: '.$reason)->update([
                'status' => 'pending', 'submitted_at' => null, 'overall_rating' => null,
                'strengths' => null, 'improvements' => null, 'comments' => null, 'evidence' => null,
            ]);
            $this->audit->record(AuditAction::StatusChange, 'performance', $locked, $changes, $reason, actor: $actor, metadata: [
                'event' => 'review_reopened', 'appraisal_id' => $current->id, 'type' => $locked->type, 'reviewer_id' => $locked->reviewer_id, 'earlier_submitted_at' => $earlier,
            ]);
            $this->score(Appraisal::query()->withoutGlobalScope(AccessScope::class)->findOrFail($current->id));
            PerformanceEvent::dispatch('performance.review.reopened', $appraisal->employee, $locked, [
                'cycle' => $appraisal->cycle?->name, 'type' => config("peopleos.performance.review_types.{$locked->type}", $locked->type), 'reason' => $reason,
            ], array_filter([(int) $locked->reviewer_id]));

            return $locked->refresh();
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
        // HP-8d: a submitted approver (third level) review drives the score; else the manager's, as before.
        $approver = $appraisal->review('approver');

        $source = $approver?->isSubmitted() ? $approver : ($manager?->isSubmitted() ? $manager : null);
        // HP-8i: the performance policy's review rating method (weighted average, as before, or the sum of all goals).
        $policyRules = app(PerformancePolicyRules::class);
        $goalScore = $source ? $this->weightedGoalScore($source, $goals, $scale, $policyRules->ratingMethod($appraisal)) : null;
        $sheet = app(GoalSheetRules::class);
        $competencyIds = $sheet->includesCompetencies($appraisal) ? $sheet->competencyIds($appraisal) : [];
        $competencyRatings = $source?->ratings->where('subject_type', 'competency')
            ->when($competencyIds !== null, fn (Collection $r) => $r->whereIn('subject_id', $competencyIds));
        $competencyScore = $source ? $this->averageScore($competencyRatings, $cycle->competencyScale(), $this->competencyWeights($cycle, $competencyRatings)) : null;
        $blockWeight = $sheet->competencyBlockWeight($appraisal);
        // HP-8i: the performance policy's competency : goal weightage, after the goal sheet policy's and before the cycle's.
        $policyWeights = $blockWeight === null ? $policyRules->weights($appraisal) : null;

        $computed = null;
        if ($goalScore !== null || $competencyScore !== null) {
            $wGoals = $goalScore === null ? 0 : ($blockWeight !== null ? 100 - $blockWeight : ($policyWeights['goals'] ?? $cycle->weight('goals')));
            $wComp = $competencyScore === null ? 0 : ($blockWeight ?? ($policyWeights['competencies'] ?? $cycle->weight('competencies')));
            $blend = ($wGoals + $wComp) > 0 ? (($goalScore ?? 0) * $wGoals + ($competencyScore ?? 0) * $wComp) / ($wGoals + $wComp) : null;
            $computed = $blend === null ? null : round($scale->min() + ($scale->max() - $scale->min()) * $blend / 100, 2);
        }
        if ($computed === null && ($source ?? $manager)?->overall_rating !== null) {
            $computed = (float) ($source ?? $manager)->overall_rating;
        }
        $reviewRating = $computed;
        // HP-8i: ratings above the scale's maximum (when the policy allows them) never take the computed rating past it.
        if ($computed !== null && $computed > $scale->max()) {
            $computed = $scale->max();
        }
        // HP-8i: the periodic reviews roll up into the rating when the performance policy says how.
        $computed = $policyRules->rollUp($appraisal, $computed, $scale);
        // HP-8h: a closed 360 round linked to this cycle blends in when its policy asks for it (off by default).
        $peerRating = $peers->isEmpty() ? null : round($peers->avg('overall_rating'), 2);
        if (($blend = app(FeedbackRoundResults::class)->appraisalBlend($appraisal, $scale)) !== null) {
            $peerRating = $blend['rating'];
            $computed = $computed === null ? null : round($computed * (1 - $blend['weight'] / 100) + $blend['rating'] * $blend['weight'] / 100, 2);
        }

        $appraisal->update([
            'goal_score' => $goalScore === null ? null : round($goalScore, 2),
            'competency_score' => $competencyScore === null ? null : round($competencyScore, 2),
            'self_rating' => $self?->overall_rating,
            'manager_rating' => $manager?->overall_rating ?? $reviewRating,
            'peer_rating' => $peerRating,
            'computed_rating' => $computed,
        ]);

        return $appraisal;
    }

    /**
     * Percent (0–100) of scale achieved, weighted by goal weights. HP-8i: with the performance policy's "sum of all goals",
     * each goal's share of the scale × its weightage ÷ 100, added up (at most 100 %; goals without weightage: as before).
     */
    private function weightedGoalScore(AppraisalReview $review, Collection $goals, $scale, string $method = 'weighted'): ?float
    {
        $ratings = $review->ratings->where('subject_type', 'goal')->keyBy('subject_id');
        $rated = $goals->filter(fn ($g) => $ratings->has($g->id));
        if ($rated->isEmpty()) {
            return null;
        }
        $totalWeight = $rated->sum('weight') ?: $rated->count();
        $range = $scale->max() - $scale->min();
        if ($method === 'sum' && $rated->sum('weight') > 0) {
            return $range > 0 ? min(100.0, $rated->sum(fn ($g) => (((float) $ratings[$g->id]->rating - $scale->min()) / $range * 100) * (float) $g->weight / 100)) : 0.0;
        }

        return $rated->sum(function ($g) use ($ratings, $scale, $range, $rated) {
            $w = $rated->sum('weight') > 0 ? $g->weight : 1;

            return $range > 0 ? (((float) $ratings[$g->id]->rating - $scale->min()) / $range * 100) * $w : 0;
        }) / $totalWeight;
    }

    /**
     * Percent (0–100) of the scale achieved. HP-8d (matrix defect 3): weighted by each competency's weightage when the
     * rated competencies carry one; a plain average otherwise (as before).
     *
     * @param  array<int, float>  $weights  competency id => weightage
     */
    private function averageScore(Collection $ratings, $scale, array $weights = []): ?float
    {
        if ($ratings->isEmpty()) {
            return null;
        }
        $range = $scale->max() - $scale->min();
        if ($range <= 0) {
            return null;
        }
        $pct = fn ($r) => ((float) $r->rating - $scale->min()) / $range * 100;
        $total = $ratings->sum(fn ($r) => max(0, (float) ($weights[(int) $r->subject_id] ?? 0)));

        return $total > 0
            ? $ratings->sum(fn ($r) => $pct($r) * max(0, (float) ($weights[(int) $r->subject_id] ?? 0))) / $total
            : $ratings->avg($pct);
    }

    /** @return array<int, float> competency id => weightage: as pinned with the cycle's template version, else the competency's own */
    private function competencyWeights(PerformanceCycle $cycle, ?Collection $ratings): array
    {
        return $this->competencyWeightsFor($cycle, collect($ratings ?? [])->pluck('subject_id')->all());
    }

    /**
     * HROne parity GO-1 (the goal sheet view lists the competencies with their weightage): competency id => weightage, as
     * pinned with the cycle's template version, else the competency's own — the weights scoring uses.
     *
     * @param  iterable<int|string>  $competencyIds
     * @return array<int, float>
     */
    public function competencyWeightsFor(PerformanceCycle $cycle, iterable $competencyIds): array
    {
        $ids = collect($competencyIds)->map(fn ($id) => (int) $id)->unique()->values();
        if ($ids->isEmpty()) {
            return [];
        }
        $pinned = collect($cycle->performance_template_version_id ? ($cycle->templateVersion?->competency_snapshot ?? []) : [])->keyBy('id');
        $live = Competency::query()->whereIn('id', $ids)->pluck('weight', 'id');

        return $ids->mapWithKeys(fn (int $id) => [$id => (float) ($pinned[$id]['weight'] ?? $live[$id] ?? 0)])->all();
    }

    /**
     * HP-8d: goal ratings from the review configuration — each KRA with key results takes the weighted rating of its
     * KPIs (review level "KPI"); an achievement % becomes a rating (input "percentage"). Returns the goal ratings, the KPI
     * rating rows and the percentages entered per goal.
     *
     * @param  array{level: string, input: string, evidence: string}  $config
     * @param  array<int|string, float|null>  $goalRatings
     * @return array{0: array<int|string, float|null>, 1: list<array<string, mixed>>, 2: array<int, float>}
     */
    private function reviewInputs(Appraisal $appraisal, array $config, array $goalRatings, array $text, RatingScale $scale): array
    {
        if ($config['level'] === 'goal' && $config['input'] === 'scale') {
            return [$goalRatings, [], []];
        }
        $sheet = app(GoalSheetRules::class);
        $goals = Goal::query()->with('keyResults')->where('employee_id', $appraisal->employee_id)->where('performance_cycle_id', $appraisal->performance_cycle_id)
            ->where('status', '!=', 'cancelled')->get()->keyBy('id');
        $number = fn ($value) => $value === null || $value === '' ? null : (float) $value;
        $assertPct = function (?float $pct) {
            if ($pct !== null && ($pct < 0 || $pct > 1000)) {
                throw new RuntimeException('An achievement percentage is between 0 and 1000.');
            }
        };
        $rows = [];
        $pcts = [];
        foreach ($goals as $goal) {
            if ($config['level'] === 'key_result' && $goal->keyResults->isNotEmpty()) {
                $parts = [];
                foreach ($goal->keyResults as $kr) {
                    $pct = $config['input'] === 'percentage' ? $number($text['key_result_pcts'][$kr->id] ?? null) : null;
                    $assertPct($pct);
                    $rating = $pct !== null ? $sheet->ratingForPct(null, $pct, $scale->min(), $scale->max()) : $number($text['key_result_ratings'][$kr->id] ?? null);
                    if ($rating === null) {
                        continue;
                    }
                    if ($rating < $scale->min() || $rating > $scale->max()) {
                        throw new RuntimeException("Ratings must be between {$scale->min()} and {$scale->max()}.");
                    }
                    $rows[] = ['subject_type' => 'key_result', 'subject_id' => $kr->id, 'rating' => $rating, 'achievement_pct' => $pct, 'comment' => $text['key_result_comments'][$kr->id] ?? null];
                    $parts[] = [$rating, max(0, (int) $kr->weight)];
                }
                if ($parts !== []) {
                    $weight = array_sum(array_column($parts, 1));
                    $goalRatings[$goal->id] = round($weight > 0 ? array_sum(array_map(fn ($p) => $p[0] * $p[1], $parts)) / $weight : array_sum(array_column($parts, 0)) / count($parts), 2);
                }

                continue;
            }
            if ($config['input'] === 'percentage' && ($pct = $number($text['goal_pcts'][$goal->id] ?? null)) !== null) {
                $assertPct($pct);
                $pcts[$goal->id] = $pct;
                $goalRatings[$goal->id] = $sheet->ratingForPct($goal, $pct, $scale->min(), $scale->max());
            }
        }

        return [$goalRatings, $rows, $pcts];
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
            // HP-8b: auto publishing (the default) publishes the rating with the finalization, as before; manual waits
            // for Publish ratings — until then the employee sees no rating, label, summary or reviews by others.
            $autoPublish = ! RatingRules::manualPublish();
            $appraisal->update([
                'final_rating' => $final,
                'final_label' => RatingRules::label($scale, $final),
                'manager_summary' => $summary ?? $appraisal->manager_summary,
                'promotion_recommended' => $promotion,
                'pip_recommended' => $pip,
                'status' => 'finalized',
                'finalized_by' => $actor?->id ?? auth()->id(),
                'finalized_at' => now(),
                'published_at' => $autoPublish ? now() : null,
                'published_by' => $autoPublish ? ($actor?->id ?? auth()->id()) : null,
            ]);

            $this->audit->record(AuditAction::Approved, 'performance', $appraisal, [['field' => 'final_rating', 'before' => null, 'after' => $final]], $summary, actor: $actor, metadata: ['cycle' => $cycle->code, 'promotion' => $promotion, 'pip' => $pip, 'published' => $autoPublish]);
            if ($autoPublish) {
                $this->announce($appraisal, $cycle);
            }
            if ($promotion) {
                PerformanceEvent::dispatch('performance.promotion.recommended', $appraisal->employee, $appraisal, ['cycle' => $cycle->name, 'rating' => $final], array_filter([$appraisal->manager_id]));
            }
            // HP-8i: training needs recommended in the reviews become development needs (Learning reads them).
            app(PerformanceRecommendations::class)->recordTrainingNeeds($appraisal, $actor);

            return $appraisal->refresh();
        });
    }

    /**
     * HROne setup parity HP-8b: publish the finalized ratings of a cycle (all, or the given appraisals) to the
     * employees — what auto publishing does at finalization. Each appraisal is published once (locked row); the employee
     * is told and the outcome goes on their timeline. Needs performance.calibrate. Returns how many were published.
     *
     * @param  list<int>|null  $appraisalIds
     */
    public function publish(PerformanceCycle $cycle, ?array $appraisalIds = null, ?User $actor = null): int
    {
        $actor ??= auth()->user();
        if ($actor !== null && ! $actor->hasPermission('performance.calibrate')) {
            throw new RuntimeException('You cannot publish ratings.');
        }
        $published = 0;
        $this->audit->operation('performance', 'Publish ratings', function () use ($cycle, $appraisalIds, $actor, &$published) {
            Appraisal::query()->withoutGlobalScope(AccessScope::class)->where('performance_cycle_id', $cycle->id)
                ->whereIn('status', ['finalized', 'acknowledged'])->whereNull('published_at')
                ->when($appraisalIds !== null, fn ($q) => $q->whereIn('id', $appraisalIds))
                ->orderBy('id')->pluck('id')
                ->each(function (int $id) use ($cycle, $actor, &$published) {
                    DB::transaction(function () use ($id, $cycle, $actor, &$published) {
                        $appraisal = Appraisal::query()->withoutGlobalScope(AccessScope::class)->with('employee')->whereKey($id)->lockForUpdate()->first();
                        if ($appraisal === null || $appraisal->isPublished() || ! $appraisal->isFinal()) {
                            return;
                        }
                        $appraisal->update(['published_at' => now(), 'published_by' => $actor?->id]);
                        $this->announce($appraisal, $cycle);
                        $published++;
                    });
                });

            return $published;
        });

        return $published;
    }

    /** The employee learns the outcome: their timeline and the "appraisal is final" event (notification, rules, webhooks). */
    private function announce(Appraisal $appraisal, PerformanceCycle $cycle): void
    {
        $appraisal->loadMissing('employee');
        $this->timeline->record($appraisal->employee, 'performance', "{$cycle->name}: {$appraisal->final_label}", $cycle->period_end, $appraisal->manager_summary, $appraisal, ['rating' => (float) $appraisal->final_rating]);
        PerformanceEvent::dispatch('performance.appraisal.finalized', $appraisal->employee, $appraisal, ['cycle' => $cycle->name, 'rating' => (float) $appraisal->final_rating, 'label' => $appraisal->final_label], [$appraisal->employee_id]);
        // HP-8i: the performance letter of the employee's performance policy, drafted for review (never blocks).
        PolicyLetters::draft(app(PerformancePolicyRules::class)->letterTemplate($appraisal->employee), $appraisal->employee, auth()->user(), $appraisal);
    }

    /**
     * HP-8i: the feedback template the employee's performance policy maps on the performance review, asked of the manager
     * when the cycle reaches the manager review (one per manager review; a My tasks item). A failure never stops the cycle.
     */
    private function raiseReviewFeedback(PerformanceCycle $cycle): void
    {
        $rules = app(PerformancePolicyRules::class);
        AppraisalReview::query()->with(['appraisal.employee.person', 'reviewer'])->whereIn('appraisal_id', $cycle->appraisals()->pluck('id'))->where('type', 'manager')->get()
            ->each(function (AppraisalReview $review) use ($rules, $cycle) {
                try {
                    $employee = $review->appraisal?->employee;
                    $templateId = $rules->reviewFeedbackTemplate($employee);
                    if ($templateId === null || $employee === null || $review->reviewer?->user_id === null) {
                        return;
                    }
                    $due = collect($cycle->stages ?? [])->firstWhere('key', 'manager_review')['ends_on'] ?? null;
                    app(FeedbackRequests::class)->raise($templateId, 'performance_review', $review, $employee, 'manager', (int) $review->reviewer->user_id, null,
                        filled($due) ? Carbon::parse($due) : null, 'Performance review feedback for '.($employee->person?->full_name ?? $employee->employee_code));
                } catch (\Throwable $e) {
                    report($e);
                }
            });
    }

    public function acknowledge(Appraisal $appraisal, ?string $comment = null): Appraisal
    {
        if ($appraisal->status !== 'finalized') {
            throw new RuntimeException('Only a finalized appraisal can be acknowledged.');
        }
        if (! $appraisal->isPublished()) {
            throw new RuntimeException('The rating has not been published yet.');
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
            $label = RatingRules::label($scale, $appraisal->effectiveRating());
            if ($label !== null) {
                $counts[$label]++;
            }
        }

        return $counts;
    }
}
