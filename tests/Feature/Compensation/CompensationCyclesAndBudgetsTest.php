<?php

use App\Domain\Audit\Models\AuditEvent;
use App\Domain\Compensation\Models\CompensationChange;
use App\Domain\Compensation\Models\EmployeeSalaryAssignment;
use App\Domain\Compensation\Models\SalaryStructure;
use App\Domain\Compensation\Services\CompensationBudgets;
use App\Domain\Compensation\Services\CompensationChanges;
use App\Domain\Compensation\Services\CompensationCycles;
use App\Domain\Compensation\Services\CompensationPlanning;
use App\Domain\Compensation\Services\CompensationRanges;
use App\Domain\Employment\Models\EmployeePosition;
use App\Domain\Identity\Scopes\AccessScope;
use App\Domain\Lifecycle\Enums\LifecycleState;
use App\Domain\Organisation\Models\Grade;
use App\Domain\Performance\Contracts\PerformanceOutcomesReader;
use App\Domain\Workforce\Models\WorkforcePlanLine;
use App\Domain\Workforce\Services\WorkforcePlans;

require_once __DIR__.'/../Workflow/WorkflowTestHelpers.php';
require_once __DIR__.'/../Performance/PerformanceTestHelpers.php';
require_once __DIR__.'/../Payroll/PayrollTestHelpers.php';
require_once __DIR__.'/../Workforce/WorkforceTestHelpers.php';

/* Phase 11.3: compensation budgets (§16), bulk cycles (§15), workforce planning (§9, §17) and the performance input (§26). */

beforeEach(function () {
    $this->travelTo('2026-09-21 09:00:00');
    syncComplianceRules();
    $this->tenant = provisionTenant();
    actAsTenant($this->tenant);
    $this->hr = tenantUser($this->tenant, ['*']);
    $this->actingAs($this->hr);
    $this->company = payrollCompany();
    $this->actors = compensationActors();
    $this->changes = app(CompensationChanges::class);
    $this->budgets = app(CompensationBudgets::class);
    $this->cycles = app(CompensationCycles::class);
    $this->structure = SalaryStructure::query()->where('code', 'STANDARD')->firstOrFail();
    $this->planner = tenantUser($this->tenant, ['compensation.budget', 'compensation.cycles', 'compensation.propose', 'compensation.view']);
    $this->budgetApprover = tenantUser($this->tenant, ['compensation.budget', 'compensation.approve']);
    $this->budget = function (float $amount, array $extra = []) {
        $b = $this->budgets->create($extra + ['code' => 'INC-'.uniqid(), 'name' => 'Increments FY27', 'company_id' => $this->company->id, 'period_start' => '2026-04-01', 'period_end' => '2027-03-31', 'currency' => 'INR', 'amount' => $amount], $this->planner);

        return $this->budgets->approve($b, $this->budgetApprover);
    };
    $this->charged = function (float $ctc, int $budgetId, ?object $employee = null) {
        $c = $this->changes->propose($employee ?? $this->employee, ['change_type' => 'annual_increment', 'effective_from' => '2026-10-01', 'salary_structure_id' => $this->structure->id, 'ctc_annual' => $ctc, 'reason' => 'Increment', 'compensation_budget_id' => $budgetId], $this->actors['proposer']);
        $this->changes->submit($c, $this->actors['proposer']);
        $this->changes->review($c, $this->actors['reviewer']);

        return $c;
    };
    $this->employee = salariedEmployee(600000, ['task.view'], '2026-04-01');
});

it('charges approvals to a budget on one declared basis and refuses an increase larger than what is left', function () {
    $budget = ($this->budget)(100000);
    $second = salariedEmployee(500000, ['task.view'], '2026-04-01');
    $third = salariedEmployee(400000, ['task.view'], '2026-04-01');

    $a = $this->changes->approve(($this->charged)(660000, $budget->id), $this->actors['approver']);           // +60,000
    $b = ($this->charged)(550000, $budget->id, $second);                                                       // +50,000: exceeds the 40,000 left
    expect(fn () => $this->changes->approve($b, $this->actors['approver']))->toThrow(RuntimeException::class, 'would exceed budget')
        ->and($b->refresh()->status)->toBe('under_review');
    $this->changes->schedule($a, $this->actors['executor']);
    $c = $this->changes->approve(($this->charged)(440000, $budget->id, $third), $this->actors['approver']);    // +40,000: exactly what is left
    ($this->charged)(600000, $budget->id, $second);   // still planned: a second proposal for the same person (+100,000)

    $m = $this->budgets->measures($budget->refresh(), '2026-10-15');
    expect($m)->toMatchArray(['basis' => 'annual_ctc_increase', 'currency' => 'INR', 'budget' => 100000.0, 'approved' => 40000.0, 'committed' => 60000.0, 'actual' => 60000.0, 'variance' => 0.0])
        ->and($m['planned'])->toBe(50000.0 + 100000.0)
        ->and($this->budgets->measures($budget, '2026-09-30')['actual'])->toBe(0.0);

    // Other currencies and other periods are never charged; a draft budget charges nothing.
    $usd = ($this->budget)(1000000, ['currency' => 'USD']);
    expect(fn () => $this->changes->approve(($this->charged)(700000, $usd->id, salariedEmployee(300000, ['task.view'], '2026-04-01')), $this->actors['approver']))->toThrow(RuntimeException::class, 'cannot be charged');
    $draft = $this->budgets->create(['code' => 'DRAFT', 'name' => 'Draft', 'company_id' => $this->company->id, 'period_start' => '2026-04-01', 'period_end' => '2027-03-31', 'currency' => 'INR', 'amount' => 1], $this->planner);
    expect(fn () => $this->changes->approve(($this->charged)(700000, $draft->id, salariedEmployee(300000, ['task.view'], '2026-04-01')), $this->actors['approver']))->toThrow(RuntimeException::class, 'is not approved')
        ->and(fn () => $this->budgets->approve($draft, $this->planner))->toThrow(RuntimeException::class, 'compensation.approve');
});

it('populates a cycle deterministically from the compensation in force and finalized ratings, never deciding anything itself', function () {
    $performanceCycle = draftCycle(['code' => 'FY26']);
    $others = collect(range(1, 3))->map(fn ($i) => salariedEmployee(500000 + $i * 100000, ['task.view'], '2026-04-01'));
    $exited = salariedEmployee(800000, ['task.view'], '2026-04-01');
    forceLifecycle($exited, LifecycleState::Exited, ['exit_date' => '2026-08-31']);
    $ratings = [$this->employee->id => 'Exceeds', $others[0]->id => 'Meets'];
    app()->instance(PerformanceOutcomesReader::class, new class($ratings) implements PerformanceOutcomesReader
    {
        public function __construct(private array $ratings) {}

        public function finalOutcome(int $employeeId, int $cycleId): ?array
        {
            return isset($this->ratings[$employeeId]) ? ['cycle_code' => 'FY26', 'period_end' => '2026-03-31', 'final_rating' => 4.0, 'final_label' => $this->ratings[$employeeId], 'promotion_recommended' => false, 'finalized_at' => '2026-05-01', 'template_version_id' => null, 'rating_scale_checksum' => null] : null;
        }
    });
    $cycles = app(CompensationCycles::class);
    $cycle = $cycles->create(['code' => 'AI27', 'name' => 'Annual increment 2027', 'cycle_type' => 'annual_increment', 'company_id' => $this->company->id, 'effective_from' => '2027-04-01', 'default_increase_percent' => 5, 'performance_cycle_id' => $performanceCycle->id, 'rating_increase_percent' => ['Exceeds' => 12, 'Meets' => 8]], $this->planner);

    $result = $cycles->populate($cycle, $this->planner);
    $lines = CompensationChange::query()->where('compensation_cycle_id', $cycle->id)->get()->keyBy('employee_id');
    expect($result)->toBe(['lines' => 4, 'skipped' => 0])
        ->and($lines->has($exited->id))->toBeFalse()   // exited: not in the cycle's lifecycle states
        ->and((float) $lines[$this->employee->id]->ctc_annual)->toBe(672000.0)->and($lines[$this->employee->id]->performance_label)->toBe('Exceeds')
        ->and((float) $lines[$others[0]->id]->ctc_annual)->toBe(648000.0)
        ->and((float) $lines[$others[1]->id]->ctc_annual)->toBe(735000.0)->and($lines[$others[1]->id]->performance_label)->toBeNull()
        ->and($lines->every(fn ($l) => $l->status === 'draft' && $l->source === 'cycle'))->toBeTrue()
        ->and(EmployeeSalaryAssignment::query()->withoutGlobalScope(AccessScope::class)->whereDate('effective_from', '2027-04-01')->exists())->toBeFalse();

    // Re-populating replaces the draft lines with the same deterministic result.
    $checksum = $cycle->refresh()->snapshot_checksum;
    $cycles->populate($cycle, $this->planner);
    expect($cycle->refresh()->snapshot_checksum)->toBe($checksum)->and(CompensationChange::query()->where('compensation_cycle_id', $cycle->id)->count())->toBe(4);
});

it('takes a cycle through four different people and executes every line or none, once', function () {
    collect(range(1, 2))->each(fn ($i) => salariedEmployee(500000, ['task.view'], '2026-04-01'));
    $reviewer = tenantUser($this->tenant, ['compensation.review']);
    $approver = tenantUser($this->tenant, ['compensation.approve']);
    $executor = tenantUser($this->tenant, ['compensation.execute']);
    $cycle = $this->cycles->create(['code' => 'MKT', 'name' => 'Market adjustment', 'cycle_type' => 'market_adjustment', 'company_id' => $this->company->id, 'effective_from' => '2026-10-01', 'default_increase_percent' => 3], $this->planner);
    $this->cycles->populate($cycle, $this->planner);

    expect(fn () => $this->cycles->submit($cycle->refresh(), $reviewer))->toThrow(RuntimeException::class, 'Only the preparer submits');
    $this->cycles->submit($cycle->refresh(), $this->planner);
    expect(fn () => $this->cycles->review($cycle->refresh(), $this->planner))->toThrow(RuntimeException::class);
    $this->cycles->review($cycle->refresh(), $reviewer);
    expect(fn () => $this->cycles->approve($cycle->refresh(), $reviewer))->toThrow(RuntimeException::class);
    $this->cycles->approve($cycle->refresh(), $approver);
    expect(fn () => $this->cycles->execute($cycle->refresh(), $approver))->toThrow(RuntimeException::class);

    // One line cannot be executed (the employee left after approval): nothing is executed.
    $line = CompensationChange::query()->where('compensation_cycle_id', $cycle->id)->orderBy('id')->first();
    forceLifecycle($line->employee, LifecycleState::Exited, ['exit_date' => '2026-09-25']);
    expect(fn () => $this->cycles->execute($cycle->refresh(), $executor))->toThrow(RuntimeException::class)
        ->and(CompensationChange::query()->where('compensation_cycle_id', $cycle->id)->where('status', 'approved')->count())->toBe(3)
        ->and($cycle->refresh()->status)->toBe('approved');

    forceLifecycle($line->employee, LifecycleState::Active, ['exit_date' => null]);
    $operation = $this->cycles->execute($cycle->refresh(), $executor);
    expect($cycle->refresh()->status)->toBe('executed')->and($cycle->operation_id)->toBe($operation)
        ->and(CompensationChange::query()->where('compensation_cycle_id', $cycle->id)->where('status', 'scheduled')->count())->toBe(3)
        ->and($this->cycles->execute($cycle->refresh(), $executor))->toBe($operation)   // idempotent
        ->and(AuditEvent::query()->where('action', 'BULK_OPERATION')->where('operation_id', $operation)->count())->toBe(1)
        ->and(AuditEvent::query()->where('action', 'SCHEDULED')->where('entity_type', CompensationChange::class)->where('operation_id', $operation)->count())->toBe(3);
});

it('prices a workforce plan at the applicable ranges and proposes from a position without moving anyone', function () {
    $org = workforceOrg();
    $grade = Grade::factory()->create(['code' => 'G7', 'name' => 'G7']);
    $preparer = tenantUser($this->tenant, ['compensation.configure']);
    $ranges = app(CompensationRanges::class);
    $range = $ranges->create(['grade_id' => $grade->id, 'currency' => 'INR', 'minimum' => 900000, 'midpoint' => 1100000, 'maximum' => 1300000, 'effective_from' => '2026-04-01'], $preparer);
    $ranges->submit($range, $preparer);
    $ranges->approve($range->refresh(), $this->actors['approver']);
    $position = openPosition(['code' => 'ENG-7', 'title' => 'Engineer', 'organisation_node_id' => $org['department']->id, 'grade_id' => $grade->id], $this->hr, tenantUser($this->tenant, ['workforce.approve', 'workforce.view']), '2026-04-01');

    $plans = app(WorkforcePlans::class);
    $plan = $plans->create(['code' => 'WP27', 'name' => 'Plan', 'company_id' => $org['company']->id, 'period_type' => 'annual', 'period_start' => '2027-01-01', 'period_end' => '2027-12-31'], $this->hr);
    $version = $plan->versions()->first();
    WorkforcePlanLine::query()->create(['workforce_plan_version_id' => $version->id, 'movement_type' => 'new_position', 'grade_id' => $grade->id, 'headcount' => 2, 'fte' => 2, 'effective_date' => '2027-02-01']);
    WorkforcePlanLine::query()->create(['workforce_plan_version_id' => $version->id, 'movement_type' => 'reduction', 'position_id' => $position->id, 'headcount' => 1, 'fte' => 1, 'effective_date' => '2027-03-01']);
    WorkforcePlanLine::query()->create(['workforce_plan_version_id' => $version->id, 'movement_type' => 'new_position', 'headcount' => 1, 'fte' => 1, 'effective_date' => '2027-03-01']);
    $lineCount = WorkforcePlanLine::query()->count();

    $priced = app(CompensationPlanning::class)->priceVersion($version);
    expect($priced['totals']['INR'])->toBe(['minimum' => 900000.0, 'midpoint' => 1100000.0, 'maximum' => 1300000.0, 'headcount' => 1])
        ->and($priced['unpriced'])->toBe(1)
        ->and(WorkforcePlanLine::query()->count())->toBe($lineCount);

    $positionsBefore = EmployeePosition::query()->where('employee_id', $this->employee->id)->count();
    $change = app(CompensationPlanning::class)->proposeFromPosition($this->employee, $position, $this->actors['proposer'], ['effective_from' => '2026-11-01']);
    expect($change->status)->toBe('draft')->and($change->source)->toBe('position')->and((float) $change->ctc_annual)->toBe(1100000.0)
        ->and((int) $change->to_position_id)->toBe($position->id)->and((int) $change->to_grade_id)->toBe($grade->id)
        ->and(EmployeePosition::query()->where('employee_id', $this->employee->id)->count())->toBe($positionsBefore);
});
