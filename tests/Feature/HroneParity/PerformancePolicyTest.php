<?php

use App\Domain\Compensation\Models\CompensationChange;
use App\Domain\Compensation\Services\CompensationCycles;
use App\Domain\Configuration\Exceptions\ConfigurationException;
use App\Domain\Configuration\Models\Policy;
use App\Domain\Configuration\Models\PolicyAssignmentRule;
use App\Domain\Configuration\Services\Policies;
use App\Domain\Employment\Models\Employee;
use App\Domain\Feedback\Models\FeedbackRequest;
use App\Domain\Feedback\Models\FeedbackTemplate;
use App\Domain\Identity\Scopes\AccessScope;
use App\Domain\Letters\Models\Letter;
use App\Domain\Letters\Models\LetterTemplate;
use App\Domain\Performance\Contracts\PerformanceOutcomesReader;
use App\Domain\Performance\Models\Appraisal;
use App\Domain\Performance\Models\AppraisalReview;
use App\Domain\Performance\Models\Competency;
use App\Domain\Performance\Models\DevelopmentNeed;
use App\Domain\Performance\Models\PerformancePeriodReview;
use App\Domain\Performance\Services\Appraisals;
use App\Domain\Performance\Services\Goals;
use App\Domain\Performance\Services\PerformancePolicyRules;
use App\Domain\Performance\Services\PerformanceRecommendations;
use App\Domain\Performance\Services\PeriodicReviews;
use App\Domain\Performance\Support\AppraisalVisibility;
use App\Filament\Resources\Appraisals\AppraisalResource;
use App\Filament\Resources\Appraisals\Pages\ViewAppraisal;
use App\Filament\Resources\Appraisals\RelationManagers\PeriodReviewsRelationManager;
use App\Filament\Resources\PerformanceCycles\Pages\EditPerformanceCycle;
use App\Filament\Support\PerformanceActions;
use App\Filament\Support\PolicySettingsSchema;

require_once __DIR__.'/../Workflow/WorkflowTestHelpers.php';
require_once __DIR__.'/../Performance/PerformanceTestHelpers.php';
require_once __DIR__.'/../Payroll/PayrollTestHelpers.php';

/* HROne setup parity HP-8i (SV-11, PF-6 … PF-11) — performance policy: eligibility by joining date, periodic reviews
 * (windows, automatic or manual opening) rolled up into the appraisal rating only when the policy says how, the review
 * rating method and weightage, ratings above the scale, per-level visibility of ratings, final rating and final comments,
 * recommendations offered to compensation and learning, the goal window, the review feedback form and the performance
 * letter. Without a policy, appraisals behave exactly as before. */

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
    $this->c1 = Competency::query()->orderBy('id')->first();
});

/** The one performance policy, for everyone (earlier ones stop applying). */
function ppPolicy(array $settings): Policy
{
    PolicyAssignmentRule::query()->where('policy_type', 'performance')->delete();
    $policy = Policy::create(['type' => 'performance', 'name' => 'Performance', 'code' => 'PERF_'.uniqid()]);
    app(Policies::class)->draft($policy, $settings);
    app(Policies::class)->publish($policy, '2020-01-01');
    PolicyAssignmentRule::create(['policy_type' => 'performance', 'policy_id' => $policy->id, 'name' => 'All', 'conditions' => []]);
    app(PerformancePolicyRules::class)->forget();

    return $policy;
}

/** A launched annual cycle (Apr–Mar) on the given stages (all open now), with goals for the employee. */
function ppCycle(array $stages = ['manager_review', 'final'], array $competencies = [], array $goals = [['Revenue', 100]], array $overrides = []): array
{
    $test = test();
    $cycle = $test->appraisals->launch(draftCycle($overrides + [
        'code' => 'C'.strtoupper(substr(uniqid(), -8)),
        'competency_ids' => $competencies,
        'stages' => array_map(fn (string $key) => ['key' => $key, 'name' => config("peopleos.performance.stages.{$key}"), 'starts_on' => '2026-09-01', 'ends_on' => '2026-12-31'], $stages),
    ]));
    $made = array_map(fn (array $g) => app(Goals::class)->create(['employee_id' => $test->employee->id, 'performance_cycle_id' => $cycle->id, 'title' => $g[0], 'weight' => $g[1], 'target_value' => 100]), $goals);

    return [$cycle, $made, Appraisal::query()->where('performance_cycle_id', $cycle->id)->where('employee_id', $test->employee->id)->sole()];
}

/** Move the cycle on to the stage. */
function ppAdvanceTo($cycle, string $stage): void
{
    $cycle = $cycle->refresh();
    while ($cycle->current_stage !== $stage) {
        $cycle = test()->appraisals->advance($cycle, test()->hr);
    }
}

it('keeps today\'s appraisals without a performance policy, or with blank answers', function () {
    foreach ([null, []] as $settings) {
        if ($settings !== null) {
            ppPolicy($settings);
        }
        [$cycle, [$goal], $appraisal] = ppCycle(['self_review', 'manager_review', 'final'], [$this->c1->id]);
        $rules = app(PerformancePolicyRules::class);
        $this->appraisals->submitReview($appraisal->review('self'), [$goal->id => 3], [$this->c1->id => 3], 3, [], $this->employee->user);
        ppAdvanceTo($cycle, 'manager_review');
        $this->appraisals->submitReview($appraisal->review('manager'), [$goal->id => 5], [$this->c1->id => 1], 4, ['recommendations' => ['increment' => 9]], $this->manager->user);
        $appraisal->refresh();

        // 100 % on goals × 70 + 0 % on competencies × 30 → 70 % of the 1–5 scale.
        expect((float) $appraisal->computed_rating)->toBe(3.8)->and($appraisal->recommendations)->toBeNull()
            ->and(PerformancePeriodReview::query()->count())->toBe(0)
            ->and($rules->visibility($appraisal, 'ratings'))->toBe('all')->and($rules->ratingMethod($appraisal))->toBe('weighted')
            ->and($rules->weights($appraisal))->toBeNull()->and($rules->maxRating($appraisal, $cycle->ratingScale()))->toBe(5.0)
            ->and(fn () => $this->appraisals->submitReview($appraisal->review('manager'), [$goal->id => 6], [], 4))->toThrow(RuntimeException::class);

        $manager = $appraisal->review('manager');
        expect(AppraisalVisibility::reviewVisible($appraisal, $manager, $this->employee->user))->toBeFalse() // not published yet
            ->and(AppraisalVisibility::reviewVisible($appraisal, $appraisal->review('self'), $this->manager->user))->toBeTrue();
        ppAdvanceTo($cycle, 'final');
        $this->appraisals->finalize($appraisal->refresh(), $this->hr);
        expect(AppraisalVisibility::reviewVisible($appraisal->refresh(), $manager, $this->employee->user))->toBeTrue()
            ->and(AppraisalVisibility::ratingsVisible($appraisal, $this->employee->user))->toBeTrue()
            ->and(AppraisalVisibility::finalCommentsVisible($appraisal, $this->employee->user))->toBeTrue();
    }
});

it('validates the performance policy and gives it HROne\'s steps', function () {
    $rules = app(PerformancePolicyRules::class);

    expect(fn () => $rules->validatePolicySettings(['goal_cycle' => 'half_yearly', 'review_cycle' => 'yearly']))->toThrow(ConfigurationException::class, 'cannot be longer than the goal cycle')
        ->and(fn () => $rules->validatePolicySettings(['goal_weight' => 60, 'competency_weight' => 30]))->toThrow(ConfigurationException::class, 'add up to 100')
        ->and(fn () => $rules->validatePolicySettings(['allow_above_max' => '1']))->toThrow(ConfigurationException::class, 'highest rating')
        ->and(fn () => $rules->validatePolicySettings(['review_cycle' => 'quarterly', 'appraisal_basis' => 'custom', 'custom_weights' => ['20', '20', '30']]))->toThrow(ConfigurationException::class, 'add up to 100')
        ->and(fn () => $rules->validatePolicySettings(['appraisal_basis' => 'custom', 'custom_weights' => ['50', '50']]))->toThrow(ConfigurationException::class, 'shorter than a year')
        ->and(fn () => $rules->validatePolicySettings(['eligibility' => '1']))->toThrow(ConfigurationException::class, 'number of days')
        ->and(fn () => $rules->validatePolicySettings(['performance_letter' => '1']))->toThrow(ConfigurationException::class, 'letter template')
        ->and(fn () => $rules->validatePolicySettings(['appraisal_basis' => 'average', 'include_appraisal' => '1']))->toThrow(ConfigurationException::class, 'appraisal weightage');
    $rules->validatePolicySettings(['goal_cycle' => 'half_yearly', 'review_cycle' => 'quarterly', 'goal_weight' => 70, 'competency_weight' => 30, 'appraisal_basis' => 'custom', 'custom_weights' => ['10', '20', '30', '40']]);

    expect(array_column(PolicySettingsSchema::steps('performance'), 'label'))->toBe(['Goal', 'Goal review', 'Visibility', 'Advance', 'Competency review', 'Appraisal']);
});

it('leaves out employees who joined too long after the cycle started, when the policy enables eligibility', function () {
    Employee::query()->whereKey($this->employee->id)->update(['joining_date' => '2026-06-01']);
    $late = Employee::query()->find($this->employee->id);
    expect($this->appraisals->eligible(draftCycle())->pluck('id'))->toContain($late->id);

    ppPolicy(['eligibility' => '1', 'joiner_cutoff_days' => 30]);
    expect($this->appraisals->eligible(draftCycle(['code' => 'FY26B']))->pluck('id'))->not->toContain($late->id)->toContain($this->manager->id);
});

it('plans periodic reviews, opens them when their window starts, and rolls them up into the rating only as the policy says', function () {
    ppPolicy(['review_cycle' => 'quarterly', 'review_window_days' => 15, 'appraisal_basis' => 'average']);
    [$cycle, [$goal], $appraisal] = ppCycle(['manager_review', 'final']);
    $reviews = PerformancePeriodReview::query()->where('appraisal_id', $appraisal->id)->orderBy('sequence')->get();
    $service = app(PeriodicReviews::class);

    expect($reviews->map(fn ($r) => [$r->period_start->toDateString(), $r->period_end->toDateString(), $r->window_opens_on->toDateString(), $r->window_closes_on->toDateString()])->all())->toBe([
        ['2026-04-01', '2026-06-30', '2026-06-30', '2026-07-14'], ['2026-07-01', '2026-09-30', '2026-09-30', '2026-10-14'],
        ['2026-10-01', '2026-12-31', '2026-12-31', '2027-01-14'], ['2027-01-01', '2027-03-31', '2027-03-31', '2027-04-14'],
    ])->and($reviews->pluck('reviewer_id')->unique()->all())->toBe([$this->manager->id])
        // Every appraisal of the cycle (the director, the manager, the employee) has its quarters; Q1's window has passed.
        ->and($service->runSchedule())->toBe(['opened' => 0, 'missed' => 3]);

    $this->travelTo('2026-10-01 09:00:00');
    expect($service->runSchedule())->toBe(['opened' => 3, 'missed' => 0]);
    $q2 = $reviews[1]->refresh();
    expect($q2->status)->toBe('open')
        ->and($this->manager->user->notifications()->get()->pluck('data.title')->filter(fn ($t) => str_contains((string) $t, 'Periodic review to complete'))->count())->toBe(1)
        ->and(fn () => $service->submit($q2, 4, 'Good quarter', $this->employee->user))->toThrow(RuntimeException::class, 'not yours to rate')
        ->and(fn () => $service->submit($q2, 6, null, $this->manager->user))->toThrow(RuntimeException::class, 'between 1 and 5');

    $service->submit($q2, 4, 'Good quarter', $this->manager->user);
    // No year-end review yet: the average of the periodic reviews is the rating.
    expect((float) $appraisal->refresh()->computed_rating)->toBe(4.0)
        ->and(fn () => $service->submit($q2->refresh(), 3, null, $this->manager->user))->toThrow(RuntimeException::class, 'already submitted');

    // The year-end review (2.0) does not count unless the policy includes it.
    $this->appraisals->submitReview($appraisal->review('manager'), [$goal->id => 2], [], 2, [], $this->manager->user);
    expect((float) $appraisal->refresh()->computed_rating)->toBe(4.0)->and((float) $appraisal->manager_rating)->toBe(2.0);

    ppPolicy(['review_cycle' => 'quarterly', 'appraisal_basis' => 'average', 'include_appraisal' => '1', 'appraisal_weight' => 50]);
    $this->appraisals->score($appraisal->refresh());
    expect((float) $appraisal->refresh()->computed_rating)->toBe(3.0);

    // Last review / custom weightage per review.
    PerformancePeriodReview::query()->whereKey($reviews[2]->id)->update(['status' => 'submitted', 'rating' => 2]);
    ppPolicy(['review_cycle' => 'quarterly', 'appraisal_basis' => 'last']);
    $this->appraisals->score($appraisal->refresh());
    expect((float) $appraisal->refresh()->computed_rating)->toBe(2.0);
    ppPolicy(['review_cycle' => 'quarterly', 'appraisal_basis' => 'custom', 'custom_weights' => ['10', '20', '60', '10']]);
    $this->appraisals->score($appraisal->refresh());
    // (4 × 20 + 2 × 60) ÷ 80 = 2.5
    expect((float) $appraisal->refresh()->computed_rating)->toBe(2.5);
    ppPolicy(['review_cycle' => 'quarterly']);
    $this->appraisals->score($appraisal->refresh());
    expect((float) $appraisal->refresh()->computed_rating)->toBe(2.0); // no basis: the year-end review alone
});

it('opens periodic reviews by hand when the policy asks for manual initiation', function () {
    ppPolicy(['review_cycle' => 'quarterly', 'review_manual_start' => '1', 'review_start_roles' => ['manager']]);
    [$cycle, , $appraisal] = ppCycle();
    $this->travelTo('2026-10-01 09:00:00');
    $service = app(PeriodicReviews::class);

    expect($service->runSchedule()['opened'])->toBe(0)
        ->and(fn () => $service->initiate($cycle, 2, null, $this->employee->user))->toThrow(RuntimeException::class, 'that you may open');
    $this->travelTo('2026-10-20 09:00:00'); // after the planned window: it gets a new one from today
    expect($service->initiate($cycle, 2, [$this->employee->id], $this->hr))->toBe(1);
    $q2 = PerformancePeriodReview::query()->where('appraisal_id', $appraisal->id)->where('sequence', 2)->sole();
    expect($q2->status)->toBe('open')->and($q2->opened_by)->toBe($this->hr->id)
        ->and([$q2->window_opens_on->toDateString(), $q2->window_closes_on->toDateString()])->toBe(['2026-10-20', '2026-11-03']);
});

it('calculates the review rating as the sum of all goals, with the policy\'s weightage and ratings above the scale when allowed', function () {
    ppPolicy(['rating_method' => 'sum', 'goal_weight' => 50, 'competency_weight' => 50, 'allow_above_max' => '1', 'max_goal_rating' => 6]);
    [$cycle, [$a, $b], $appraisal] = ppCycle(['manager_review', 'final'], [$this->c1->id], [['Revenue', 40], ['Margin', 40]], ['settings' => ['allow_resubmit' => true]]);
    $this->appraisals->submitReview($appraisal->review('manager'), [$a->id => 5, $b->id => 5], [$this->c1->id => 1], 4, [], $this->manager->user);

    // Sum of all goals: 100 % × 40 ÷ 100 + 100 % × 40 ÷ 100 = 80 %; blended 50 / 50 with 0 % on competencies = 40 % → 2.6.
    expect((float) $appraisal->refresh()->goal_score)->toBe(80.0)->and((float) $appraisal->computed_rating)->toBe(2.6);

    $this->appraisals->submitReview($appraisal->review('manager'), [$a->id => 6, $b->id => 6], [$this->c1->id => 5], 5, [], $this->manager->user);
    expect((float) $appraisal->refresh()->computed_rating)->toBe(5.0) // never past the scale's maximum
        ->and(fn () => $this->appraisals->submitReview($appraisal->review('manager'), [$a->id => 7], [], 5, [], $this->manager->user))->toThrow(RuntimeException::class, 'between 1 and 6');
});

it('shows each level\'s ratings and comments, the final rating and the final comments as the policy says', function () {
    $setup = function (array $settings) {
        ppPolicy($settings);
        [$cycle, [$goal], $appraisal] = ppCycle(['self_review', 'manager_review', 'approver_review', 'final']);
        $this->appraisals->submitReview($appraisal->review('self'), [$goal->id => 3], [], 3, [], $this->employee->user);
        ppAdvanceTo($cycle, 'manager_review');
        $this->appraisals->submitReview($appraisal->review('manager'), [$goal->id => 4], [], 4, [], $this->manager->user);
        ppAdvanceTo($cycle, 'approver_review');
        $this->appraisals->submitReview($appraisal->review('approver'), [$goal->id => 4], [], 4, [], $this->director->user);
        ppAdvanceTo($cycle, 'final');
        $this->appraisals->finalize($appraisal->refresh(), $this->hr, null, 'Well done');
        $appraisal = Appraisal::query()->with('reviews')->find($appraisal->id);

        return [$appraisal, $appraisal->review('self'), $appraisal->review('manager'), $appraisal->review('approver')];
    };
    $sees = fn (Appraisal $a, AppraisalReview $r, $employee) => AppraisalVisibility::reviewVisible($a, $r, $employee->user);

    [$a, $self, $manager, $approver] = $setup(['ratings_visible_to' => 'preceding_level']);
    expect($sees($a, $self, $this->manager))->toBeTrue()->and($sees($a, $approver, $this->manager))->toBeFalse()
        ->and($sees($a, $manager, $this->director))->toBeTrue()->and($sees($a, $self, $this->director))->toBeFalse()
        ->and($sees($a, $self, $this->employee))->toBeTrue()->and($sees($a, $manager, $this->employee))->toBeFalse()
        ->and(AppraisalVisibility::reviewVisible($a, $manager, $this->hr))->toBeTrue();

    [$a, $self, $manager, $approver] = $setup(['ratings_visible_to' => 'last_level', 'final_rating_visible_to' => 'last_level', 'final_comments_visible_to' => 'none']);
    expect($sees($a, $self, $this->director))->toBeTrue()->and($sees($a, $manager, $this->director))->toBeTrue()
        ->and($sees($a, $self, $this->manager))->toBeFalse()
        ->and(AppraisalVisibility::ratingsVisible($a, $this->director->user))->toBeTrue()
        ->and(AppraisalVisibility::ratingsVisible($a, $this->manager->user))->toBeFalse()
        ->and(AppraisalVisibility::ratingsVisible($a, $this->employee->user))->toBeFalse()
        ->and(AppraisalVisibility::finalCommentsVisible($a, $this->director->user))->toBeFalse()
        ->and(AppraisalVisibility::finalCommentsVisible($a, $this->hr))->toBeTrue();

    [$a, $self, $manager] = $setup(['ratings_visible_to' => 'reviewer', 'final_rating_visible_to' => 'all_except_employee']);
    expect($sees($a, $manager, $this->employee))->toBeFalse()->and($sees($a, $self, $this->director))->toBeTrue()
        ->and(AppraisalVisibility::ratingsVisible($a, $this->employee->user))->toBeFalse()
        ->and(AppraisalVisibility::partVisible($a, $this->employee->user, 'computed_rating'))->toBeFalse()
        ->and(AppraisalVisibility::ratingsVisible($a, $this->manager->user))->toBeTrue();

    [$a, $self, $manager] = $setup(['ratings_visible_to' => 'none']);
    expect($sees($a, $self, $this->manager))->toBeFalse()->and($sees($a, $manager, $this->director))->toBeFalse()
        ->and($sees($a, $manager, $this->manager))->toBeTrue(); // a reviewer always sees what they wrote
});

it('stores recommendations with the manager review and offers them to learning and compensation as suggestions', function () {
    ppPolicy(['recommend_training' => '1', 'recommend_promotion' => '1', 'recommend_increment' => '1', 'increment_type' => 'percentage', 'recommend_variable' => '1']);
    [$cycle, [$goal], $appraisal] = ppCycle(['self_review', 'manager_review', 'final']);
    $this->appraisals->submitReview($appraisal->review('self'), [$goal->id => 4], [], 4, ['recommendations' => ['increment' => 50]], $this->employee->user);
    ppAdvanceTo($cycle, 'manager_review');
    expect($appraisal->refresh()->recommendations)->toBeNull() // the employee's own review carries none
        ->and(fn () => $this->appraisals->submitReview($appraisal->review('manager'), [$goal->id => 4], [], 4, ['recommendations' => ['increment' => 120]], $this->manager->user))->toThrow(RuntimeException::class, 'at most 100');
    $this->appraisals->submitReview($appraisal->review('manager'), [$goal->id => 4], [], 4,
        ['recommendations' => ['training_competencies' => [$this->c1->id], 'training_note' => 'Negotiation skills', 'promotion' => true, 'increment' => 8, 'variable' => 10]], $this->manager->user);
    $recommendations = $appraisal->refresh()->recommendations;
    expect($recommendations['training'])->toBe(['competency_ids' => [$this->c1->id], 'note' => 'Negotiation skills'])
        ->and($recommendations['promotion'])->toBeTrue()->and($recommendations['increment'])->toEqual(['type' => 'percentage', 'value' => 8])
        ->and($recommendations['by'])->toBe('manager')
        ->and(app(PerformanceOutcomesReader::class)->finalOutcome($this->employee->id, $cycle->id))->toBeNull(); // not final yet

    ppAdvanceTo($cycle, 'final');
    $this->appraisals->finalize($appraisal->refresh(), $this->hr, null, null, true);
    $need = DevelopmentNeed::query()->where('employee_id', $this->employee->id)->where('source_type', 'appraisal')->sole();
    expect($need->competency_id)->toBe($this->c1->id)->and($need->source_id)->toBe($appraisal->id)
        ->and(app(PerformanceOutcomesReader::class)->finalOutcome($this->employee->id, $cycle->id)['recommendations']['increment'])->toEqual(['type' => 'percentage', 'value' => 8]);
    // Finalizing again never duplicates the need.
    expect(app(PerformanceRecommendations::class)->recordTrainingNeeds($appraisal->refresh(), $this->hr))->toBe(0);
});

it('shows the appraisal\'s increment suggestion on a compensation cycle line without changing the proposal', function () {
    syncComplianceRules();
    $company = payrollCompany();
    $planner = tenantUser($this->tenant, ['compensation.budget', 'compensation.cycles', 'compensation.propose', 'compensation.view']);
    $employee = salariedEmployee(600000, ['task.view'], '2026-04-01');
    $performanceCycle = draftCycle(['code' => 'FY26C']);
    app()->instance(PerformanceOutcomesReader::class, new class($employee->id) implements PerformanceOutcomesReader
    {
        public function __construct(private int $id) {}

        public function finalOutcome(int $employeeId, int $cycleId): ?array
        {
            return $employeeId === $this->id ? ['cycle_code' => 'FY26C', 'period_end' => '2027-03-31', 'final_rating' => 4.0, 'final_label' => 'Exceeds', 'promotion_recommended' => false, 'finalized_at' => '2027-05-01',
                'template_version_id' => null, 'rating_scale_checksum' => null, 'recommendations' => ['training' => null, 'promotion' => null, 'increment' => ['type' => 'percentage', 'value' => 8.0], 'variable' => null]] : null;
        }
    });
    $cycles = app(CompensationCycles::class);
    $cycle = $cycles->create(['code' => 'AI27', 'name' => 'Annual increment 2027', 'cycle_type' => 'annual_increment', 'company_id' => $company->id, 'effective_from' => '2027-04-01', 'default_increase_percent' => 5, 'performance_cycle_id' => $performanceCycle->id], $planner);
    $cycles->populate($cycle, $planner);
    $line = CompensationChange::query()->where('compensation_cycle_id', $cycle->id)->where('employee_id', $employee->id)->sole();

    expect($line->reason)->toContain('(appraisal suggests 8%)')->and((float) $line->ctc_annual)->toBe(630000.0)->and((float) $line->increase_percent)->toBe(5.0);
});

it('keeps goals inside the policy\'s goal window, asks the manager the review feedback form and drafts the performance letter', function () {
    $template = FeedbackTemplate::create(['code' => 'PRF', 'name' => 'Review feedback', 'process' => 'performance_review', 'questions' => [['title' => 'Ready for more?', 'type' => 'yes_no']]]);
    $letter = LetterTemplate::create(['name' => 'Performance letter', 'code' => 'PERF_LETTER', 'type' => 'performance', 'subject' => 'Your appraisal', 'requires_approval' => false, 'body' => '<p>Dear {{ employee.first_name }}</p>']);
    ppPolicy(['goal_window_days' => 10, 'review_feedback' => '1', 'review_feedback_template_id' => $template->id, 'performance_letter' => '1', 'letter_template_id' => $letter->id]);
    $cycle = $this->appraisals->launch(draftCycle(['competency_ids' => [], 'stages' => array_map(fn (string $key) => ['key' => $key, 'name' => $key, 'starts_on' => '2026-09-01', 'ends_on' => '2026-12-31'], ['self_review', 'manager_review', 'final'])]));

    // The window ran 1–10 April: the employee can no longer add goals; HR can.
    expect(fn () => app(Goals::class)->create(['employee_id' => $this->employee->id, 'performance_cycle_id' => $cycle->id, 'title' => 'Late goal', 'weight' => 100, 'target_value' => 1], [], $this->employee->user))
        ->toThrow(RuntimeException::class, 'goal window');
    $goal = app(Goals::class)->create(['employee_id' => $this->employee->id, 'performance_cycle_id' => $cycle->id, 'title' => 'Revenue', 'weight' => 100, 'target_value' => 100], [], $this->hr);

    $this->appraisals->advance($cycle->refresh(), $this->hr);
    $appraisal = Appraisal::query()->where('performance_cycle_id', $cycle->id)->where('employee_id', $this->employee->id)->sole();
    $request = FeedbackRequest::query()->withoutGlobalScope(AccessScope::class)->where('process', 'performance_review')->where('employee_id', $this->employee->id)->sole();
    expect($request->respondent_user_id)->toBe($this->manager->user_id)->and($request->subject_id)->toBe($appraisal->review('manager')->id);

    $this->appraisals->submitReview($appraisal->review('manager'), [$goal->id => 4], [], 4, [], $this->manager->user);
    ppAdvanceTo($cycle, 'final');
    $this->appraisals->finalize($appraisal->refresh(), $this->hr);
    expect(Letter::query()->where('source_type', $appraisal->getMorphClass())->where('source_id', $appraisal->id)->sole()->type)->toBe('performance');
});

it('keeps periodic reviews to the reviewer, HR and (when the policy allows) the employee, and inside their tenant', function () {
    ppPolicy(['review_cycle' => 'quarterly', 'ratings_visible_to' => 'reviewer']);
    [$cycle, , $appraisal] = ppCycle();
    $this->travelTo('2026-10-01 09:00:00');
    $service = app(PeriodicReviews::class);
    $service->runSchedule();
    $q2 = PerformancePeriodReview::query()->where('appraisal_id', $appraisal->id)->where('sequence', 2)->sole();
    $service->submit($q2, 4, 'Steady', $this->manager->user);
    $q2 = $q2->refresh()->setRelation('appraisal', $appraisal->refresh());
    $stranger = activeEmployee();

    expect($service->visibleTo($this->manager->user, $q2))->toBeTrue()->and($service->visibleTo($this->hr, $q2))->toBeTrue()
        ->and($service->visibleTo($this->employee->user, $q2))->toBeFalse() // "reviewers" keeps it from the employee
        ->and($service->visibleTo($stranger->user, $q2))->toBeFalse()
        ->and($service->mayRate($stranger->user, $q2))->toBeFalse()
        ->and(fn () => $q2->delete())->toThrow(RuntimeException::class, 'never deleted');

    actAsTenant(provisionTenant('Beta'));
    expect(PerformancePeriodReview::query()->withoutGlobalScope(AccessScope::class)->count())->toBe(0);
});

it('shows periodic reviews and recommendations on the appraisal and opens periodic reviews from the cycle', function () {
    ppPolicy(['review_cycle' => 'quarterly', 'review_manual_start' => '1', 'recommend_increment' => '1', 'recommend_training' => '1']);
    [$cycle, [$goal], $appraisal] = ppCycle();
    $this->travelTo('2026-10-01 09:00:00');

    Livewire\Livewire::test(EditPerformanceCycle::class, ['record' => $cycle->id])
        ->callAction('initiatePeriodicReview', data: ['sequence' => 2, 'employee_ids' => [$this->employee->id]])->assertNotified('1 periodic review(s) opened');
    $q2 = PerformancePeriodReview::query()->where('appraisal_id', $appraisal->id)->where('sequence', 2)->sole();

    $this->actingAs($this->manager->user);
    $this->get(AppraisalResource::getUrl('view', ['record' => $appraisal]))->assertOk();
    Livewire\Livewire::test(PeriodReviewsRelationManager::class, ['ownerRecord' => $appraisal, 'pageClass' => ViewAppraisal::class])
        ->assertOk()->assertCanSeeTableRecords([$q2])
        ->callTableAction('rate', $q2, ['rating' => '4', 'comment' => 'Steady'])->assertNotified('Periodic review submitted');
    expect($q2->refresh()->status)->toBe('submitted');

    $form = collect(PerformanceActions::reviewForm($appraisal->refresh(), true, 'manager'))->map(fn ($c) => $c->getHeading())->all();
    expect($form)->toContain('Recommendations')
        ->and(collect(PerformanceActions::reviewForm($appraisal, true, 'self'))->map(fn ($c) => $c->getHeading())->all())->not->toContain('Recommendations');
    $this->appraisals->submitReview($appraisal->review('manager'), [$goal->id => 4], [], 4, ['recommendations' => ['increment' => 7, 'training_note' => 'Public speaking']], $this->manager->user);
    expect(AppraisalResource::recommendationLines($appraisal->refresh()))->toBe(['Training needs: Public speaking', 'Salary increment: 7%']);
});
