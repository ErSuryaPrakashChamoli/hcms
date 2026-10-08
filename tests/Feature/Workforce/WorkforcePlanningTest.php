<?php

use App\Domain\Employment\Actions\AssignPositionAction;
use App\Domain\Employment\Models\EmployeePosition;
use App\Domain\Exit\Models\ExitCase;
use App\Domain\Organisation\Models\Designation;
use App\Domain\Payroll\Models\PayrollEntry;
use App\Domain\Payroll\Models\PayrollPeriod;
use App\Domain\Payroll\Models\PayrollRun;
use App\Domain\Workforce\Models\Position;
use App\Domain\Workforce\Models\PositionVersion;
use App\Domain\Workforce\Models\WorkforcePlanVersion;
use App\Domain\Workforce\Services\Positions;
use App\Domain\Workforce\Services\WorkforceBudgets;
use App\Domain\Workforce\Services\WorkforceForecast;
use App\Domain\Workforce\Services\WorkforcePlans;
use App\Domain\Workforce\Services\WorkforceScenarios;
use App\Domain\Workforce\Services\WorkforceSnapshot;

require_once __DIR__.'/../Workflow/WorkflowTestHelpers.php';
require_once __DIR__.'/../Performance/PerformanceTestHelpers.php';
require_once __DIR__.'/WorkforceTestHelpers.php';

beforeEach(function () {
    $this->travelTo('2026-10-05 09:00:00');
    $this->tenant = provisionTenant();
    actAsTenant($this->tenant);
    $this->admin = tenantUser($this->tenant, ['*']);
    $this->actingAs($this->admin);
    $this->planner = tenantUser($this->tenant, ['workforce.view', 'workforce.plan', 'workforce.manage', 'workforce.costs']);
    $this->reviewer = tenantUser($this->tenant, ['workforce.view', 'workforce.review']);
    $this->approver = tenantUser($this->tenant, ['workforce.view', 'workforce.approve', 'workforce.costs', 'workforce.manage']);
    $this->org = workforceOrg();
    $this->plans = app(WorkforcePlans::class);
    $this->scenarios = app(WorkforceScenarios::class);
    $this->newPlan = fn (array $overrides = []) => $this->plans->create([
        'code' => 'FY27-ENG', 'name' => 'Engineering FY27', 'organisation_node_id' => $this->org['department']->id,
        'period_type' => 'annual', 'period_start' => '2027-04-01', 'period_end' => '2028-03-31', ...$overrides,
    ], $this->planner);
});

it('keeps scenarios configurable, with labelled assumptions, approved by a second person and then locked', function () {
    $growth = $this->scenarios->create('growth', 'Growth 2027', 'Two new squads', ['attrition_rate_percent' => 8], $this->planner);
    expect($growth->code)->toBe('GROWTH')
        ->and(fn () => $this->scenarios->create('x', 'X', null, ['flight_risk' => 1], $this->planner))->toThrow(RuntimeException::class, 'Unknown planning assumption')
        ->and(fn () => $this->scenarios->create('y', 'Y', null, ['attrition_rate_percent' => 140], $this->planner))->toThrow(RuntimeException::class, 'between 0 and 100')
        ->and(fn () => $this->scenarios->approve($growth, $this->planner))->toThrow(RuntimeException::class);

    $this->scenarios->approve($growth, $this->approver);
    expect($growth->refresh()->status)->toBe('approved')
        ->and(fn () => $this->scenarios->update($growth, ['assumptions' => ['attrition_rate_percent' => 2]], $this->planner))->toThrow(RuntimeException::class, 'locked')
        ->and(fn () => $growth->delete())->toThrow(RuntimeException::class, 'never deleted');
});

it('moves a plan version through submission, review and approval by different people and keeps it locked', function () {
    $plan = ($this->newPlan)();
    $v1 = $plan->versions()->first();
    expect($v1->currency)->toBe($this->org['company']->currency ?? 'INR')
        ->and($plan->department_id)->toBe($this->org['department']->nodeable_id)
        ->and(fn () => $this->plans->submit($v1, $this->planner))->toThrow(RuntimeException::class, 'at least one line')
        ->and(fn () => $this->plans->addLine($v1, ['movement_type' => 'new_position', 'headcount' => 2, 'effective_date' => '2026-01-01'], $this->planner))->toThrow(RuntimeException::class, 'inside the planning period');

    $this->plans->addLine($v1, ['movement_type' => 'new_position', 'headcount' => 3, 'fte' => 3, 'effective_date' => '2027-06-01', 'planned_cost' => 4500000, 'cost_basis' => 'annual_salary'], $this->planner);
    $this->plans->addLine($v1, ['movement_type' => 'known_exit', 'headcount' => 1, 'fte' => 1, 'effective_date' => '2027-09-01'], $this->planner);
    expect(fn () => $this->plans->addLine($v1, ['movement_type' => 'new_position', 'headcount' => 1, 'planned_cost' => 10, 'effective_date' => '2027-06-01'], $this->planner))->toThrow(RuntimeException::class, 'cost basis')
        ->and($this->plans->totals($v1, $this->planner))->toMatchArray(['headcount' => 2, 'fte' => 2.0, 'cost_by_basis' => ['annual_salary' => 4500000.0]])
        ->and($this->plans->totals($v1, $this->reviewer)['cost_by_basis'])->toBeNull();

    $this->plans->submit($v1, $this->planner);
    expect(fn () => $v1->refresh()->update(['period_end' => '2028-06-30']))->toThrow(RuntimeException::class, 'locked')
        ->and(fn () => $this->plans->addLine($v1, ['movement_type' => 'expansion', 'headcount' => 1, 'effective_date' => '2027-06-01'], $this->planner))->toThrow(RuntimeException::class, 'draft')
        ->and(fn () => $this->plans->startReview($v1, $this->planner))->toThrow(RuntimeException::class);

    $this->plans->startReview($v1->refresh(), $this->reviewer);
    expect(fn () => $this->plans->approve($v1->refresh(), null, $this->reviewer))->toThrow(RuntimeException::class, 'workforce.approve');
    $this->plans->approve($v1->refresh(), 'Agreed with finance', $this->approver);
    expect($v1->refresh()->status)->toBe('approved')->and($v1->checksum)->toHaveLength(64)->and($v1->approved_by)->toBe($this->approver->id);
});

it('stops the submitter from approving through a second role and publishes one active version per plan', function () {
    $both = tenantUser($this->tenant, ['workforce.view', 'workforce.plan', 'workforce.review', 'workforce.approve']);
    $plan = $this->plans->create(['code' => 'SOD', 'name' => 'SOD plan', 'company_id' => $this->org['company']->id, 'period_type' => 'quarterly', 'period_start' => '2027-01-01', 'period_end' => '2027-03-31'], $both);
    $v1 = $plan->versions()->first();
    $this->plans->addLine($v1, ['movement_type' => 'expansion', 'headcount' => 1, 'effective_date' => '2027-02-01'], $both);
    $this->plans->submit($v1, $both);
    expect(fn () => $this->plans->startReview($v1->refresh(), $both))->toThrow(RuntimeException::class, 'submitted a plan version cannot review');

    $this->plans->startReview($v1->refresh(), $this->reviewer);
    expect(fn () => $this->plans->approve($v1->refresh(), null, $both))->toThrow(RuntimeException::class, 'cannot approve');
    $this->plans->approve($v1->refresh(), null, $this->approver);
    $this->plans->publish($v1->refresh(), '2027-01-01', $this->approver);

    // A correction is a new version copied from v1; publishing it supersedes v1.
    $v2 = $this->plans->createVersion($plan->refresh(), [], $both, $v1->refresh());
    expect($v2->version)->toBe(2)->and($v2->lines()->count())->toBe(1);
    $this->plans->submit($v2, $both);
    $this->plans->startReview($v2->refresh(), $this->reviewer);
    $this->plans->approve($v2->refresh(), null, $this->approver);
    $this->plans->publish($v2->refresh(), '2027-02-01', $this->approver);

    expect($v1->refresh()->status)->toBe('superseded')->and($v2->refresh()->status)->toBe('active')
        ->and($plan->refresh()->active_version_id)->toBe($v2->id)
        ->and(WorkforcePlanVersion::query()->where('workforce_plan_id', $plan->id)->where('status', 'active')->count())->toBe(1);
});

it('never changes live positions or employees from a plan or scenario; proposing a position is explicit and still needs approval', function () {
    $plan = ($this->newPlan)();
    $v1 = $plan->versions()->first();
    $designation = Designation::query()->create(['name' => 'Data Engineer', 'code' => 'DE']);
    $line = $this->plans->addLine($v1, ['movement_type' => 'new_position', 'headcount' => 2, 'fte' => 2, 'designation_id' => $designation->id, 'effective_date' => '2027-05-01'], $this->planner);
    $before = [Position::query()->count(), PositionVersion::query()->count(), EmployeePosition::query()->count()];

    $this->plans->submit($v1, $this->planner);
    $this->plans->startReview($v1->refresh(), $this->reviewer);
    $this->plans->approve($v1->refresh(), null, $this->approver);
    $this->plans->publish($v1->refresh(), null, $this->approver);
    expect([Position::query()->count(), PositionVersion::query()->count(), EmployeePosition::query()->count()])->toBe($before);

    $position = $this->plans->proposePositionFromLine($line->refresh(), 'DE-01', $this->planner);
    expect($position->status)->toBe('proposed')->and($position->source_plan_line_id)->toBe($line->id)
        ->and($position->currentVersion->headcount)->toBe(2)->and($position->currentVersion->effective_from->toDateString())->toBe('2027-05-01')
        ->and(fn () => $this->plans->proposePositionFromLine($line->refresh(), 'DE-02', $this->planner))->toThrow(RuntimeException::class, 'already proposed')
        ->and(fn () => app(Positions::class)->transition($position, 'approved', null, $this->planner))->toThrow(RuntimeException::class);
});

it('reconstructs headcount, vacancy and FTE on any date from effective-dated records', function () {
    $creator = tenantUser($this->tenant, ['workforce.manage']);
    $a = openPosition(['code' => 'A-1', 'title' => 'Analyst', 'organisation_node_id' => $this->org['department']->id], $creator, $this->approver, '2026-01-01');
    $b = openPosition(['code' => 'B-1', 'title' => 'Analyst team', 'organisation_node_id' => $this->org['department']->id, 'occupancy_mode' => 'multiple', 'headcount' => 3, 'fte' => 1, 'fte_capacity' => 2.5], $creator, $this->approver, '2026-01-01');
    $employee = activeEmployee();
    app(AssignPositionAction::class)->handle($employee, ['position_id' => $a->id], 'transfer', '2026-03-01', 'Seat');
    app(AssignPositionAction::class)->handle(activeEmployee(), ['position_id' => $b->id, 'fte' => 0.5], 'transfer', '2026-03-01', 'Seat');
    app(Positions::class)->transition($b, 'frozen', 'Hiring freeze', $creator, '2026-09-01');

    $snapshot = app(WorkforceSnapshot::class);
    $march = $snapshot->headcount('2026-03-31');
    $october = $snapshot->headcount('2026-10-01');
    $before = $snapshot->headcount('2025-12-31');

    expect($march)->toMatchArray(['positions' => 2, 'approved_seats' => 4, 'approved_fte' => 3.5, 'open_seats' => 4, 'occupied_seats' => 2, 'occupied_fte' => 1.5, 'vacant_seats' => 2, 'frozen_seats' => 0])
        ->and($october)->toMatchArray(['approved_seats' => 4, 'open_seats' => 1, 'frozen_seats' => 3, 'vacant_seats' => 0, 'occupied_seats' => 2])
        ->and($before['positions'])->toBe(0)
        ->and($october['employees'])->toBeGreaterThanOrEqual(2)   // people, counted separately from seats
        ->and($snapshot->vacancies('2026-03-31')->pluck('position_versions.position_id')->all())->toBe([$b->id])
        ->and($snapshot->by('department_id', '2026-03-31')[0])->toMatchArray(['seats' => 4, 'occupied_seats' => 2]);
});

it('compares budget with planned and actual employer cost on the same basis and suppresses small populations', function () {
    $budgets = app(WorkforceBudgets::class);
    $plan = ($this->newPlan)(['period_start' => '2026-04-01', 'period_end' => '2027-03-31', 'code' => 'FY26']);
    $version = $plan->versions()->first();
    $this->plans->addLine($version, ['movement_type' => 'baseline', 'headcount' => 2, 'fte' => 2, 'planned_cost' => 100000, 'cost_basis' => 'employer_cost', 'effective_date' => '2026-04-01'], $this->planner);
    expect(fn () => $budgets->create(['name' => 'X', 'company_id' => $this->org['company']->id, 'period_start' => '2026-04-01', 'period_end' => '2027-03-31', 'cost_basis' => 'employer_cost', 'amount' => 1], $this->reviewer))->toThrow(RuntimeException::class, 'workforce.plan');

    $budget = $budgets->create(['name' => 'Engineering FY26', 'workforce_plan_version_id' => $version->id, 'organisation_node_id' => $this->org['department']->id, 'period_start' => '2026-04-01', 'period_end' => '2027-03-31', 'cost_basis' => 'employer_cost', 'amount' => 150000], $this->planner);
    expect(fn () => $budgets->approve($budget, $this->planner))->toThrow(RuntimeException::class);
    $budgets->approve($budget, $this->approver);
    expect(fn () => $budget->refresh()->update(['amount' => 1]))->toThrow(RuntimeException::class, 'locked');

    // Six employees in the department with finalized payroll employer cost of 20,000 each in May.
    $employees = collect(range(1, 6))->map(fn () => activeEmployee());
    $employees->each(fn ($e) => app(AssignPositionAction::class)->handle($e, ['department_id' => $this->org['department']->nodeable_id], 'transfer', '2026-04-01', 'Dept'));
    $period = PayrollPeriod::query()->create(['company_id' => $this->org['company']->id, 'year' => 2026, 'month' => 5, 'start_date' => '2026-05-01', 'end_date' => '2026-05-31', 'payment_date' => '2026-05-31', 'status' => 'closed']);
    $run = PayrollRun::query()->create(['company_id' => $this->org['company']->id, 'payroll_period_id' => $period->id, 'status' => 'finalized', 'totals' => []]);
    $employees->each(fn ($e) => PayrollEntry::query()->create(['payroll_run_id' => $run->id, 'employee_id' => $e->id, 'days_in_period' => 31, 'paid_days' => 31, 'lop_days' => 0, 'gross' => 18000, 'total_earnings' => 18000, 'total_deductions' => 0, 'net_pay' => 18000, 'employer_cost' => 20000, 'taxable_earnings' => 18000, 'status' => 'calculated']));

    $comparison = $budgets->comparison($budget->refresh(), $this->approver);
    expect($comparison)->toMatchArray(['budget' => 150000.0, 'planned' => 100000.0, 'actual' => 120000.0, 'budget_vs_planned' => 50000.0, 'budget_vs_actual' => 30000.0])
        ->and(fn () => $budgets->comparison($budget, $this->reviewer))->toThrow(RuntimeException::class, 'workforce.costs');

    config(['peopleos.workforce.analytics_min_group' => 10]);
    expect($budgets->comparison($budget, $this->approver))->toMatchArray(['actual' => null])->and($budgets->comparison($budget, $this->approver)['note'])->toContain('suppressed');

    $annual = $budgets->create(['name' => 'Salaries', 'company_id' => $this->org['company']->id, 'period_start' => '2026-04-01', 'period_end' => '2027-03-31', 'cost_basis' => 'annual_salary', 'amount' => 500000], $this->planner);
    expect($budgets->comparison($annual, $this->approver))->toMatchArray(['actual' => null])->and($budgets->comparison($annual, $this->approver)['note'])->toContain('another basis');
});

it('forecasts from facts and shows the attrition assumption separately, labelled as an assumption', function () {
    $creator = tenantUser($this->tenant, ['workforce.manage']);
    $scenario = $this->scenarios->create('base', 'Baseline', null, ['attrition_rate_percent' => 12], $this->planner);
    $positions = collect(range(1, 4))->map(fn ($i) => openPosition(['code' => "OP-{$i}", 'title' => 'Operator', 'organisation_node_id' => $this->org['department']->id], $creator, $this->approver, '2026-01-01'));
    $people = $positions->map(fn ($p) => tap(activeEmployee(), fn ($e) => app(AssignPositionAction::class)->handle($e, ['position_id' => $p->id], 'transfer', '2026-02-01', 'Seat')));
    ExitCase::create(['number' => 'EXIT-2026-00001', 'employee_id' => $people[0]->id, 'type' => 'resignation', 'status' => 'notice', 'initiated_on' => '2026-10-01', 'last_working_day' => '2026-11-15']);

    $plan = ($this->newPlan)(['code' => 'Q4', 'period_type' => 'quarterly', 'period_start' => '2026-10-01', 'period_end' => '2026-12-31', 'workforce_scenario_id' => $scenario->id]);
    $version = $plan->versions()->first();
    $this->plans->addLine($version, ['movement_type' => 'new_position', 'headcount' => 2, 'fte' => 2, 'effective_date' => '2026-12-01'], $this->planner);

    $forecast = app(WorkforceForecast::class)->forPlan($version->refresh());
    expect($forecast['basis'])->toMatchArray(['approved_seats' => 4, 'occupied_seats' => 4, 'attrition_assumption_percent' => 12])
        ->and($forecast['basis']['assumption_label'])->toContain('Planning assumption')->toContain('not a prediction')
        ->and(collect($forecast['months'])->pluck('month')->all())->toBe(['2026-10', '2026-11', '2026-12'])
        ->and($forecast['months'][1])->toMatchArray(['recorded_exits' => 1, 'occupied_after_recorded_exits' => 3, 'assumed_attrition' => 0.0 + round(4 * 0.12 / 12, 1)])
        ->and($forecast['months'][2])->toMatchArray(['planned_change' => 2, 'planned_seats' => 6])
        ->and(json_encode($forecast))->not->toContain('flight')->not->toContain($people[0]->employee_code);
});
