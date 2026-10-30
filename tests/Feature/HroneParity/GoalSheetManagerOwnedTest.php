<?php

use App\Domain\Configuration\Models\Policy;
use App\Domain\Configuration\Models\PolicyAssignmentRule;
use App\Domain\Configuration\Services\Policies;
use App\Domain\Experience\Services\ApprovalCenter;
use App\Domain\Experience\Services\ApprovalDecisions;
use App\Domain\Performance\Models\Goal;
use App\Domain\Performance\Models\GoalPlan;
use App\Domain\Performance\Models\GoalTemplate;
use App\Domain\Performance\Services\Appraisals;
use App\Domain\Performance\Services\GoalPlans;
use App\Domain\Performance\Services\Goals;
use App\Domain\Performance\Services\GoalSheetRules;
use App\Domain\Workflow\Models\WorkflowInstance;
use App\Filament\Resources\Goals\Pages\CreateGoal;
use App\Filament\Resources\Goals\Pages\ListGoals;
use Livewire\Livewire;

require_once __DIR__.'/../Workflow/WorkflowTestHelpers.php';
require_once __DIR__.'/../Performance/PerformanceTestHelpers.php';

/* Owner decision 2026-10-29 (HP-8d, goal sheet system mapping): the reporting manager owns the final mapped goal sheet.
 * The employee's additions and changes are proposals that wait in `pending_approval` on the sheet's goal plan until the
 * manager confirms them (Approval Center or the goal sheet workflow); the manager changes the sheet directly and confirms
 * it; the employee never finalises it alone. Manual goal sheets behave as before. */

beforeEach(function () {
    $this->travelTo('2026-09-21 09:00:00');
    $this->tenant = provisionTenant();
    actAsTenant($this->tenant);
    $this->hr = tenantUser($this->tenant, ['*']);
    $this->actingAs($this->hr);
    $this->manager = activeEmployee(null, ['performance.team', 'performance.review', 'performance.goals', 'task.view', 'task.act', 'feedback.view']);
    $this->employee = activeEmployee($this->manager);
    $this->goals = app(Goals::class);
    $template = GoalTemplate::create(['code' => 'sales', 'name' => 'Sales goal sheet']);
    $template->items()->create(['title' => 'Revenue', 'weight' => 60, 'target_value' => 100]);
    $template->items()->create(['title' => 'Pipeline hygiene', 'weight' => 40]);
});

function gsmPolicy(array $settings): void
{
    $policy = Policy::create(['type' => 'goal_sheet', 'name' => 'Goal sheets '.uniqid(), 'code' => 'GSM_'.strtoupper(uniqid())]);
    app(Policies::class)->draft($policy, $settings);
    app(Policies::class)->publish($policy, '2020-01-01');
    PolicyAssignmentRule::create(['policy_type' => 'goal_sheet', 'policy_id' => $policy->id, 'name' => 'All', 'conditions' => []]);
}

/** @return list<string> the statuses on the employee's goal sheet of the cycle */
function gsmStatuses(int $employeeId, int $cycleId): array
{
    return Goal::query()->where('employee_id', $employeeId)->where('performance_cycle_id', $cycleId)->orderBy('id')->pluck('status')->unique()->values()->all();
}

it('keeps manual goal sheets as before: the employee creates, changes, submits and closes their own goals', function () {
    gsmPolicy(['goal_creation' => 'manual']);
    $cycle = app(Appraisals::class)->launch(draftCycle());
    $this->actingAs($this->employee->user);
    $goal = $this->goals->create(['employee_id' => $this->employee->id, 'performance_cycle_id' => $cycle->id, 'title' => 'Own goal', 'weight' => 50], [], $this->employee->user);
    $draft = $this->goals->create(['employee_id' => $this->employee->id, 'performance_cycle_id' => $cycle->id, 'title' => 'Second goal', 'weight' => 50, 'status' => 'draft'], [], $this->employee->user);
    expect($goal->status)->toBe('active')->and($this->goals->proposesFor($goal, $this->employee->user))->toBeFalse()
        ->and($this->goals->submitPlan($this->employee, $cycle->id, $this->employee->user))->toBe(1)
        ->and($draft->refresh()->status)->toBe('active')
        ->and($this->goals->update($goal, ['title' => 'Own goal, renamed'], null, $this->employee->user)->status)->toBe('active')
        ->and($this->goals->close($goal->refresh(), 'completed')->status)->toBe('completed')
        ->and(GoalPlan::query()->count())->toBe(0);
});

it('makes the employee\'s additions and changes to a mapped goal sheet proposals that the reporting manager changes and confirms', function () {
    gsmPolicy(['goal_creation' => 'system', 'goal_template_code' => 'SALES', 'total_goal_weight' => 100]);
    $cycle = app(Appraisals::class)->launch(draftCycle());
    $revenue = Goal::query()->where('employee_id', $this->employee->id)->where('title', 'Revenue')->sole();
    $pipeline = Goal::query()->where('employee_id', $this->employee->id)->where('title', 'Pipeline hygiene')->sole();
    expect(gsmStatuses($this->employee->id, $cycle->id))->toBe(['draft']);

    // The employee's change sends the mapped sheet to the reporting manager (the policy itself asks for no approval).
    $this->actingAs($this->employee->user);
    $this->goals->update($revenue, ['weight' => 50], null, $this->employee->user);
    $plan = GoalPlan::query()->sole();
    expect($plan->only(['status', 'approval', 'goals']))->toBe(['status' => 'pending', 'approval' => 'manager', 'goals' => 2])
        ->and(gsmStatuses($this->employee->id, $cycle->id))->toBe(['pending_approval'])
        ->and($this->manager->user->notifications()->get()->filter(fn ($n) => str_contains((string) data_get($n->data, 'title'), 'waits for approval')))->toHaveCount(1);

    // An addition joins the same plan as a proposal (whatever status was asked for); the employee cannot finalise alone.
    $added = $this->goals->create(['employee_id' => $this->employee->id, 'performance_cycle_id' => $cycle->id, 'title' => 'Key accounts', 'weight' => 10, 'status' => 'active'], [], $this->employee->user);
    expect($added->status)->toBe('pending_approval')->and(GoalPlan::query()->count())->toBe(1)->and($plan->refresh()->goals)->toBe(3)
        ->and(fn () => $this->goals->update($revenue->refresh(), ['status' => 'completed'], null, $this->employee->user))->toThrow(RuntimeException::class, Goals::MANAGER_OWNED)
        ->and(fn () => $this->goals->create(['employee_id' => $this->employee->id, 'performance_cycle_id' => $cycle->id, 'title' => 'Done already', 'status' => 'completed'], [], $this->employee->user))->toThrow(RuntimeException::class, Goals::MANAGER_OWNED)
        ->and(fn () => app(GoalPlans::class)->approve($plan, null, $this->employee->user))->toThrow(RuntimeException::class, 'You cannot decide this goal sheet.')
        ->and(app(ApprovalCenter::class)->pending($this->employee->user)->pluck('id'))->not->toContain('goal_plan:'.$plan->id);
    $this->goals->update($revenue->refresh(), ['status' => 'active', 'title' => 'Revenue (new logos)'], null, $this->employee->user);
    $this->goals->update($pipeline, ['weight' => 30], null, $this->employee->user);
    expect($revenue->refresh()->only(['status', 'title']))->toBe(['status' => 'pending_approval', 'title' => 'Revenue (new logos)']);

    // The manager's version is authoritative: the sheet must add up before it is confirmed; the manager changes it directly.
    $this->actingAs($this->manager->user);
    expect(fn () => app(GoalPlans::class)->approve($plan->refresh(), null, $this->manager->user))->toThrow(RuntimeException::class, 'adds up to 90');
    $this->goals->update($pipeline->refresh(), ['weight' => 40], null, $this->manager->user);
    expect($pipeline->refresh()->status)->toBe('pending_approval')->and(GoalPlan::query()->count())->toBe(1);
    app(ApprovalDecisions::class)->decide($this->manager->user, 'goal_plan:'.$plan->id, 'approve', 'Aligned with the sales plan');
    expect(gsmStatuses($this->employee->id, $cycle->id))->toBe(['active'])->and($plan->refresh()->status)->toBe('approved');

    // A later change by the employee is a new proposal; the manager may send it back (the goal returns to draft).
    $this->actingAs($this->employee->user);
    expect(fn () => $this->goals->close($pipeline->refresh(), 'cancelled', 'Not mine'))->toThrow(RuntimeException::class, Goals::MANAGER_OWNED);
    $this->goals->update($revenue->refresh(), ['description' => 'Focus on renewals'], null, $this->employee->user);
    $this->goals->update($added->refresh(), [], null, $this->employee->user, keyResultsChanged: true); // KPI rows changed on the form
    $second = GoalPlan::query()->where('status', 'pending')->sole();
    expect($revenue->refresh()->status)->toBe('pending_approval')->and($added->refresh()->status)->toBe('pending_approval')
        ->and($pipeline->refresh()->status)->toBe('active');
    app(GoalPlans::class)->reject($second, 'Keep the description as agreed', $this->manager->user);
    expect($revenue->refresh()->status)->toBe('draft')->and($pipeline->refresh()->status)->toBe('active');
    // Check-ins stay the employee's.
    expect($this->goals->checkIn($pipeline->refresh(), 20.0, 'First week', null, $this->employee->user)->value)->toEqual(20);
});

it('always sends the employee\'s submitted mapped sheet to the manager, while the manager\'s own submission activates it', function () {
    $second = activeEmployee($this->manager);
    gsmPolicy(['goal_creation' => 'system', 'goal_template_code' => 'SALES', 'goal_sheet_owner' => 'manager']);
    $cycle = app(Appraisals::class)->launch(draftCycle());
    expect($this->manager->user->notifications()->get()->filter(fn ($n) => str_contains((string) data_get($n->data, 'title'), 'Goal sheet ready')))->toHaveCount(2);

    $this->actingAs($this->employee->user);
    expect($this->goals->submitPlan($this->employee, $cycle->id, $this->employee->user))->toBe(2)
        ->and(gsmStatuses($this->employee->id, $cycle->id))->toBe(['pending_approval'])
        ->and(GoalPlan::query()->sole()->approval)->toBe('manager')
        ->and(fn () => $this->goals->submitPlan($this->employee, $cycle->id, $this->employee->user))->toThrow(RuntimeException::class, 'already waiting for approval');

    // The manager fills in and submits the other sheet: with no approval in the policy it is final at once.
    $this->actingAs($this->manager->user);
    expect($this->goals->submitPlan($second, $cycle->id, $this->manager->user))->toBe(2)
        ->and(gsmStatuses($second->id, $cycle->id))->toBe(['active'])->and(GoalPlan::query()->count())->toBe(1);
    app(GoalPlans::class)->approve(GoalPlan::query()->sole(), null, $this->manager->user);
    expect(gsmStatuses($this->employee->id, $cycle->id))->toBe(['active']);
});

it('routes the employee\'s proposals to the goal sheet workflow when the policy asks for one', function () {
    publishWorkflow(...[...linear([['id' => 'a', 'type' => 'approval', 'name' => 'Manager', 'config' => ['mode' => 'single', 'approver' => ['type' => 'user', 'user_id' => $this->manager->user->id]]]]), ['trigger_event' => 'performance.goal_plan.submitted']]);
    gsmPolicy(['goal_creation' => 'system', 'goal_template_code' => 'SALES', 'plan_approval' => 'workflow']);
    $cycle = app(Appraisals::class)->launch(draftCycle());
    $this->actingAs($this->employee->user);
    $this->goals->update(Goal::query()->where('employee_id', $this->employee->id)->where('title', 'Revenue')->sole(), ['description' => 'New logos first'], null, $this->employee->user);
    $plan = GoalPlan::query()->sole();
    expect($plan->approval)->toBe('workflow')
        ->and(WorkflowInstance::query()->where('subject_type', $plan->getMorphClass())->where('subject_id', $plan->id)->count())->toBe(1);
});

it('reads older answers of who fills the mapped sheet safely and asks the question as the decision puts it', function () {
    gsmPolicy(['goal_creation' => 'system', 'goal_sheet_owner' => 'hr']);
    $rules = app(GoalSheetRules::class);
    $field = collect(config('peopleos.policies.types.goal_sheet.fields'))->flatMap(fn (array $f) => $f['fields'] ?? [$f])->firstWhere('key', 'goal_sheet_owner');
    expect($rules->creation($this->employee)['owner'])->toBe('employee')
        ->and($rules->managerOwned($this->employee, 5))->toBeTrue()
        ->and($rules->managerOwned($this->employee, null))->toBeFalse() // goals outside a cycle are not on a goal sheet
        ->and($field['label'])->toContain('fills in the mapped goal sheet first')
        ->and($field['options'])->toBe(['employee' => 'The employee', 'manager' => 'The reporting manager']);

    // Outside a cycle the employee's own goals stay theirs.
    $free = $this->goals->create(['employee_id' => $this->employee->id, 'title' => 'Learn Spanish'], [], $this->employee->user);
    expect($free->status)->toBe('active');
});

it('offers the employee the goal screens as proposals: the status is the plan\'s and closing is the manager\'s', function () {
    gsmPolicy(['goal_creation' => 'system', 'goal_template_code' => 'SALES']);
    $cycle = app(Appraisals::class)->launch(draftCycle());
    $mapped = Goal::query()->where('employee_id', $this->employee->id)->where('title', 'Revenue')->sole();

    $this->actingAs($this->employee->user);
    Livewire::test(CreateGoal::class)
        ->fillForm(['level' => 'employee', 'employee_id' => $this->employee->id, 'performance_cycle_id' => $cycle->id, 'type' => 'goal', 'title' => 'Learning', 'weight' => 0, 'measure_type' => 'percentage', 'target_value' => 100])
        ->assertFormFieldIsDisabled('status')
        ->call('create')->assertHasNoFormErrors();
    expect(Goal::query()->where('title', 'Learning')->value('status'))->toBe('pending_approval')
        ->and($mapped->refresh()->status)->toBe('pending_approval'); // the addition sent the whole mapped sheet
    app(GoalPlans::class)->approve(GoalPlan::query()->sole(), null, $this->manager->user);
    Livewire::test(ListGoals::class)->assertTableActionHidden('close', $mapped->refresh());

    $this->actingAs($this->manager->user);
    Livewire::test(ListGoals::class)->assertTableActionVisible('close', $mapped);
});
