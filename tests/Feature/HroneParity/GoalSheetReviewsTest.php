<?php

use App\Domain\Configuration\Models\Policy;
use App\Domain\Configuration\Models\PolicyAssignmentRule;
use App\Domain\Configuration\Services\Policies;
use App\Domain\Performance\Models\Appraisal;
use App\Domain\Performance\Models\AppraisalRating;
use App\Domain\Performance\Models\Competency;
use App\Domain\Performance\Models\Goal;
use App\Domain\Performance\Models\PerformanceCycle;
use App\Domain\Performance\Services\Appraisals;
use App\Domain\Performance\Services\Goals;
use App\Domain\Performance\Services\GoalSheetRules;
use App\Domain\Performance\Support\AppraisalVisibility;
use App\Domain\Platform\Services\SettingsRepository;
use App\Filament\Support\PerformanceActions;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;

require_once __DIR__.'/../Workflow/WorkflowTestHelpers.php';
require_once __DIR__.'/../Performance/PerformanceTestHelpers.php';

/* HROne setup parity HP-8d — goal sheet policy in reviews: what each level rates (KRA or KPI) and how (rating or
 * achievement %), evidence, which competencies count and their weightage (matrix defect 3), the scorecard parts hidden
 * from the employee, copying the previous level's inputs, and the approver as a third review level. */

beforeEach(function () {
    $this->travelTo('2026-09-21 09:00:00');
    $this->tenant = provisionTenant();
    actAsTenant($this->tenant);
    $this->hr = tenantUser($this->tenant, ['*']);
    $this->actingAs($this->hr);
    $this->director = activeEmployee(null, ['performance.team', 'performance.review', 'performance.goals']);
    $this->manager = activeEmployee($this->director, ['performance.team', 'performance.review', 'performance.goals']);
    $this->employee = activeEmployee($this->manager);
    $this->appraisals = app(Appraisals::class);
    [$this->c1, $this->c2] = Competency::query()->orderBy('id')->limit(2)->get()->all();
});

function gsrPolicy(array $settings): void
{
    $policy = Policy::create(['type' => 'goal_sheet', 'name' => 'Reviews', 'code' => 'GSR']);
    app(Policies::class)->draft($policy, $settings);
    app(Policies::class)->publish($policy, '2020-01-01');
    PolicyAssignmentRule::create(['policy_type' => 'goal_sheet', 'policy_id' => $policy->id, 'name' => 'All', 'conditions' => []]);
}

/** A launched cycle on the given stages (all open), rating the two test competencies, with one goal for the employee. */
function gsrCycle(array $stages, array $keyResults = []): array
{
    $test = test();
    $cycle = app(Appraisals::class)->launch(draftCycle([
        'competency_ids' => [$test->c1->id, $test->c2->id],
        'stages' => array_map(fn (string $key) => ['key' => $key, 'name' => config("peopleos.performance.stages.{$key}"), 'starts_on' => '2026-09-01', 'ends_on' => '2026-12-31'], $stages),
    ]));
    $goal = app(Goals::class)->create(['employee_id' => $test->employee->id, 'performance_cycle_id' => $cycle->id, 'title' => 'Revenue', 'weight' => 100, 'target_value' => 100], $keyResults);

    return [$cycle, $goal, Appraisal::query()->where('performance_cycle_id', $cycle->id)->where('employee_id', $test->employee->id)->sole()];
}

it('rates KRAs on the scale with every competency, as before, without a goal sheet policy', function () {
    [$cycle, $goal, $appraisal] = gsrCycle(['manager_review', 'final']);
    $config = app(GoalSheetRules::class)->reviewConfig($appraisal, 'manager');
    $this->appraisals->submitReview($appraisal->review('manager'), [$goal->id => 4], [$this->c1->id => 5, $this->c2->id => 1], 4);

    expect($config)->toBe(['level' => 'goal', 'input' => 'scale', 'evidence' => 'off'])
        ->and((float) $appraisal->refresh()->competency_score)->toBe(50.0)
        ->and((float) $appraisal->goal_score)->toBe(75.0)
        ->and(AppraisalRating::query()->where('subject_type', 'goal')->value('achievement_pct'))->toBeNull()
        ->and(PerformanceActions::previousInputs($appraisal, 'manager'))->toBe([]);
});

it('weights competencies by their weightage when they carry one (matrix defect 3)', function () {
    $this->c1->update(['weight' => 30]);
    $this->c2->update(['weight' => 10]);
    [$cycle, $goal, $appraisal] = gsrCycle(['manager_review', 'final']);
    $this->appraisals->submitReview($appraisal->review('manager'), [$goal->id => 3], [$this->c1->id => 5, $this->c2->id => 1], 3);

    // (100 × 30 + 0 × 10) ÷ 40 = 75, where a plain average gave 50; the weights pinned at launch count.
    expect((float) $appraisal->refresh()->competency_score)->toBe(75.0);
    $this->c1->update(['weight' => 0]);
    $this->appraisals->score($appraisal->refresh());
    expect((float) $appraisal->refresh()->competency_score)->toBe(75.0);
});

it('takes an achievement percentage instead of a rating, and asks for evidence when the policy says so', function () {
    gsrPolicy(['manager_review_input' => 'percentage', 'manager_evidence' => 'required']);
    [$cycle, $goal, $appraisal] = gsrCycle(['manager_review', 'final']);
    $review = $appraisal->review('manager');

    expect(fn () => $this->appraisals->submitReview($review, [], [$this->c1->id => 3, $this->c2->id => 3], 4, ['goal_pcts' => [$goal->id => 80]]))->toThrow(RuntimeException::class, 'Upload evidence')
        ->and(fn () => $this->appraisals->submitReview($review, [], [], 4, ['goal_pcts' => [$goal->id => -5], 'evidence' => ['performance-evidence/x.pdf']]))->toThrow(RuntimeException::class, 'between 0 and 1000');

    $this->appraisals->submitReview($review, [], [$this->c1->id => 3, $this->c2->id => 3], 4, ['goal_pcts' => [$goal->id => 80], 'evidence' => ['performance-evidence/sales.pdf']]);
    $rating = AppraisalRating::query()->where('subject_type', 'goal')->sole();
    expect((float) $rating->rating)->toBe(4.2)->and((float) $rating->achievement_pct)->toBe(80.0)
        ->and($review->refresh()->evidence)->toBe(['performance-evidence/sales.pdf'])
        ->and((float) $appraisal->refresh()->goal_score)->toBe(80.0);

    // The form asks for a percentage and the upload.
    $this->actingAs($this->manager->user);
    $form = PerformanceActions::reviewForm($appraisal->refresh(), true, 'manager');
    $fields = collect($form)->flatMap(fn (Section $s) => $s->getDefaultChildComponents())->map(fn ($c) => $c->getName())->filter()->values()->all();
    expect($fields)->toContain("goal_pcts.{$goal->id}")->toContain('evidence');
});

it('rates each KPI of a KRA when the policy says so, the KRA taking their weighted rating', function () {
    gsrPolicy(['self_review_level' => 'key_result']);
    [$cycle, $goal, $appraisal] = gsrCycle(['self_review', 'final'], [['title' => 'New logos', 'weight' => 75], ['title' => 'Renewals', 'weight' => 25]]);
    [$k1, $k2] = $goal->keyResults()->get()->all();

    $this->appraisals->submitReview($appraisal->review('self'), [], [], 4, ['key_result_ratings' => [$k1->id => 4, $k2->id => 2]]);
    expect(AppraisalRating::query()->where('subject_type', 'goal')->value('rating'))->toEqual('3.50')
        ->and(AppraisalRating::query()->where('subject_type', 'key_result')->orderBy('subject_id')->pluck('rating', 'subject_id')->map(fn ($r) => (float) $r)->all())->toBe([$k1->id => 4.0, $k2->id => 2.0]);
});

it('counts only the employee\'s competencies, their block weight, or none', function () {
    gsrPolicy(['competency_codes' => [$this->c1->code], 'competency_block_weight' => 50]);
    [$cycle, $goal, $appraisal] = gsrCycle(['manager_review', 'final']);
    $this->appraisals->submitReview($appraisal->review('manager'), [$goal->id => 5], [$this->c1->id => 1, $this->c2->id => 5], 3);

    // Goals 100 %, competency 0 % (only c1 counts), blended 50 / 50 = 3 on a 1–5 scale.
    expect((float) $appraisal->refresh()->competency_score)->toBe(0.0)->and((float) $appraisal->computed_rating)->toBe(3.0)
        ->and(AppraisalRating::query()->where('subject_type', 'competency')->pluck('subject_id')->all())->toBe([$this->c1->id])
        ->and(app(GoalSheetRules::class)->competenciesFor($appraisal)->pluck('id')->all())->toBe([$this->c1->id]);
});

it('leaves competencies out of the rating when the policy does not include them', function () {
    gsrPolicy(['include_competencies' => 0]);
    [$cycle, $goal, $appraisal] = gsrCycle(['manager_review', 'final']);
    $this->appraisals->submitReview($appraisal->review('manager'), [$goal->id => 4], [$this->c1->id => 1, $this->c2->id => 1], 4);

    expect($appraisal->refresh()->competency_score)->toBeNull()->and((float) $appraisal->computed_rating)->toBe(4.0)
        ->and(app(GoalSheetRules::class)->competenciesFor($appraisal))->toBeEmpty();
});

it('hides the scorecard parts the policy names from the employee, never from HR or the manager', function () {
    gsrPolicy(['scorecard_hidden' => ['goal_score', 'reviewer_ratings']]);
    [$cycle, $goal, $appraisal] = gsrCycle(['manager_review', 'final']);
    Appraisal::query()->whereKey($appraisal->id)->update(['status' => 'finalized', 'published_at' => now(), 'final_rating' => 4]);
    $appraisal->refresh();

    expect(AppraisalVisibility::partVisible($appraisal, $this->employee->user, 'goal_score'))->toBeFalse()
        ->and(AppraisalVisibility::partVisible($appraisal, $this->employee->user, 'reviewer_ratings'))->toBeFalse()
        ->and(AppraisalVisibility::partVisible($appraisal, $this->employee->user, 'competency_score'))->toBeTrue()
        ->and(AppraisalVisibility::ratingsVisible($appraisal, $this->employee->user))->toBeTrue()
        ->and(AppraisalVisibility::partVisible($appraisal, $this->hr, 'goal_score'))->toBeTrue()
        ->and(AppraisalVisibility::partVisible($appraisal, $this->manager->user, 'reviewer_ratings'))->toBeTrue();
});

it('copies the previous level\'s inputs to the next level as the organisation setting or the policy says', function () {
    [$cycle, $goal, $appraisal] = gsrCycle(['self_review', 'manager_review', 'final']);
    $this->appraisals->submitReview($appraisal->review('self'), [$goal->id => 4], [$this->c1->id => 3, $this->c2->id => 2], 4, ['goal_comments' => [$goal->id => 'Beat the target']]);
    $cycle = $this->appraisals->advance($cycle);
    expect(PerformanceActions::previousInputs($appraisal->refresh(), 'manager'))->toBe([]);

    app(SettingsRepository::class)->set('performance.review.copy_previous_level', true, 'Start from the self review');
    $copied = PerformanceActions::previousInputs($appraisal->refresh(), 'manager');
    expect($copied['goals'])->toBe([$goal->id => '4'])->and($copied['goal_comments'])->toBe([$goal->id => 'Beat the target'])
        ->and($copied['competencies'])->toBe([$this->c1->id => '3', $this->c2->id => '2']);

    $field = collect(PerformanceActions::reviewForm($appraisal->refresh(), true, 'manager'))->flatMap(fn (Section $s) => $s->getDefaultChildComponents())->first(fn ($c) => $c->getName() === "goal_comments.{$goal->id}");
    expect($field)->toBeInstanceOf(TextInput::class)->and($field->getDefaultState())->toBe('Beat the target');

    gsrPolicy(['copy_goal_inputs' => 0, 'copy_competency_inputs' => 1]);
    $copied = PerformanceActions::previousInputs($appraisal->refresh(), 'manager');
    expect($copied)->not->toHaveKey('goals')->and($copied['competencies'])->toBe([$this->c1->id => '3', $this->c2->id => '2']);
});

it('adds the approver as a third review level whose review drives the score', function () {
    [$cycle, $goal, $appraisal] = gsrCycle(['manager_review', 'approver_review', 'final']);
    $approverReview = $appraisal->review('approver');
    $outsider = activeEmployee(null, ['performance.team', 'performance.review']);
    expect($approverReview?->reviewer_id)->toBe($this->director->id)
        ->and(AppraisalVisibility::isPrivileged($appraisal, $this->director->user))->toBeTrue()
        ->and($this->director->user->can('view', $appraisal))->toBeTrue()
        ->and($outsider->user->can('view', $appraisal))->toBeFalse()
        ->and(fn () => $this->appraisals->submitReview($approverReview, [$goal->id => 5], [], 5))->toThrow(RuntimeException::class, 'has not opened yet');

    $this->appraisals->submitReview($appraisal->review('manager'), [$goal->id => 3], [$this->c1->id => 3, $this->c2->id => 3], 3);
    expect((float) $appraisal->refresh()->computed_rating)->toBe(3.0);
    $this->appraisals->advance($cycle);
    $this->appraisals->submitReview($approverReview->refresh(), [$goal->id => 5], [$this->c1->id => 5, $this->c2->id => 5], 5);
    expect((float) $appraisal->refresh()->computed_rating)->toBe(5.0)->and((float) $appraisal->manager_rating)->toBe(3.0)
        ->and(config('peopleos.performance.review_types.approver'))->not->toBeNull()
        ->and(PerformanceCycle::defaultStages(now())[2]['key'])->toBe('manager_review'); // default cycles stay without it
});
