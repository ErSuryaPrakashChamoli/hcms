<?php

use App\Domain\Configuration\Exceptions\ConfigurationException;
use App\Domain\Configuration\Models\Policy;
use App\Domain\Configuration\Models\PolicyAssignmentRule;
use App\Domain\Configuration\Services\Policies;
use App\Domain\Configuration\Services\PolicySetup;
use App\Domain\Configuration\Services\SetupCatalogue;
use App\Domain\Experience\Services\ApprovalCenter;
use App\Domain\Experience\Services\ApprovalDecisions;
use App\Domain\Performance\Models\Competency;
use App\Domain\Performance\Models\Goal;
use App\Domain\Performance\Models\GoalPlan;
use App\Domain\Performance\Services\GoalPlans;
use App\Domain\Performance\Services\Goals;
use App\Domain\Performance\Services\GoalSheetRules;
use App\Domain\Performance\Support\GoalFieldRules;
use App\Domain\Workflow\Models\WorkflowInstance;
use App\Domain\Workflow\Models\WorkflowTask;
use App\Domain\Workflow\Services\WorkflowEngine;
use App\Filament\Pages\PolicyWizard;
use App\Filament\Resources\Goals\Pages\EditGoal;
use App\Filament\Resources\Policies\Pages\ListPolicies;
use App\Filament\Support\PolicySettingsSchema;
use Livewire\Livewire;

require_once __DIR__.'/../Workflow/WorkflowTestHelpers.php';
require_once __DIR__.'/../Performance/PerformanceTestHelpers.php';

/* HROne setup parity HP-8d — Goal → Goal sheet policy: KRA, KPI and competency weight and count rules, equal weightage,
 * field rights per role, goal sheet approval (Approval Center and workflow), the policy validator and "Duplicate" on
 * policies. Without a policy goals work exactly as before. */

beforeEach(function () {
    $this->travelTo('2026-09-21 09:00:00');
    $this->tenant = provisionTenant();
    actAsTenant($this->tenant);
    $this->hr = tenantUser($this->tenant, ['*']);
    $this->actingAs($this->hr);
    $this->manager = activeEmployee(null, ['performance.team', 'performance.review', 'performance.goals', 'task.view', 'task.act']);
    $this->employee = activeEmployee($this->manager);
    $this->goals = app(Goals::class);
    $this->cycle = draftCycle(['settings' => ['required_goal_weight' => 100]]);
    // A goal on the employee's sheet of the test cycle, created by $actor (the employee by default).
    $this->goal = fn (array $attributes, array $keyResults = [], $actor = null) => $this->goals->create(
        $attributes + ['employee_id' => $this->employee->id, 'performance_cycle_id' => $this->cycle->id, 'status' => 'draft'], $keyResults, $actor ?? $this->employee->user);
});

/** A published goal sheet policy for everyone. */
function gspPolicy(array $settings, string $code = 'GOAL_SHEET'): Policy
{
    $policy = Policy::create(['type' => 'goal_sheet', 'name' => 'Goal sheet', 'code' => $code]);
    app(Policies::class)->draft($policy, $settings);
    app(Policies::class)->publish($policy, '2020-01-01');
    PolicyAssignmentRule::create(['policy_type' => 'goal_sheet', 'policy_id' => $policy->id, 'name' => 'All', 'conditions' => []]);

    return $policy;
}

it('keeps goals, goal plans and the goal form as before without a goal sheet policy', function () {
    $a = ($this->goal)(['title' => 'Revenue', 'weight' => 60]);
    ($this->goal)(['title' => 'Hiring', 'weight' => 40]);
    $rules = app(GoalSheetRules::class);

    expect($this->goals->requiredWeight($this->cycle->id, $this->employee))->toBe(100.0)
        ->and(GoalFieldRules::for($this->employee, $this->employee->user)->restricted())->toBeFalse()
        ->and($rules->planApproval($this->employee))->toBe('none')
        ->and($rules->creation($this->employee))->toBe(['mode' => 'manual', 'template' => null, 'owner' => 'employee'])
        ->and((int) $a->refresh()->weight)->toBe(60)
        ->and($this->goals->submitPlan($this->employee, $this->cycle->id, $this->employee->user))->toBe(2)
        ->and(Goal::query()->where('employee_id', $this->employee->id)->pluck('status')->unique()->all())->toBe(['active'])
        ->and(GoalPlan::query()->count())->toBe(0);

    $this->actingAs($this->employee->user);
    Livewire::test(EditGoal::class, ['record' => $a->id])->assertOk()->assertFormFieldEnabled('weight')->assertFormFieldVisible('perspective_id');
});

it('applies the KRA weightage and number rules of the goal sheet policy, its total before the cycle\'s', function () {
    gspPolicy(['total_goal_weight' => 90, 'goal_weight_mode' => 'specific', 'min_goal_weight' => 10, 'max_goal_weight' => 50, 'min_goals' => 3, 'max_goals' => 3, 'unit_and_target_required' => 1]);
    $make = fn (array $a) => ($this->goal)($a + ['unit' => '%', 'target_value' => 100]);

    expect($this->goals->requiredWeight($this->cycle->id, $this->employee))->toBe(90.0)
        ->and($this->goals->requiredWeight($this->cycle->id))->toBe(100.0)
        ->and(fn () => $make(['title' => 'No weight', 'weight' => 0]))->toThrow(RuntimeException::class, 'Each KRA needs a weightage')
        ->and(fn () => $make(['title' => 'Too small', 'weight' => 5]))->toThrow(RuntimeException::class, 'at least 10')
        ->and(fn () => $make(['title' => 'Too big', 'weight' => 60]))->toThrow(RuntimeException::class, 'at most 50')
        ->and(fn () => ($this->goal)(['title' => 'No unit', 'weight' => 20, 'target_value' => 100]))->toThrow(RuntimeException::class, 'unit of measurement (UOM) of a KRA is mandatory');

    $a = $make(['title' => 'Revenue', 'weight' => 50]);
    $make(['title' => 'Hiring', 'weight' => 40]);
    expect(fn () => $this->goals->submitPlan($this->employee, $this->cycle->id, $this->employee->user))->toThrow(RuntimeException::class, 'needs at least 3 KRA(s); it has 2');

    $this->goals->update($a, ['weight' => 40], null, $this->employee->user);
    $make(['title' => 'Learning', 'weight' => 10]);
    expect(fn () => $make(['title' => 'Fourth', 'weight' => 10]))->toThrow(RuntimeException::class, 'most KRAs allowed (3)')
        ->and($this->goals->submitPlan($this->employee, $this->cycle->id, $this->employee->user))->toBe(3);
});

it('applies the KPI rules: their number, single weightage and total within the KRA, and the UOM and target', function () {
    gspPolicy(['min_kpis' => 2, 'max_kpis' => 3, 'kpi_total_weight' => 'equal_to_goal', 'min_kpi_weight' => 10]);
    $kr = fn (string $title, int $weight) => ['title' => $title, 'weight' => $weight, 'target_value' => 100, 'measure_type' => 'percentage'];

    expect(fn () => ($this->goal)(['title' => 'One KPI', 'weight' => 40], [$kr('a', 40)]))->toThrow(RuntimeException::class, 'at least 2 KPI(s)')
        ->and(fn () => ($this->goal)(['title' => 'Four KPIs', 'weight' => 40], [$kr('a', 10), $kr('b', 10), $kr('c', 10), $kr('d', 10)]))->toThrow(RuntimeException::class, 'at most 3 KPI(s)')
        ->and(fn () => ($this->goal)(['title' => 'Short', 'weight' => 40], [$kr('a', 20), $kr('b', 10)]))->toThrow(RuntimeException::class, 'add up to 30; they must add up to 40')
        ->and(fn () => ($this->goal)(['title' => 'Tiny', 'weight' => 40], [$kr('a', 35), $kr('b', 5)]))->toThrow(RuntimeException::class, 'at least 10');

    $goal = ($this->goal)(['title' => 'Fine', 'weight' => 40], [$kr('a', 20), $kr('b', 20)]);
    expect($goal->keyResults()->pluck('weight')->all())->toBe([20, 20]);

    // The goal form saves key results itself: the same rules are checked from its rows, before anything is saved.
    expect(fn () => $this->goals->assertKeyResultRows($this->employee, $goal, 40, [$kr('a', 40)], $this->employee->user))->toThrow(RuntimeException::class, 'at least 2 KPI(s)');
});

it('splits the weightage equally between KRAs and between KPIs when the policy says so', function () {
    gspPolicy(['goal_weight_mode' => 'equal', 'kpi_weight_mode' => 'equal', 'kpi_total_weight' => 'equal_to_goal']);
    $weights = fn () => Goal::query()->where('employee_id', $this->employee->id)->whereIn('status', ['draft', 'active'])->orderBy('id')->pluck('weight')->all();

    $a = ($this->goal)(['title' => 'Revenue', 'weight' => 70], [['title' => 'k1', 'weight' => 0], ['title' => 'k2', 'weight' => 0]]);
    expect($weights())->toBe([100])->and($a->keyResults()->pluck('weight')->all())->toBe([50, 50]);
    ($this->goal)(['title' => 'Hiring']);
    expect($weights())->toBe([50, 50])->and($a->keyResults()->pluck('weight')->all())->toBe([25, 25]);
    $c = ($this->goal)(['title' => 'Learning']);
    expect($weights())->toBe([34, 33, 33]);

    $this->goals->close($c, 'cancelled', 'Dropped');
    expect($weights())->toBe([50, 50])
        ->and($this->goals->planStatus($this->employee, $this->cycle->id)['valid'])->toBeTrue();
});

it('gives each role its field rights: hidden, view only, editable or mandatory; HR is not limited', function () {
    gspPolicy(['field_rules' => [
        ['field' => 'weight', 'creator' => 'view', 'employee' => 'view', 'manager' => 'edit'],
        ['field' => 'description', 'creator' => 'required', 'employee' => 'required'],
        ['field' => 'perspective', 'creator' => 'hidden', 'employee' => 'hidden'],
        ['field' => 'key_results', 'employee' => 'view', 'creator' => 'view'],
    ]]);

    expect(fn () => ($this->goal)(['title' => 'Mine', 'weight' => 30, 'description' => 'Why']))->toThrow(RuntimeException::class, 'cannot change the weightage')
        ->and(fn () => ($this->goal)(['title' => 'Mine']))->toThrow(RuntimeException::class, 'Description is mandatory')
        ->and(fn () => ($this->goal)(['title' => 'Mine', 'description' => 'Why'], [['title' => 'k', 'weight' => 0]]))->toThrow(RuntimeException::class, 'cannot change the KPIs');

    $goal = ($this->goal)(['title' => 'Mine', 'description' => 'Why']);
    expect(fn () => $this->goals->update($goal, ['weight' => 20], null, $this->employee->user))->toThrow(RuntimeException::class, 'cannot change the weightage');
    $this->goals->update($goal->refresh(), ['title' => 'Mine, sharper'], null, $this->employee->user);
    $this->goals->update($goal->refresh(), ['weight' => 20], null, $this->manager->user);
    expect((int) $goal->refresh()->weight)->toBe(20)->and($goal->title)->toBe('Mine, sharper');

    $employeeRights = GoalFieldRules::for($this->employee, $this->employee->user, $goal);
    $managerRights = GoalFieldRules::for($this->employee, $this->manager->user, $goal);
    expect($employeeRights->visible('perspective'))->toBeFalse()->and($employeeRights->editable('weight'))->toBeFalse()->and($employeeRights->required('description'))->toBeTrue()
        ->and($managerRights->visible('perspective'))->toBeTrue()->and($managerRights->editable('weight'))->toBeTrue()
        ->and(GoalFieldRules::for($this->employee, $this->hr, $goal)->restricted())->toBeFalse();

    $this->actingAs($this->employee->user);
    Livewire::test(EditGoal::class, ['record' => $goal->id])->assertOk()
        ->assertFormFieldDisabled('weight')->assertFormFieldHidden('perspective_id')->assertFormFieldEnabled('title');
    $this->actingAs($this->manager->user);
    Livewire::test(EditGoal::class, ['record' => $goal->id])->assertOk()->assertFormFieldEnabled('weight')->assertFormFieldVisible('perspective_id');
});

it('sends a submitted goal sheet to the reporting manager for approval in the Approval Center', function () {
    gspPolicy(['plan_approval' => 'manager']);
    publishWorkflow(...[...linear([['id' => 'a', 'type' => 'approval', 'name' => 'HR', 'config' => ['mode' => 'single', 'approver' => ['type' => 'user', 'user_id' => $this->hr->id]]]]), ['trigger_event' => 'performance.goal_plan.submitted']]);
    ($this->goal)(['title' => 'Revenue', 'weight' => 60]);
    ($this->goal)(['title' => 'Hiring', 'weight' => 40]);
    $outsider = activeEmployee(null, ['performance.team']);

    expect($this->goals->submitPlan($this->employee, $this->cycle->id, $this->employee->user))->toBe(2);
    $plan = GoalPlan::query()->sole();
    expect($plan->status)->toBe('pending')->and($plan->approval)->toBe('manager')
        ->and(Goal::query()->where('employee_id', $this->employee->id)->pluck('status')->unique()->all())->toBe(['pending_approval'])
        ->and(WorkflowInstance::query()->count())->toBe(0) // the manager decides; no workflow runs
        ->and(app(ApprovalCenter::class)->pending($this->manager->user)->pluck('id'))->toContain('goal_plan:'.$plan->id)
        ->and(app(ApprovalCenter::class)->pending($this->employee->user)->pluck('id'))->not->toContain('goal_plan:'.$plan->id)
        ->and(app(ApprovalCenter::class)->pending($outsider->user)->pluck('id'))->not->toContain('goal_plan:'.$plan->id)
        ->and(fn () => app(GoalPlans::class)->approve($plan, null, $outsider->user))->toThrow(RuntimeException::class, 'cannot decide')
        ->and(fn () => app(GoalPlans::class)->approve($plan, null, $this->employee->user))->toThrow(RuntimeException::class, 'cannot decide')
        ->and(fn () => $this->goals->submitPlan($this->employee, $this->cycle->id, $this->employee->user))->toThrow(RuntimeException::class, 'already waiting');

    app(ApprovalDecisions::class)->decide($this->manager->user, 'goal_plan:'.$plan->id, 'reject', 'Add a learning goal');
    expect($plan->refresh()->status)->toBe('rejected')->and($plan->decided_by)->toBe($this->manager->user->id)
        ->and(Goal::query()->where('employee_id', $this->employee->id)->pluck('status')->unique()->all())->toBe(['draft'])
        ->and($this->employee->user->notifications()->get()->pluck('data.title')->filter(fn ($t) => str_contains((string) $t, 'sent back'))->count())->toBe(1);

    $this->goals->submitPlan($this->employee, $this->cycle->id, $this->employee->user);
    $again = GoalPlan::query()->where('status', 'pending')->sole();
    app(ApprovalDecisions::class)->decide($this->manager->user, 'goal_plan:'.$again->id, 'approve');
    expect($again->refresh()->status)->toBe('approved')
        ->and(Goal::query()->where('employee_id', $this->employee->id)->pluck('status')->unique()->all())->toBe(['active'])
        ->and(fn () => $again->update(['status' => 'rejected']))->toThrow(RuntimeException::class, 'already decided');
});

it('decides a goal sheet through a workflow on performance.goal_plan.submitted when the policy says so', function () {
    gspPolicy(['plan_approval' => 'workflow']);
    [$nodes, $edges] = linear([['id' => 'a', 'type' => 'approval', 'name' => 'Manager', 'config' => ['mode' => 'single', 'approver' => ['type' => 'user', 'user_id' => $this->manager->user->id]]]]);
    publishWorkflow($nodes, $edges, ['name' => 'Goal sheet approval', 'key' => 'goal_sheet', 'trigger_event' => 'performance.goal_plan.submitted']);
    ($this->goal)(['title' => 'Revenue', 'weight' => 100]);

    $this->goals->submitPlan($this->employee, $this->cycle->id, $this->employee->user);
    $plan = GoalPlan::query()->sole();
    expect(app(ApprovalCenter::class)->pending($this->manager->user)->pluck('id'))->not->toContain('goal_plan:'.$plan->id);

    $task = WorkflowTask::query()->whereIn('workflow_instance_id', WorkflowInstance::query()->where('subject_type', $plan->getMorphClass())->where('subject_id', $plan->id)->pluck('id'))->where('status', 'pending')->sole();
    app(WorkflowEngine::class)->completeTask($task, 'approved', 'Looks right', $this->manager->user);
    expect($plan->refresh()->status)->toBe('approved')->and(Goal::query()->where('employee_id', $this->employee->id)->value('status'))->toBe('active');
});

it('checks a goal sheet policy before it is saved or published, and offers its masters as options', function () {
    [$one, $two] = Competency::query()->orderBy('id')->limit(2)->get()->all();
    $one->update(['weight' => 40]);
    $policy = Policy::create(['type' => 'goal_sheet', 'name' => 'Strict', 'code' => 'STRICT']);
    app(Policies::class)->draft($policy, ['min_goals' => 5, 'max_goals' => 2]);
    expect(fn () => app(Policies::class)->publish($policy))->toThrow(ConfigurationException::class, 'minimum number of KRAs is above the maximum');

    app(Policies::class)->draft($policy, ['competency_codes' => [$one->code], 'min_competencies' => 2]);
    expect(fn () => app(Policies::class)->publish($policy))->toThrow(ConfigurationException::class, 'at least 2 competencies');
    app(Policies::class)->draft($policy, ['competency_codes' => [$one->code], 'max_competency_weight' => 30]);
    expect(fn () => app(Policies::class)->publish($policy))->toThrow(ConfigurationException::class, 'outside the single competency weightage limits');

    // The wizard checks the same answers before saving the draft.
    $state = app(PolicySetup::class)->load($policy);
    $state['settings'] = ['total_goal_weight' => 99.5];
    expect(fn () => app(PolicySetup::class)->save($state, $policy, $this->hr, false, 'Try'))->toThrow(ConfigurationException::class, 'whole number');

    $policyOptions = fn (string $source) => (new ReflectionMethod(PolicySettingsSchema::class, 'options'))->invoke(null, ['options_from' => $source]);
    expect($policyOptions('competencies'))->toHaveKey($one->code)
        ->and(app(SetupCatalogue::class)->optionsFor(['type' => 'multiselect', 'options_from' => 'competencies']))->toHaveKey($two->code)
        ->and(array_column(PolicySettingsSchema::steps('goal_sheet'), 'label'))->toBe(['Goal creation', 'KRA weightage', 'KPI weightage', 'Competencies', 'Goal creation configuration', 'Performance review configuration', 'Performance scorecard configuration', 'Other setting'])
        ->and(config('peopleos_setup.modules.performance.policy_types'))->toContain('goal_sheet', 'pip');
    Livewire::withQueryParams(['type' => 'goal_sheet'])->test(PolicyWizard::class)->assertOk();
});

it('duplicates a policy with its settings as a new draft that applies to nobody', function () {
    $source = gspPolicy(['goal_weight_mode' => 'equal', 'max_goals' => 6]);
    $copy = app(Policies::class)->duplicate($source, 'Sales goal sheet', 'SALES_GS', 'Same rules for sales', $this->hr);

    expect($copy->type)->toBe('goal_sheet')->and($copy->assignmentRules()->count())->toBe(0)
        ->and($copy->draft()->sole()->settings)->toBe(['goal_weight_mode' => 'equal', 'max_goals' => 6])
        ->and(fn () => app(Policies::class)->duplicate($source, 'Again', 'SALES_GS', 'x', $this->hr))->toThrow(ConfigurationException::class, 'already uses the code SALES_GS')
        ->and(fn () => app(Policies::class)->duplicate($source, 'Mine', 'MINE', 'x', tenantUser($this->tenant, ['policy.view'])))->toThrow(ConfigurationException::class, 'cannot create policies');

    Livewire::test(ListPolicies::class)->callTableAction('duplicate', $source, ['name' => 'Ops goal sheet', 'code' => 'OPS_GS', 'audit_reason' => 'Ops copy'])->assertHasNoTableActionErrors();
    expect(Policy::query()->where('code', 'OPS_GS')->sole()->draft()->sole()->settings)->toBe(['goal_weight_mode' => 'equal', 'max_goals' => 6]);
});
