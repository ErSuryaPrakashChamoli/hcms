<?php

use App\Domain\Analytics\Services\DatasetRegistry;
use App\Domain\Analytics\Services\ReportLibrary;
use App\Domain\Analytics\Services\ReportRunner;
use App\Domain\Claims\Models\ReimbursementClaim;
use App\Domain\Claims\Models\ReimbursementLedgerEntry;
use App\Domain\Configuration\Models\Policy;
use App\Domain\Configuration\Models\PolicyAssignmentRule;
use App\Domain\Configuration\Services\Policies;
use App\Domain\Employment\Models\Employee;
use App\Domain\Employment\Models\EmployeePosition;
use App\Domain\Feedback\Models\FeedbackTemplate;
use App\Domain\Identity\Models\Role;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Scopes\AccessScope;
use App\Domain\Identity\Services\AccessScopes;
use App\Domain\Identity\Services\PermissionRegistry;
use App\Domain\Loans\Models\Loan;
use App\Domain\Loans\Models\LoanInstallment;
use App\Domain\Organisation\Models\Location;
use App\Domain\Payroll\Models\SalaryComponent;
use App\Domain\Payroll\Models\VariablePayPlan;
use App\Domain\Payroll\Models\VariablePayRequest;
use App\Domain\Performance\Models\Appraisal;
use App\Domain\Performance\Models\AppraisalRating;
use App\Domain\Performance\Models\AppraisalReview;
use App\Domain\Performance\Models\Competency;
use App\Domain\Performance\Models\DevelopmentNeed;
use App\Domain\Performance\Models\FeedbackEntry;
use App\Domain\Performance\Models\FeedbackNomination;
use App\Domain\Performance\Models\FeedbackParticipant;
use App\Domain\Performance\Models\Goal;
use App\Domain\Performance\Models\GoalCheckIn;
use App\Domain\Performance\Models\GoalPlan;
use App\Domain\Performance\Models\ImprovementPlan;
use App\Domain\Performance\Models\ImprovementPlanCheckpoint;
use App\Domain\Performance\Models\OneOnOne;
use App\Domain\Performance\Models\OneOnOneFeedback;
use App\Domain\Performance\Models\PerformanceCheckIn;
use App\Domain\Performance\Services\FeedbackNominations;
use App\Domain\Performance\Services\FeedbackRoundResults;
use App\Domain\Performance\Services\FeedbackRounds;
use Illuminate\Database\Eloquent\Model;

require_once __DIR__.'/../Workflow/WorkflowTestHelpers.php';
require_once __DIR__.'/../Performance/PerformanceTestHelpers.php';

/* HROne parity AN-26 (loans, reimbursements, variable pay datasets) and AN-27 (performance datasets): every dataset behind
 * the right of the screen it reports on, money as sensitive fields, the reader's access scope and the tenant, the
 * performance visibility rules (review visibility, PIP confidentiality), anonymous givers never named, 360 results as
 * aggregates only with the policy minimum and never about the reader; every library template runs over real rows. */

/** @return list<string> */
function an26Datasets(): array
{
    return ['loans', 'loan_installments', 'reimbursement_ledger', 'reimbursement_claims', 'variable_pay_plans', 'variable_pay_requests'];
}

/** @return list<string> */
function an27Datasets(): array
{
    return ['one_on_ones', 'continuous_feedback', 'feedback_360_status', 'feedback_360_results', 'check_ins', 'performance_check_ins', 'goal_plans',
        'pip_checkpoints', 'appraisal_reviews', 'appraisal_ratings', 'development_needs'];
}

/** @return list<string> */
function an2627Templates(): array
{
    return ['loan_ledger', 'loan_requests', 'loan_summary', 'loans_outstanding', 'reimbursement_audit', 'reimbursement_monthly_ledger', 'reimbursement_requests',
        'reimbursement_summary', 'variable_paid', 'variable_pay_plan', 'variable_requests',
        'one_on_one_details', 'feedback_360_status', 'feedback_360_nominations', 'feedback_360_details', 'company_check_ins', 'employee_goal_check_ins', 'team_check_ins',
        'check_in_conversations', 'continuous_feedback', 'goal_sheet_status', 'goals_not_created', 'pip_evaluations', 'performance_feedback_details',
        'performance_review_details', 'performance_review_details_by_approver', 'performance_review_rating_by_approver', 'tni_details'];
}

/** Save a fixture row as the domain would, without depending on each model's fillable list. */
function an27Make(string $class, array $attributes): Model
{
    $model = new $class;
    $model->forceFill($attributes)->save();

    return $model;
}

/** Give an existing user a role with these permissions (and drop the memoised permission keys). */
function an27Grant(User $user, array $permissions): User
{
    $role = Role::factory()->create();
    $role->permissions()->sync(app(PermissionRegistry::class)->idsMatching($permissions));
    $user->roles()->attach($role);

    return $user->fresh();
}

/** Run a dataset as $user (signed in, as the report screens and schedules run it). */
function an27Run(string $dataset, array $definition, User $user)
{
    test()->actingAs($user);

    return app(ReportRunner::class)->execute($dataset, $definition, $user);
}

/** Two employees' worth of rows (a and b) behind every AN-26 / AN-27 dataset, plus a 360 round with real answers. */
function an27Seed(): void
{
    $t = test();
    [$a, $b, $manager, $p1, $p2, $p3, $hr] = [$t->a, $t->b, $t->manager, $t->p1, $t->p2, $t->p3, $t->hr];

    // AN-26 --------------------------------------------------------------------------------------------------------
    $t->fuel = SalaryComponent::create(['name' => 'Fuel reimbursement', 'code' => 'AN_FUEL', 'type' => 'reimbursement', 'classification' => 'reimbursement', 'calculation_method' => 'fixed',
        'taxable' => false, 'include_in_ctc' => true, 'include_in_gross' => false, 'is_recurring' => true, 'is_proratable' => false, 'pay_on_claim' => true, 'sort_order' => 50, 'status' => 'active']);
    $bonus = SalaryComponent::query()->where('code', 'BONUS')->firstOrFail();
    foreach (['a' => $a, 'b' => $b] as $key => $employee) {
        $loan = an27Make(Loan::class, ['employee_id' => $employee->id, 'number' => 'LN-'.$key, 'loan_type' => 'personal', 'principal' => 12000, 'interest_rate' => 0, 'installments' => 12, 'emi' => 1000,
            'first_due_month' => '2026-09', 'disbursed_through' => 'payroll', 'status' => 'active', 'reason' => 'Medical bills', 'disbursed_on' => '2026-08-25', 'outstanding' => 11000,
            'requested_by' => $hr->id, 'reviewed_by' => $hr->id, 'reviewed_at' => '2026-08-20 10:00:00']);
        foreach ([[1, '2026-09', 'recovered', '2026-09-30'], [2, '2026-10', 'due', null], [3, '2026-11', 'due', null]] as [$sequence, $month, $status, $on]) {
            an27Make(LoanInstallment::class, ['loan_id' => $loan->id, 'employee_id' => $employee->id, 'sequence' => $sequence, 'due_month' => $month, 'principal_part' => 1000, 'interest_part' => 0, 'amount' => 1000, 'status' => $status, 'recovered_on' => $on]);
        }
        an27Make(ReimbursementLedgerEntry::class, ['employee_id' => $employee->id, 'salary_component_id' => $t->fuel->id, 'financial_year' => 2026, 'month' => null, 'kind' => 'opening', 'amount' => 10000, 'entry_key' => 'opening-2026', 'note' => 'Go-live']);
        an27Make(ReimbursementLedgerEntry::class, ['employee_id' => $employee->id, 'salary_component_id' => $t->fuel->id, 'financial_year' => 2026, 'month' => '2026-09', 'kind' => 'accrual', 'amount' => 1250, 'entry_key' => 'accrual-9', 'note' => 'Accrued']);
        an27Make(ReimbursementLedgerEntry::class, ['employee_id' => $employee->id, 'salary_component_id' => $t->fuel->id, 'financial_year' => 2026, 'month' => '2026-09', 'kind' => 'paid', 'amount' => -900, 'entry_key' => 'claim-1', 'note' => 'Claim paid']);
        an27Make(ReimbursementClaim::class, ['employee_id' => $employee->id, 'salary_component_id' => $t->fuel->id, 'financial_year' => 2026, 'claim_date' => '2026-09-10', 'amount' => 1000, 'approved_amount' => 900,
            'description' => 'Fuel bills', 'bills' => [['number' => 'B1', 'amount' => 600], ['number' => 'B2', 'amount' => 400]], 'attachments' => ['claims/b1.pdf'], 'status' => 'paid', 'requested_by' => $employee->user_id, 'reviewed_by' => $hr->id, 'reviewed_at' => '2026-09-12 10:00:00']);
        an27Make(ReimbursementClaim::class, ['employee_id' => $employee->id, 'salary_component_id' => $t->fuel->id, 'financial_year' => 2026, 'claim_date' => '2026-10-05', 'amount' => 500, 'description' => 'Fuel October', 'bills' => [], 'attachments' => [], 'status' => 'pending']);
        $plan = an27Make(VariablePayPlan::class, ['employee_id' => $employee->id, 'salary_component_id' => $bonus->id, 'name' => 'Sales incentive '.$key, 'schedule' => [['month' => '2026-12', 'target' => 15000], ['month' => '2026-09', 'target' => 10000]], 'status' => 'active', 'created_by' => $hr->id]);
        an27Make(VariablePayRequest::class, ['employee_id' => $employee->id, 'salary_component_id' => $bonus->id, 'variable_pay_plan_id' => $plan->id, 'plan_month' => '2026-09', 'amount' => 9000, 'pay_month' => '2026-09', 'reason' => 'Q2 sales', 'status' => 'paid', 'requested_by' => $manager->user_id]);
        an27Make(VariablePayRequest::class, ['employee_id' => $employee->id, 'salary_component_id' => $bonus->id, 'amount' => 2000, 'pay_month' => '2026-10', 'reason' => 'Spot award', 'status' => 'pending', 'requested_by' => $hr->id]);
    }

    // AN-27 --------------------------------------------------------------------------------------------------------
    $t->cycle = $cycle = draftCycle();
    $competency = Competency::query()->orderBy('id')->firstOrFail();
    $t->goalA = an27Make(Goal::class, ['level' => 'employee', 'employee_id' => $a->id, 'performance_cycle_id' => $cycle->id, 'title' => 'Ship the release', 'weight' => 60, 'progress' => 40, 'status' => 'active', 'due_date' => '2026-12-31']);
    $goalB = an27Make(Goal::class, ['level' => 'employee', 'employee_id' => $b->id, 'performance_cycle_id' => $cycle->id, 'title' => 'Grow the pipeline', 'weight' => 100, 'progress' => 10, 'status' => 'pending_approval', 'due_date' => '2026-12-31']);
    $team = an27Make(Goal::class, ['level' => 'team', 'performance_cycle_id' => $cycle->id, 'title' => 'Team uptime', 'weight' => 100, 'progress' => 25, 'status' => 'active']);
    an27Make(GoalPlan::class, ['employee_id' => $b->id, 'performance_cycle_id' => $cycle->id, 'status' => 'pending', 'goals' => 1, 'total_weight' => 100, 'submitted_by' => $b->user_id, 'submitted_at' => '2026-10-02 10:00:00']);
    an27Make(GoalCheckIn::class, ['goal_id' => $t->goalA->id, 'previous_progress' => 0, 'progress' => 40, 'confidence' => 'on_track', 'source' => 'manual', 'note' => 'Good start', 'created_by' => $a->user_id]);
    an27Make(GoalCheckIn::class, ['goal_id' => $goalB->id, 'previous_progress' => 0, 'progress' => 10, 'confidence' => 'at_risk', 'source' => 'manual', 'note' => 'Slow month', 'created_by' => $b->user_id]);
    an27Make(GoalCheckIn::class, ['goal_id' => $team->id, 'previous_progress' => 0, 'progress' => 25, 'confidence' => 'on_track', 'source' => 'manual', 'note' => 'Team update', 'created_by' => $manager->user_id]);

    foreach ([$a, $b, $p1] as $employee) {
        an27Make(Appraisal::class, ['performance_cycle_id' => $cycle->id, 'employee_id' => $employee->id, 'manager_id' => $manager->id, 'status' => 'manager_review']);
    }
    $appraisalA = Appraisal::query()->where('employee_id', $a->id)->sole();
    $appraisalB = Appraisal::query()->where('employee_id', $b->id)->sole();
    $self = an27Make(AppraisalReview::class, ['appraisal_id' => $appraisalA->id, 'type' => 'self', 'reviewer_id' => $a->id, 'status' => 'submitted', 'overall_rating' => 4, 'comments' => 'I shipped it', 'submitted_at' => '2026-10-08 10:00:00']);
    $mgr = an27Make(AppraisalReview::class, ['appraisal_id' => $appraisalA->id, 'type' => 'manager', 'reviewer_id' => $manager->id, 'status' => 'submitted', 'overall_rating' => 3.5, 'strengths' => 'Clear thinker', 'improvements' => 'Delegate more', 'submitted_at' => '2026-10-10 10:00:00']);
    $t->anonymousReview = an27Make(AppraisalReview::class, ['appraisal_id' => $appraisalA->id, 'type' => 'peer', 'reviewer_id' => $p2->id, 'status' => 'submitted', 'overall_rating' => 3, 'is_anonymous' => true, 'comments' => 'Helpful', 'submitted_at' => '2026-10-09 10:00:00']);
    an27Make(AppraisalRating::class, ['appraisal_review_id' => $self->id, 'subject_type' => 'goal', 'subject_id' => $t->goalA->id, 'rating' => 3]);
    an27Make(AppraisalRating::class, ['appraisal_review_id' => $mgr->id, 'subject_type' => 'goal', 'subject_id' => $t->goalA->id, 'rating' => 4, 'achievement_pct' => 90, 'comment' => 'On track']);
    an27Make(AppraisalRating::class, ['appraisal_review_id' => $mgr->id, 'subject_type' => 'competency', 'subject_id' => $competency->id, 'rating' => 3]);
    an27Make(AppraisalRating::class, ['appraisal_review_id' => $t->anonymousReview->id, 'subject_type' => 'competency', 'subject_id' => $competency->id, 'rating' => 2]);
    $mgrB = an27Make(AppraisalReview::class, ['appraisal_id' => $appraisalB->id, 'type' => 'manager', 'reviewer_id' => $manager->id, 'status' => 'submitted', 'overall_rating' => 3, 'submitted_at' => '2026-10-10 10:00:00']);
    $approverB = an27Make(AppraisalReview::class, ['appraisal_id' => $appraisalB->id, 'type' => 'approver', 'reviewer_id' => $p3->id, 'status' => 'submitted', 'overall_rating' => 4, 'submitted_at' => '2026-10-11 10:00:00']);
    an27Make(AppraisalRating::class, ['appraisal_review_id' => $mgrB->id, 'subject_type' => 'goal', 'subject_id' => $goalB->id, 'rating' => 3]);
    an27Make(AppraisalRating::class, ['appraisal_review_id' => $approverB->id, 'subject_type' => 'goal', 'subject_id' => $goalB->id, 'rating' => 4]);

    foreach ([$a, $b] as $employee) {
        an27Make(PerformanceCheckIn::class, ['employee_id' => $employee->id, 'manager_id' => $manager->id, 'performance_cycle_id' => $cycle->id, 'cadence' => 'monthly', 'period_date' => '2026-10-01',
            'went_well' => 'Secret win', 'goal_progress' => [['goal_id' => 1, 'progress' => 40]], 'actions' => [['title' => 'Pair up']], 'status' => 'submitted', 'submitted_at' => '2026-10-05 10:00:00']);
        an27Make(DevelopmentNeed::class, ['employee_id' => $employee->id, 'source_type' => 'appraisal', 'competency_id' => $competency->id, 'title' => 'Stakeholder management', 'priority' => 'high', 'status' => 'open', 'target_date' => '2026-12-31', 'description' => 'Private description']);
    }
    $meeting = an27Make(OneOnOne::class, ['employee_id' => $a->id, 'manager_id' => $manager->id, 'initiated_by' => $a->id, 'scheduled_at' => '2026-10-10 11:00:00', 'held_at' => '2026-10-10 11:30:00', 'agenda' => 'Career path',
        'notes' => 'Shared notes', 'private_notes' => 'Private manager note', 'action_items' => [['title' => 'Course', 'done' => true], ['title' => 'Mentor', 'done' => false]], 'status' => 'held']);
    an27Make(OneOnOneFeedback::class, ['one_on_one_id' => $meeting->id, 'employee_id' => $a->id, 'role' => 'initiator', 'rating' => 4, 'comment' => 'Useful talk', 'acknowledged_at' => now()]);
    an27Make(OneOnOne::class, ['employee_id' => $b->id, 'manager_id' => $manager->id, 'scheduled_at' => '2026-10-20 11:00:00', 'agenda' => 'Targets', 'private_notes' => 'Private manager note', 'action_items' => [], 'status' => 'scheduled']);

    an27Make(FeedbackEntry::class, ['employee_id' => $a->id, 'author_id' => $manager->id, 'type' => 'praise', 'visibility' => 'manager', 'is_anonymous' => false, 'message' => 'Great demo', 'goal_id' => $t->goalA->id, 'status' => 'given']);
    an27Make(FeedbackEntry::class, ['employee_id' => $a->id, 'author_id' => $p2->id, 'type' => 'constructive', 'visibility' => 'private', 'is_anonymous' => true, 'message' => 'Speak up more', 'competency_id' => $competency->id, 'status' => 'given']);
    an27Make(FeedbackEntry::class, ['employee_id' => $b->id, 'author_id' => $p1->id, 'type' => 'praise', 'visibility' => 'public', 'is_anonymous' => false, 'message' => 'Thanks for the help', 'status' => 'given']);

    $planA = an27Make(ImprovementPlan::class, ['employee_id' => $a->id, 'manager_id' => $manager->id, 'start_date' => '2026-10-01', 'end_date' => '2026-12-31', 'reason' => 'Targets missed', 'objectives' => [], 'status' => 'active']);
    $planB = an27Make(ImprovementPlan::class, ['employee_id' => $b->id, 'manager_id' => $p3->id, 'start_date' => '2026-10-01', 'end_date' => '2026-12-31', 'reason' => 'Quality issues', 'objectives' => [], 'status' => 'active']);
    an27Make(ImprovementPlanCheckpoint::class, ['improvement_plan_id' => $planA->id, 'title' => 'Week 2 review', 'due_date' => '2026-10-14', 'status' => 'pending', 'kind' => 'checkpoint', 'notes' => 'Confidential note']);
    an27Make(ImprovementPlanCheckpoint::class, ['improvement_plan_id' => $planA->id, 'title' => 'Share the plan', 'due_date' => '2026-10-02', 'status' => 'met', 'kind' => 'start', 'owner' => 'HR', 'outcome' => 'Confidential outcome', 'reviewed_by' => $hr->id, 'reviewed_at' => '2026-10-02 10:00:00']);
    an27Make(ImprovementPlanCheckpoint::class, ['improvement_plan_id' => $planB->id, 'title' => 'Week 2 review', 'due_date' => '2026-10-14', 'status' => 'pending', 'kind' => 'checkpoint']);

    // 360: managers named, peers anonymous (minimum 3 answers), launched for a and b; a's peers p1, p2, p3; b's peer p1.
    $template = FeedbackTemplate::create(['code' => 'AN360', 'name' => '360 form', 'process' => 'feedback_360', 'questions' => [
        ['key' => 'teamwork', 'title' => 'Teamwork', 'type' => 'rating', 'scale_max' => 5, 'mandatory' => true],
        ['key' => 'comments', 'title' => 'Comments', 'type' => 'text', 'mandatory' => false],
    ]]);
    $policy = Policy::create(['type' => 'feedback_360', 'name' => '360 policy', 'code' => 'AN360']);
    app(Policies::class)->draft($policy, ['frequency' => 'quarterly', 'window_days' => 20, 'manager_feedback' => '1', 'manager_template_id' => $template->id, 'peer_feedback' => '1', 'peer_template_id' => $template->id]);
    app(Policies::class)->publish($policy, '2020-01-01');
    PolicyAssignmentRule::create(['policy_type' => 'feedback_360', 'policy_id' => $policy->id, 'name' => 'All', 'conditions' => []]);
    $t->round = app(FeedbackRounds::class)->launch($policy, ['employee_ids' => [$a->id, $b->id]], $hr);
    $nominations = app(FeedbackNominations::class);
    $participant = fn (Employee $e) => FeedbackParticipant::query()->withoutGlobalScope(AccessScope::class)->where('feedback_round_id', $t->round->id)->where('employee_id', $e->id)->sole();
    $t->participantA = $participant($a);
    $nominations->nominate($t->participantA, ['peer' => [$p1->id, $p2->id, $p3->id]], [], $a->user);
    $nominations->submit($t->participantA, $a->user);
    $nominations->nominate($participant($b), ['peer' => [$p1->id]], [], $b->user);
    $nominations->submit($participant($b), $b->user);
    $t->answer = function (Employee $about, Employee $reviewer, int $rating) use ($t, $nominations) {
        $nomination = FeedbackNomination::query()->withoutGlobalScope(AccessScope::class)->where('feedback_round_id', $t->round->id)->where('employee_id', $about->id)->where('reviewer_employee_id', $reviewer->id)->sole();
        $nominations->respond($nomination, ['teamwork' => $rating, 'comments' => 'Comment from '.$reviewer->person->full_name], $reviewer->user);
    };
    ($t->answer)($a, $p1, 4);
    ($t->answer)($a, $p2, 2);
    ($t->answer)($a, $manager, 5);
    ($t->answer)($b, $p1, 3);
}

beforeEach(function () {
    $this->travelTo('2026-10-15 09:00:00');
    $this->tenant = provisionTenant();
    actAsTenant($this->tenant);
    $this->hr = tenantUser($this->tenant, ['*']);
    $this->actingAs($this->hr);
    $this->manager = activeEmployee(null, ['performance.team', 'performance.review', 'performance.goals', 'task.view']);
    $this->a = activeEmployee($this->manager);
    $this->b = activeEmployee($this->manager);
    $this->p1 = activeEmployee($this->manager);
    $this->p2 = activeEmployee($this->manager);
    $this->p3 = activeEmployee($this->manager);
    an27Seed();
    $this->actingAs($this->hr);
});

it('registers the AN-26 and AN-27 datasets and templates, each behind the right of the screen it reports on', function () {
    $registry = app(DatasetRegistry::class);
    $library = app(ReportLibrary::class);
    foreach ([...an26Datasets(), ...an27Datasets()] as $key) {
        expect($registry->has($key))->toBeTrue($key)
            ->and(config("peopleos.analytics.unattended_datasets.{$key}"))->toBeNull(); // never read without a signed-in user
    }
    expect(collect(an26Datasets())->map(fn ($k) => $registry->get($k)->category())->unique()->all())->toBe(['Payroll & compensation'])
        ->and(collect(an27Datasets())->map(fn ($k) => $registry->get($k)->category())->unique()->all())->toBe(['Performance & learning']);
    foreach (an2627Templates() as $key) {
        expect($library->find($key))->not->toBeNull($key);
    }

    $payrollViewer = tenantUser($this->tenant, ['payroll.view', 'analytics.reports']);
    $payroll = tenantUser($this->tenant, ['payroll.manage', 'analytics.reports']);
    $performance = tenantUser($this->tenant, ['performance.view', 'analytics.reports']);
    $calibrator = tenantUser($this->tenant, ['performance.calibrate', 'analytics.reports']);
    $runner360 = tenantUser($this->tenant, ['performance.feedback_360', 'analytics.reports']);
    $pip = tenantUser($this->tenant, ['performance.pip', 'analytics.reports']);
    $available = fn (User $u) => array_keys(array_intersect_key($registry->availableTo($u), array_flip([...an26Datasets(), ...an27Datasets()])));

    expect($available($payrollViewer))->toBe([]) // the loan and claim screens are payroll.manage's
        ->and($available($payroll))->toBe(an26Datasets())
        ->and($available($performance))->toEqualCanonicalizing(array_values(array_diff(an27Datasets(), ['pip_checkpoints'])))
        ->and($available($calibrator))->toEqualCanonicalizing(['goal_plans', 'appraisal_reviews', 'appraisal_ratings'])
        ->and($available($runner360))->toEqualCanonicalizing(['feedback_360_status', 'feedback_360_results'])
        ->and($available($pip))->toBe(['pip_checkpoints'])
        ->and(fn () => an27Run('loans', [], $payrollViewer))->toThrow(RuntimeException::class, 'cannot report');

    // A run without a user is unattended: none of these datasets is on the reviewed list.
    foreach ([...an26Datasets(), ...an27Datasets()] as $key) {
        expect(fn () => app(ReportRunner::class)->execute($key, ['fields' => ['count']]))->toThrow(RuntimeException::class, 'not available without a signed-in user');
    }
});

it('runs every new template and every field over real rows without touching an unloaded relation', function () {
    $runner = app(ReportRunner::class);
    foreach ([...an26Datasets(), ...an27Datasets()] as $key) {
        $result = $runner->execute($key, [], $this->hr);
        expect($result->total)->toBeGreaterThan(1, $key)
            ->and(array_keys($result->columns))->toBe(array_keys(app(DatasetRegistry::class)->get($key)->fields()), $key);
    }
    foreach (an2627Templates() as $key) {
        $template = app(ReportLibrary::class)->find($key);
        $result = $runner->execute($template['dataset'], $template['definition'], $this->hr);
        expect($result->columns)->not->toBeEmpty($key)->and($result->total)->toBeGreaterThan(0, $key);
    }
});

it('reports loans with their EMI ledger, the reimbursement ledger and claims, and variable pay plans and payouts', function () {
    $library = app(ReportLibrary::class);
    $code = $this->a->employee_code;
    $row = fn ($result) => collect($result->rows)->firstWhere('employee_code', $code);

    $summary = an27Run('loans', $library->find('loan_summary')['definition'], $this->hr);
    expect($row($summary))->toMatchArray(['number' => 'LN-a', 'principal' => 12000.0, 'emi' => 1000.0, 'installments' => 12, 'installments_recovered' => 1, 'recovered' => 1000.0, 'outstanding' => 11000.0, 'next_due_month' => '2026-10', 'status_label' => 'Recovering']);
    $outstanding = an27Run('loans', $library->find('loans_outstanding')['definition'], $this->hr);
    expect($outstanding->rows)->toBe([['loan_type' => 'Personal loan', 'sum_outstanding' => 22000.0, 'count' => 2]]);
    $ledger = collect(an27Run('loan_installments', $library->find('loan_ledger')['definition'], $this->hr)->rows)->where('employee_code', $code);
    expect($ledger->pluck('due_month')->all())->toBe(['2026-09', '2026-10', '2026-11'])->and($ledger->pluck('status_label')->all())->toBe(['Recovered', 'Due', 'Due']);

    $audit = an27Run('reimbursement_ledger', $library->find('reimbursement_audit')['definition'], $this->hr);
    expect(collect($audit->rows)->firstWhere('employee_component', $code.' · '.$this->a->person->full_name.' — Fuel reimbursement'))
        ->toMatchArray(['sum_credit' => 11250.0, 'sum_debit' => 900.0, 'sum_amount' => 10350.0]);
    expect(an27Run('reimbursement_ledger', $library->find('reimbursement_summary')['definition'], $this->hr)->rows)
        ->toBe([['component' => 'Fuel reimbursement', 'sum_credit' => 22500.0, 'sum_debit' => 1800.0, 'sum_amount' => 20700.0]])
        // Last month (September) holds the accrual and the paid claim, not the opening.
        ->and(collect(an27Run('reimbursement_ledger', $library->find('reimbursement_monthly_ledger')['definition'], $this->hr)->rows)->where('employee_code', $code)->pluck('kind_label')->all())->toBe(['Accrued', 'Claimed and paid']);
    $claims = collect(an27Run('reimbursement_claims', ['fields' => ['employee_code', 'claim_date', 'amount', 'approved_amount', 'payable', 'bills', 'bills_total', 'attachments', 'status_label']], $this->hr)->rows)->where('employee_code', $code)->values();
    expect($claims->all())->toBe([
        ['employee_code' => $code, 'claim_date' => '2026-10-05', 'amount' => 500.0, 'approved_amount' => null, 'payable' => 500.0, 'bills' => 0, 'bills_total' => 0.0, 'attachments' => 0, 'status_label' => 'Pending'],
        ['employee_code' => $code, 'claim_date' => '2026-09-10', 'amount' => 1000.0, 'approved_amount' => 900.0, 'payable' => 900.0, 'bills' => 2, 'bills_total' => 1000.0, 'attachments' => 1, 'status_label' => 'Paid'],
    ]);

    $plans = an27Run('variable_pay_plans', $library->find('variable_pay_plan')['definition'], $this->hr);
    expect($row($plans))->toMatchArray(['schedule' => '2026-09: 10,000.00 · 2026-12: 15,000.00', 'total_target' => 25000.0, 'payouts_entered' => 1, 'paid_total' => 9000.0, 'months_without_payout' => 0, 'next_month' => '2026-12']);
    $paid = an27Run('variable_pay_requests', $library->find('variable_paid')['definition'], $this->hr);
    expect(collect($paid->rows)->pluck('employee_code')->sort()->values()->all())->toBe(collect([$code, $this->b->employee_code])->sort()->values()->all())
        ->and($row($paid))->toMatchArray(['plan_month' => '2026-09', 'amount' => 9000.0]);
    $requests = collect(an27Run('variable_pay_requests', ['fields' => ['employee_code', 'pay_month', 'plan_target', 'amount', 'variance', 'status_label']], $this->hr)->rows)->where('employee_code', $code)->values();
    expect($requests->all())->toBe([
        ['employee_code' => $code, 'pay_month' => '2026-10', 'plan_target' => null, 'amount' => 2000.0, 'variance' => null, 'status_label' => 'Pending'],
        ['employee_code' => $code, 'pay_month' => '2026-09', 'plan_target' => 10000.0, 'amount' => 9000.0, 'variance' => -1000.0, 'status_label' => 'Paid'],
    ]);
});

it('keeps loan, claim and variable pay amounts to readers who may see them', function () {
    $registry = app(DatasetRegistry::class);
    foreach (['loans' => 'principal', 'loan_installments' => 'amount', 'reimbursement_ledger' => 'credit', 'reimbursement_claims' => 'amount', 'variable_pay_plans' => 'total_target', 'variable_pay_requests' => 'amount'] as $key => $money) {
        $dataset = $registry->get($key);
        expect($dataset->sensitivePermission())->toBe('payroll.manage')
            // A feed without a reader (the warehouse) leaves money out; the payroll reader gets it.
            ->and($dataset->fieldsFor(null))->not->toHaveKey($money)->toHaveKey('employee_code')
            ->and($dataset->fieldsFor(tenantUser($this->tenant, ['payroll.manage'])))->toHaveKey($money);
    }
});

it('never names anonymous feedback givers or reviewers, and keeps private notes and comments out', function () {
    $feedback = an27Run('continuous_feedback', ['fields' => ['employee_code', 'type_code', 'author', 'is_anonymous', 'message']], $this->hr);
    $anonymous = collect($feedback->rows)->firstWhere('is_anonymous', true);
    expect($anonymous)->toMatchArray(['employee_code' => $this->a->employee_code, 'author' => 'Anonymous', 'message' => 'Speak up more'])
        ->and(collect($feedback->rows)->pluck('author')->all())->not->toContain($this->p2->person->full_name)
        ->and(collect($feedback->rows)->pluck('author')->all())->toContain($this->manager->person->full_name);

    $reviews = an27Run('appraisal_reviews', ['fields' => ['employee_code', 'level_code', 'reviewer', 'overall_rating', 'strengths', 'comments']], $this->hr);
    $peer = collect($reviews->rows)->firstWhere('level_code', 'peer');
    expect($peer)->toMatchArray(['reviewer' => 'Anonymous', 'overall_rating' => 3.0])
        ->and(json_encode($reviews->rows))->not->toContain($this->p2->person->full_name);
    $ratings = an27Run('appraisal_ratings', ['fields' => ['employee_code', 'level_code', 'reviewer']], $this->hr);
    expect(json_encode($ratings->rows))->not->toContain($this->p2->person->full_name);

    $meetings = an27Run('one_on_ones', [], $this->hr);
    expect(json_encode($meetings->rows))->not->toContain('Private manager note')->not->toContain('Shared notes')->not->toContain('Useful talk')
        ->and(collect($meetings->rows)->firstWhere('employee_code', $this->a->employee_code))->toMatchArray(['requested_by_employee' => true, 'action_items' => 2, 'action_items_open' => 1, 'feedback_given' => 1, 'feedback_rating' => 4.0, 'status' => 'held']);
    expect(json_encode(an27Run('performance_check_ins', [], $this->hr)->rows))->not->toContain('Secret win')
        ->and(json_encode(an27Run('development_needs', [], $this->hr)->rows))->not->toContain('Private description')
        ->and(json_encode(an27Run('pip_checkpoints', [], $this->hr)->rows))->not->toContain('Confidential')->not->toContain('Targets missed');

    // performance.calibrate reads ratings like HR but never the comment text (performance.view).
    $calibrator = tenantUser($this->tenant, ['performance.calibrate', 'analytics.reports']);
    $columns = array_keys(an27Run('appraisal_reviews', [], $calibrator)->columns);
    expect($columns)->toContain('overall_rating')->not->toContain('strengths')->not->toContain('comments')
        ->and(array_keys(an27Run('appraisal_ratings', [], $calibrator)->columns))->not->toContain('comment');

    // Ratings and comments follow the review visibility for the reader: none without one.
    auth()->forgetUser();
    $dataset = app(DatasetRegistry::class)->get('appraisal_reviews');
    $review = AppraisalReview::query()->withoutGlobalScopes()->where('type', 'manager')->where('appraisal_id', Appraisal::query()->where('employee_id', $this->a->id)->value('id'))->sole();
    expect($dataset->value('overall_rating', $review))->toBeNull()->and($dataset->value('strengths', $review))->toBeNull();
    $this->actingAs($this->hr);
    expect($dataset->value('overall_rating', $review))->toBe(3.5)->and($dataset->value('strengths', $review))->toBe('Clear thinker');
});

it('marks the final review level and gives the weightage of each goal, KPI and competency', function () {
    $library = app(ReportLibrary::class);
    $final = collect(an27Run('appraisal_ratings', $library->find('performance_review_details')['definition'], $this->hr)->rows);
    expect($final->where('employee_code', $this->a->employee_code)->map(fn ($r) => [$r['item_type'], $r['item'], $r['rating']])->values()->all())
        ->toEqualCanonicalizing([['Goal', 'Ship the release', 4.0], ['Competency', Competency::query()->orderBy('id')->value('name'), 3.0]])
        ->and($final->firstWhere('item', 'Ship the release')['weight'])->toBe(60.0)
        // b's appraisal has an approver: the approver's rating is the final one, not the manager's.
        ->and($final->where('employee_code', $this->b->employee_code)->pluck('rating')->all())->toBe([4.0]);
    $all = collect(an27Run('appraisal_ratings', $library->find('performance_review_details_by_approver')['definition'], $this->hr)->rows);
    expect($all->where('employee_code', $this->b->employee_code)->pluck('reviewer')->sort()->values()->all())
        ->toBe(collect([$this->manager->person->full_name, $this->p3->person->full_name])->sort()->values()->all());
    $byApprover = collect(an27Run('appraisal_reviews', $library->find('performance_review_rating_by_approver')['definition'], $this->hr)->rows)->where('employee_code', $this->a->employee_code);
    expect($byApprover->pluck('overall_rating', 'level')->all())->toBe(['Self' => 4.0, 'Manager' => 3.5, 'Peer' => 3.0])
        ->and(collect(an27Run('appraisal_reviews', $library->find('performance_feedback_details')['definition'], $this->hr)->rows)->pluck('strengths')->filter()->values()->all())->toBe(['Clear thinker']);
});

it('reports 360 feedback as aggregates only: the minimum for anonymous sources, never a reviewer, never about the reader', function () {
    $library = app(ReportLibrary::class);
    $details = fn (User $reader) => collect(an27Run('feedback_360_results', $library->find('feedback_360_details')['definition'], $reader)->rows);
    $sources = fn ($rows, Employee $about) => $rows->where('employee_code', $about->employee_code)->keyBy('source')
        ->map(fn ($r) => [$r['anonymous'], $r['asked'], $r['answered'], $r['average_score']])->all();

    $rows = $details($this->hr);
    expect($sources($rows, $this->a))->toBe(['Manager' => [false, 1, 1, 100.0], 'Peer' => [true, 3, 2, null]])
        ->and($rows->firstWhere('source', 'Peer')['note'])->toContain('fewer than 3 answers')
        ->and($sources($rows, $this->b))->toBe(['Manager' => [false, 1, 0, null], 'Peer' => [true, 1, 1, null]])
        // No name of any reviewer, no comment text, no single answer.
        ->and(json_encode($rows->all()))->not->toContain($this->p1->person->full_name)->not->toContain('Comment from')->not->toContain($this->manager->person->full_name);

    // The third peer answers: the anonymous source is shown, as its average only — the same number as the round's export.
    ($this->answer)($this->a, $this->p3, 3);
    $rows = $details($this->hr);
    $export = collect(app(FeedbackRoundResults::class)->exportRows($this->round->refresh(), $this->hr))->where('employee_code', $this->a->employee_code)->firstWhere('source', 'Peer');
    expect($sources($rows, $this->a)['Peer'])->toBe([true, 3, 3, 50.0])->and($export['average_score_pct'])->toBe(50.0);

    // HR never reads 360 results about themselves.
    $aAsHr = an27Grant($this->a->user, ['performance.view', 'analytics.reports']);
    expect($details($aAsHr)->pluck('employee_code')->unique()->values()->all())->toBe([$this->b->employee_code]);

    $status = collect(an27Run('feedback_360_status', $library->find('feedback_360_status')['definition'], $this->hr)->rows)->keyBy('employee_code');
    expect($status[$this->a->employee_code])->toMatchArray(['nomination_status' => 'Approved', 'asked' => 4, 'given' => 4, 'declined' => 0, 'pending' => 0])
        ->and($status[$this->b->employee_code])->toMatchArray(['asked' => 2, 'given' => 1, 'pending' => 1])
        ->and(json_encode($status->all()))->not->toContain($this->p1->person->full_name);
});

it('lists every employee of a cycle by goal sheet status, including those without goals, as the Goal sheets screen does', function () {
    $library = app(ReportLibrary::class);
    $rows = collect(an27Run('goal_plans', ['fields' => ['employee_code', 'sheet_status_key', 'goals', 'total_weight', 'versions', 'latest_plan_status', 'submitted_on']], $this->hr)->rows)->keyBy('employee_code');
    expect($rows[$this->a->employee_code])->toMatchArray(['sheet_status_key' => 'active', 'goals' => 1, 'total_weight' => 60.0, 'versions' => 0])
        ->and($rows[$this->b->employee_code])->toMatchArray(['sheet_status_key' => 'pending', 'versions' => 1, 'latest_plan_status' => 'Waiting for approval', 'submitted_on' => '2026-10-02'])
        ->and($rows[$this->p1->employee_code])->toMatchArray(['sheet_status_key' => 'not_created', 'goals' => 0]);
    expect(collect(an27Run('goal_plans', $library->find('goals_not_created')['definition'], $this->hr)->rows)->pluck('employee_code')->all())->toBe([$this->p1->employee_code])
        ->and(collect(an27Run('goal_plans', $library->find('goal_sheet_status')['definition'], $this->hr)->rows)->pluck('employee_code')->sort()->values()->all())
        ->toBe(collect([$this->b->employee_code, $this->p1->employee_code])->sort()->values()->all());

    $checkIns = collect(an27Run('check_ins', ['fields' => ['level', 'owner', 'employee_code', 'goal', 'change', 'note']], $this->hr)->rows);
    expect($checkIns->firstWhere('level', 'team'))->toMatchArray(['owner' => 'Organisation', 'employee_code' => null, 'goal' => 'Team uptime', 'change' => 25.0])
        ->and(collect(an27Run('check_ins', $library->find('employee_goal_check_ins')['definition'], $this->hr)->rows)->pluck('employee_code')->sort()->values()->all())
        ->toBe(collect([$this->a->employee_code, $this->b->employee_code])->sort()->values()->all())
        ->and(collect(an27Run('check_ins', $library->find('team_check_ins')['definition'], $this->hr)->rows)->pluck('goal')->all())->toBe(['Team uptime']);
});

it('keeps PIP checkpoints confidential: performance.pip only, and only the plans the screen lists for the reader', function () {
    $pipManager = an27Grant($this->manager->user, ['performance.pip', 'analytics.reports']);
    $rows = collect(an27Run('pip_checkpoints', ['fields' => ['employee_code', 'checkpoint', 'kind', 'is_overdue', 'status_label']], $pipManager)->rows);
    // The manager runs a's plan only (b's plan has another manager), as the Improvement plans screen lists it.
    expect($rows->pluck('employee_code')->unique()->values()->all())->toBe([$this->a->employee_code])
        ->and($rows->firstWhere('checkpoint', 'Week 2 review'))->toMatchArray(['kind' => 'Checkpoint', 'is_overdue' => true, 'status_label' => 'Pending']);
    expect(an27Run('pip_checkpoints', ['fields' => ['employee_code']], $this->hr)->total)->toBe(3)
        ->and(fn () => an27Run('pip_checkpoints', [], tenantUser($this->tenant, ['performance.view', 'analytics.reports'])))->toThrow(RuntimeException::class, 'cannot report');
    auth()->forgetUser();
    expect(app(DatasetRegistry::class)->get('pip_checkpoints')->query()->count())->toBe(0); // no reader, no rows
});

it('keeps every new dataset inside the reader\'s access scope and the tenant', function () {
    $location = Location::factory()->create(['company_id' => $this->a->currentPosition->company_id]);
    EmployeePosition::query()->where('employee_id', $this->a->id)->update(['location_id' => $location->id]);
    $scoped = tenantUser($this->tenant, ['payroll.manage', 'performance.view', 'performance.pip', 'performance.feedback_360', 'analytics.reports']);
    app(AccessScopes::class)->assign($scoped, ['location' => [$location->id]]);

    foreach ([...an26Datasets(), ...an27Datasets()] as $key) {
        $codes = collect(an27Run($key, ['fields' => ['employee_code']], $scoped)->rows)->pluck('employee_code')->filter()->unique()->values()->all();
        expect($codes)->toBe([$this->a->employee_code], $key);
        $all = collect(an27Run($key, ['fields' => ['employee_code']], $this->hr)->rows)->pluck('employee_code');
        expect($all)->toContain($this->b->employee_code);
    }

    $other = provisionTenant('Other');
    actAsTenant($other);
    $stranger = tenantUser($other, ['*']);
    foreach ([...an26Datasets(), ...an27Datasets()] as $key) {
        expect(an27Run($key, [], $stranger)->total)->toBe(0, $key);
    }
});
