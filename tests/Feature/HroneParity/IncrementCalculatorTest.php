<?php

use App\Domain\Audit\Models\AuditEvent;
use App\Domain\Compensation\Contracts\CompensationOutput;
use App\Domain\Compensation\Models\CompensationChange;
use App\Domain\Compensation\Models\CompensationCycle;
use App\Domain\Compensation\Models\CompensationCycleProposal;
use App\Domain\Compensation\Models\CompensationCycleShare;
use App\Domain\Compensation\Models\SalaryStructure;
use App\Domain\Compensation\Services\CompensationBudgets;
use App\Domain\Compensation\Services\CompensationChanges;
use App\Domain\Compensation\Services\CompensationCycles;
use App\Domain\Compensation\Services\IncrementCalculator;
use App\Domain\Compensation\Services\IncrementPlanSharing;
use App\Domain\Employment\Models\Employee;
use App\Domain\Employment\Models\EmployeePosition;
use App\Domain\Experience\Services\ExperienceNavigation;
use App\Domain\Identity\Scopes\AccessScope;
use App\Domain\Lifecycle\Enums\LifecycleState;
use App\Domain\Notifications\Models\NotificationDelivery;
use App\Domain\Offboarding\Support\DataInventory;
use App\Domain\Organisation\Models\Designation;
use App\Domain\Performance\Contracts\PerformanceOutcomesReader;
use App\Filament\Pages\IncrementCalculator as IncrementCalculatorPage;
use App\Filament\Pages\PayrollDesk;
use App\Filament\Pages\TeamIncrements;
use App\Filament\Resources\CompensationCycles\CompensationCycleResource;
use App\Filament\Resources\CompensationCycles\Pages\ViewCompensationCycle;
use App\Filament\Resources\CompensationCycles\RelationManagers\LinesRelationManager;
use App\Filament\Support\IncrementCalculatorWizard;
use Filament\Facades\Filament;
use Filament\Forms\Components\Repeater;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;

require_once __DIR__.'/../Workflow/WorkflowTestHelpers.php';
require_once __DIR__.'/../Performance/PerformanceTestHelpers.php';
require_once __DIR__.'/../Payroll/PayrollTestHelpers.php';

/*
| HROne parity PO-17 (matrix 25 §25.9.2 #1, #2, #5–#10; §25.14 #5): the increment calculator on compensation cycles —
| population by organisation filters and pasted codes, tenure proration, amount basis, the CTC band × rating grid (the
| earlier rating-label matrix read as before), summary and error file, sharing with managers and their proposals for
| their own team, clone, and never for oneself.
*/

/** Bind finalized ratings (employee id => [rating, label]) for the performance contract. */
function poIncrementRatings(array $ratings): void
{
    app()->instance(PerformanceOutcomesReader::class, new class($ratings) implements PerformanceOutcomesReader
    {
        public function __construct(private array $ratings) {}

        public function finalOutcome(int $employeeId, int $cycleId): ?array
        {
            if (! isset($this->ratings[$employeeId])) {
                return null;
            }
            [$rating, $label] = $this->ratings[$employeeId];

            return ['cycle_code' => 'FY26', 'period_end' => '2026-03-31', 'final_rating' => (float) $rating, 'final_label' => $label, 'promotion_recommended' => false,
                'finalized_at' => '2026-05-01', 'template_version_id' => null, 'rating_scale_checksum' => null, 'recommendations' => null];
        }
    });
}

/** An active employee whose compensation carries a variable-pay target (four people, as always). */
function poIncrementVariableEmployee(float $ctc, float $variable): Employee
{
    $employee = employeeWithUser(null, ['task.view']);
    forceLifecycle($employee, LifecycleState::Active, ['joining_date' => '2025-01-01']);
    $actors = compensationActors();
    $changes = app(CompensationChanges::class);
    $change = $changes->propose($employee, ['change_type' => 'hire', 'effective_from' => '2026-04-01', 'salary_structure_id' => SalaryStructure::query()->where('code', 'STANDARD')->value('id'),
        'ctc_annual' => $ctc, 'variable_target_annual' => $variable, 'component_values' => ['CONV' => 1600], 'reason' => 'test'], $actors['proposer']);
    $changes->submit($change, $actors['proposer']);
    $changes->review($change, $actors['reviewer']);
    $changes->approve($change, $actors['approver']);
    $changes->schedule($change, $actors['executor']);

    return $employee->refresh();
}

beforeEach(function () {
    $this->travelTo('2026-09-21 09:00:00');
    syncComplianceRules();
    $this->tenant = provisionTenant();
    actAsTenant($this->tenant);
    $this->hr = tenantUser($this->tenant, ['*']);
    $this->actingAs($this->hr);
    Filament::getPanel('admin')->boot();
    $this->company = payrollCompany();
    $this->planner = tenantUser($this->tenant, ['compensation.budget', 'compensation.cycles', 'compensation.propose', 'compensation.view']);
    $this->data = fn (array $data = []) => $data + ['code' => 'INC-'.Str::upper(Str::random(10)), 'name' => 'Increments 2027', 'cycle_type' => 'annual_increment', 'company_id' => $this->company->id,
        'effective_from' => '2027-04-01', 'default_increase_percent' => 10];
    $this->lines = fn (CompensationCycle $cycle) => CompensationChange::query()->withoutGlobalScope(AccessScope::class)->where('compensation_cycle_id', $cycle->id)->get()->keyBy('employee_id');
});

it('calculates each line from the CTC band × rating grid and skips whom the grid does not reach', function () {
    $performance = draftCycle(['code' => 'FY26']);
    [$a, $b, $c, $d, $e, $f, $g] = [salariedEmployee(400000, from: '2026-04-01'), salariedEmployee(400000, from: '2026-04-01'), salariedEmployee(600000, from: '2026-04-01'),
        salariedEmployee(600000, from: '2026-04-01'), salariedEmployee(1200000, from: '2026-04-01'), salariedEmployee(450000, from: '2026-04-01'), salariedEmployee(400000, from: '2026-04-01')];
    poIncrementRatings([$a->id => [4.0, 'Exceeds'], $b->id => [2.0, 'Below'], $c->id => [4.0, 'Exceeds'], $e->id => [4.5, 'Outstanding'], $g->id => [2.7, 'Meets']]);
    $cycles = app(CompensationCycles::class);
    $grid = ['bands' => [
        ['ctc_from' => 0, 'ctc_to' => 500000, 'ratings' => [['from' => 1, 'to' => 2.5, 'value' => 4], ['from' => 3, 'to' => 5, 'value' => 10]]],
        ['ctc_from' => 500000.01, 'ctc_to' => 1000000, 'value' => 3, 'ratings' => [['from' => 3, 'to' => 5, 'value' => 8]]],
        ['ctc_from' => 1000000.01, 'ctc_to' => null, 'value' => 2],
    ]];
    $cycle = $cycles->create(($this->data)(['performance_cycle_id' => $performance->id, 'rating_increase_percent' => $grid]), $this->planner);

    expect($cycle->hasGrid())->toBeTrue()->and($cycle->rating_increase_percent['shape'])->toBe('ctc_band_rating')
        ->and($cycle->rating_increase_percent['bands'][2]['ctc_to'])->toBeNull();
    $result = $cycles->populate($cycle, $this->planner);
    $lines = ($this->lines)($cycle);
    expect($result)->toBe(['lines' => 5, 'skipped' => 2])
        ->and((float) $lines[$a->id]->ctc_annual)->toBe(440000.0)->and((float) $lines[$a->id]->increase_percent)->toBe(10.0)->and($lines[$a->id]->performance_label)->toBe('Exceeds')
        ->and((float) $lines[$a->id]->base_ctc_annual)->toBe(400000.0)
        ->and($lines[$a->id]->reason)->toStartWith('Increments 2027: CTC band 0.00–500,000.00, rating Exceeds, 10.00%')
        ->and((float) $lines[$b->id]->ctc_annual)->toBe(416000.0)        // band 1, rating 2.0 → 4%
        ->and((float) $lines[$c->id]->ctc_annual)->toBe(648000.0)        // band 2, rating 4.0 → 8%
        ->and((float) $lines[$d->id]->ctc_annual)->toBe(618000.0)        // band 2, no rating → the band's value 3%
        ->and((float) $lines[$e->id]->ctc_annual)->toBe(1224000.0)       // band 3 (open), 2%
        ->and($lines->has($f->id))->toBeFalse()->and($lines->has($g->id))->toBeFalse()
        ->and($lines->every(fn ($l) => $l->status === 'draft' && $l->source === 'cycle'))->toBeTrue();
    $errors = collect($cycle->refresh()->population_errors)->keyBy('employee_id');
    expect($errors[$f->id]['reason'])->toBe('No finalized rating in the performance cycle, and the band has no value for other ratings.')
        ->and($errors[$g->id]['reason'])->toBe('The finalized rating is outside the band\'s rating rows, and the band has no value for other ratings.');

    // HROne's grid validations.
    $bad = fn (array $bands, ?int $performanceId = null) => fn () => $cycles->create(($this->data)(['performance_cycle_id' => $performanceId ?? $performance->id, 'rating_increase_percent' => ['bands' => $bands]]), $this->planner);
    expect($bad([['ctc_from' => 0, 'ctc_to' => 500000, 'value' => 5], ['ctc_from' => 400000, 'ctc_to' => null, 'value' => 3]]))->toThrow(RuntimeException::class, 'From annual salary should be greater than the last filled annual salary in To')
        ->and($bad([['ctc_from' => 500000, 'ctc_to' => 100000, 'value' => 5]]))->toThrow(RuntimeException::class, 'To should be greater than or equal to From')
        ->and($bad([['ctc_from' => 0, 'ctc_to' => null, 'ratings' => [['from' => 3, 'to' => 5, 'value' => 8], ['from' => 4, 'to' => 5, 'value' => 9]]]]))->toThrow(RuntimeException::class, 'From rating should be greater than the last filled rating in To')
        ->and($bad([['ctc_from' => 0, 'ctc_to' => null, 'value' => 5], ['ctc_from' => 600000, 'ctc_to' => null, 'value' => 5]]))->toThrow(RuntimeException::class, 'From annual salary should be greater')
        ->and($bad([['ctc_from' => 0, 'ctc_to' => null, 'value' => 2000]]))->toThrow(RuntimeException::class, 'between -100 and 1000')
        ->and(fn () => $cycles->create(($this->data)(['rating_increase_percent' => ['bands' => [['ctc_from' => 0, 'ratings' => [['from' => 1, 'to' => 5, 'value' => 5]]]]]]), $this->planner))
        ->toThrow(RuntimeException::class, 'without a performance cycle every band needs a value');
});

it('reads the earlier rating-label matrix exactly as before', function () {
    $performance = draftCycle(['code' => 'FY26']);
    $exceeds = salariedEmployee(600000, from: '2026-04-01');
    $meets = salariedEmployee(600000, from: '2026-04-01');
    $unrated = salariedEmployee(700000, from: '2026-04-01');
    poIncrementRatings([$exceeds->id => [4.0, 'Exceeds'], $meets->id => [3.0, 'Meets']]);
    $cycles = app(CompensationCycles::class);
    $cycle = $cycles->create(['code' => 'AI27', 'name' => 'Annual increment 2027', 'cycle_type' => 'annual_increment', 'company_id' => $this->company->id, 'effective_from' => '2027-04-01',
        'default_increase_percent' => 5, 'performance_cycle_id' => $performance->id, 'rating_increase_percent' => ['Exceeds' => 12, 'Meets' => 8]], $this->planner);

    expect($cycle->rating_increase_percent)->toEqual(['Exceeds' => 12, 'Meets' => 8])->and($cycle->hasGrid())->toBeFalse()
        ->and($cycle->increment_basis)->toBe('ctc_percent')->and($cycle->prorate_on_tenure)->toBeFalse()->and($cycle->population)->toBeNull();
    expect($cycles->populate($cycle, $this->planner))->toBe(['lines' => 3, 'skipped' => 0]);
    $lines = ($this->lines)($cycle);
    expect((float) $lines[$exceeds->id]->ctc_annual)->toBe(672000.0)->and($lines[$exceeds->id]->reason)->toBe('Annual increment 2027: rating Exceeds, 12.00%')
        ->and((float) $lines[$meets->id]->ctc_annual)->toBe(648000.0)->and($lines[$meets->id]->reason)->toBe('Annual increment 2027: rating Meets, 8.00%')
        ->and((float) $lines[$unrated->id]->ctc_annual)->toBe(735000.0)->and($lines[$unrated->id]->reason)->toBe('Annual increment 2027: 5.00%')
        ->and($lines[$unrated->id]->performance_label)->toBeNull()->and((float) $lines[$unrated->id]->increase_percent)->toBe(5.0)
        ->and($cycle->refresh()->population_errors)->toBeNull();

    // The snapshot checksum is the earlier one: employee : assignment in force : proposed CTC, sorted.
    $output = app(CompensationOutput::class);
    $snapshot = collect([[$exceeds, 672000.0], [$meets, 648000.0], [$unrated, 735000.0]])->map(fn ($p) => $p[0]->id.':'.$output->on($p[0], '2027-04-01')->assignmentId.':'.$p[1])->sort()->values()->all();
    expect($cycle->snapshot_checksum)->toBe(hash('sha256', implode('|', $snapshot)));

    // The earlier validation, word for word.
    expect(fn () => $cycles->create(($this->data)(['rating_increase_percent' => ['Top' => 2000]]), $this->planner))
        ->toThrow(RuntimeException::class, 'The increase for rating [Top] must be a percentage between -100 and 1000.');
});

it('prorates the increment by months since joining or in the current designation, up to the cut-off', function () {
    $full = salariedEmployee(600000, from: '2026-04-01');
    $half = salariedEmployee(600000, from: '2026-04-01');
    forceLifecycle($half, LifecycleState::Active, ['joining_date' => '2026-10-01']);
    $partial = salariedEmployee(600000, from: '2026-04-01');
    forceLifecycle($partial, LifecycleState::Active, ['joining_date' => '2026-10-15']);
    $late = salariedEmployee(600000, from: '2026-04-01');
    forceLifecycle($late, LifecycleState::Active, ['joining_date' => '2027-04-15']);
    $cycles = app(CompensationCycles::class);
    $cycle = $cycles->create(($this->data)(['prorate_on_tenure' => true, 'proration_basis' => 'joining', 'proration_cutoff' => '2027-03-31', 'proration_full_months' => 12]), $this->planner);
    $result = $cycles->populate($cycle, $this->planner);
    $lines = ($this->lines)($cycle);

    expect($result)->toBe(['lines' => 3, 'skipped' => 1])
        ->and((float) $lines[$full->id]->ctc_annual)->toBe(660000.0)
        ->and((float) $lines[$half->id]->ctc_annual)->toBe(630000.0)->and((float) $lines[$half->id]->increase_percent)->toBe(5.0)
        ->and($lines[$half->id]->reason)->toContain('10.00%, prorated 6/12 months')
        ->and((float) $lines[$partial->id]->ctc_annual)->toBe(625000.0)->and((float) $lines[$partial->id]->increase_percent)->toBe(4.17)   // 5 full months
        ->and(collect($cycle->refresh()->population_errors)->firstWhere('employee_id', $late->id)['reason'])->toBe('No full month of service by the proration cut-off date.');

    // Months in the current designation: promoted on 1 July 2026 → 9 months by the cut-off.
    $junior = Designation::factory()->create(['name' => 'Analyst']);
    $senior = Designation::factory()->create(['name' => 'Senior analyst']);
    $row = EmployeePosition::query()->withoutGlobalScope(AccessScope::class)->where('employee_id', $full->id)->firstOrFail();
    $row->update(['designation_id' => $junior->id, 'effective_to' => '2026-06-30']);
    EmployeePosition::query()->create(['employee_id' => $full->id, 'company_id' => $row->company_id, 'designation_id' => $senior->id, 'effective_from' => '2026-07-01', 'change_type' => 'promotion']);
    $role = $cycles->create(($this->data)(['prorate_on_tenure' => true, 'proration_basis' => 'role', 'proration_cutoff' => '2027-03-31']), $this->planner);
    expect($role->proration_full_months)->toBe(12);
    $cycles->populate($role, $this->planner);
    $lines = ($this->lines)($role);
    expect((float) $lines[$full->id]->ctc_annual)->toBe(645000.0)                // 9/12 of 60,000
        ->and((float) $lines[$half->id]->ctc_annual)->toBe(630000.0);            // one designation since joining

    expect(fn () => $cycles->create(($this->data)(['prorate_on_tenure' => true, 'proration_full_months' => 200]), $this->planner))->toThrow(RuntimeException::class, 'between 1 and 120 months')
        ->and(fn () => $cycles->create(($this->data)(['prorate_on_tenure' => true, 'proration_basis' => 'weeks']), $this->planner))->toThrow(RuntimeException::class, 'Unknown proration basis');
});

it('gives increments as a percent of fixed pay or a flat amount, by default or by grid band', function () {
    $variable = poIncrementVariableEmployee(600000, 60000);
    $plain = salariedEmployee(500000, from: '2026-04-01');
    $cycles = app(CompensationCycles::class);

    $fixed = $cycles->create(($this->data)(['increment_basis' => 'fixed_percent']), $this->planner);
    $cycles->populate($fixed, $this->planner);
    $lines = ($this->lines)($fixed);
    expect((float) $lines[$variable->id]->ctc_annual)->toBe(654000.0)->and((float) $lines[$variable->id]->increase_percent)->toBe(9.0)   // 10% of 540,000
        ->and((float) $lines[$variable->id]->variable_target_annual)->toBe(60000.0)
        ->and($lines[$variable->id]->reason)->toBe('Increments 2027: 10.00% of fixed pay')
        ->and((float) $lines[$plain->id]->ctc_annual)->toBe(550000.0);

    $flat = $cycles->create(($this->data)(['increment_basis' => 'amount', 'default_increase_amount' => 50000]), $this->planner);
    $cycles->populate($flat, $this->planner);
    $lines = ($this->lines)($flat);
    expect((float) $lines[$variable->id]->ctc_annual)->toBe(650000.0)->and((float) $lines[$variable->id]->increase_percent)->toBe(8.33)
        ->and($lines[$variable->id]->reason)->toBe('Increments 2027: INR 50,000.00')
        ->and((float) $lines[$plain->id]->ctc_annual)->toBe(550000.0)->and((float) $lines[$plain->id]->increase_percent)->toBe(10.0);

    $banded = $cycles->create(($this->data)(['increment_basis' => 'amount', 'rating_increase_percent' => ['bands' => [
        ['ctc_from' => 0, 'ctc_to' => 550000, 'value' => 30000], ['ctc_from' => 550000.01, 'ctc_to' => null, 'value' => 70000],
    ]]]), $this->planner);
    $cycles->populate($banded, $this->planner);
    $lines = ($this->lines)($banded);
    expect((float) $lines[$plain->id]->ctc_annual)->toBe(530000.0)->and((float) $lines[$variable->id]->ctc_annual)->toBe(670000.0);

    expect(fn () => $cycles->create(($this->data)(['increment_basis' => 'amount', 'rating_increase_percent' => ['bands' => [['ctc_from' => 0, 'value' => 2e11]]]]), $this->planner))
        ->toThrow(RuntimeException::class, 'below 100,000,000,000')
        ->and(fn () => $cycles->create(($this->data)(['increment_basis' => 'bonus']), $this->planner))->toThrow(RuntimeException::class, 'Unknown increment basis');
});

it('summarises headcount, current and proposed cost, the increase and the budget used, in the review and on the plan', function () {
    $people = collect(range(1, 3))->map(fn () => salariedEmployee(500000, from: '2026-04-01'));
    $budgets = app(CompensationBudgets::class);
    $budget = $budgets->approve($budgets->create(['code' => 'INC27', 'name' => 'Increments FY28', 'company_id' => $this->company->id, 'period_start' => '2027-04-01', 'period_end' => '2028-03-31', 'currency' => 'INR', 'amount' => 100000], $this->planner),
        tenantUser($this->tenant, ['compensation.budget', 'compensation.approve']));
    $cycles = app(CompensationCycles::class);
    $data = ($this->data)(['compensation_budget_id' => $budget->id]);

    // The wizard's Review runs the same calculation without writing anything.
    $draft = $cycles->draftOf($data, $this->planner);
    $plan = $cycles->plan($draft, $this->planner);
    $calculator = app(IncrementCalculator::class);
    $preview = $calculator->summarise($draft, array_map(fn ($l) => ['currency' => $l['current']->currency, 'proposed' => $l['result']['proposed'], 'current' => $l['current']->ctcAnnual, 'percent' => $l['result']['percent']], $plan['lines']), count($plan['errors']));
    expect(CompensationCycle::query()->count())->toBe(0)->and(CompensationChange::query()->where('source', 'cycle')->count())->toBe(0);

    $cycle = $cycles->create($data, $this->planner);
    $cycles->populate($cycle, $this->planner);
    $summary = $calculator->summary($cycle->refresh());
    expect($summary)->toMatchArray([
        'currency' => 'INR', 'headcount' => 3, 'current_cost' => 1500000.0, 'proposed_cost' => 1650000.0, 'increase' => 150000.0, 'increase_percent' => 10.0, 'average_percent' => 10.0,
        'budget' => 100000.0, 'budget_used_percent' => 150.0, 'budget_left_after' => -50000.0, 'over_budget' => true, 'skipped' => 0,
    ])->and($preview)->toBe($summary)
        ->and(IncrementCalculatorWizard::summaryHtml($summary))->toContain('Total increment')->toContain('INR 150,000.00')->toContain('approval would be refused');

    // The summary follows the lines: an adjusted line changes the proposed cost.
    app(CompensationChanges::class)->update(($this->lines)($cycle)[$people[0]->id], ['ctc_annual' => 520000], $this->planner);
    expect($calculator->summary($cycle->refresh())['increase'])->toBe(120000.0);

    // The plan page shows it; the calculator lists the plan with its figures.
    $this->get(CompensationCycleResource::getUrl('view', ['record' => $cycle]))->assertOk()->assertSee('Total increment')->assertSee('Budget used');
    $this->actingAs($this->planner);
    Livewire::test(IncrementCalculatorPage::class)->assertOk()->assertCanSeeTableRecords([$cycle])->assertSee('120,000.00')->assertSee('100,000.00');
});

it('narrows the population by organisation filters and pasted codes and lists everyone skipped in an audited error file', function () {
    $analyst = Designation::factory()->create(['name' => 'Analyst']);
    $kept = salariedEmployee(500000, from: '2026-04-01');
    $alsoKept = salariedEmployee(500000, from: '2026-04-01');
    $otherDesignation = salariedEmployee(500000, from: '2026-04-01');
    $leftOut = salariedEmployee(500000, from: '2026-04-01');
    $unpaid = employeeWithUser();
    forceLifecycle($unpaid, LifecycleState::Active, ['joining_date' => '2025-01-01']);
    $refused = salariedEmployee(500000, from: '2026-04-01');
    $own = salariedEmployee(500000, ['compensation.cycles', 'compensation.propose', 'compensation.view'], '2026-04-01');
    foreach ([$kept, $alsoKept, $leftOut, $unpaid, $refused, $own] as $e) {
        EmployeePosition::query()->withoutGlobalScope(AccessScope::class)->where('employee_id', $e->id)->update(['designation_id' => $analyst->id]);
    }
    // A structure with no approved version: compensation rules refuse that line when it is proposed.
    $empty = SalaryStructure::query()->create(['name' => 'Legacy', 'code' => 'LEGACY', 'status' => 'active']);
    DB::table('employee_salary_assignments')->where('employee_id', $refused->id)->update(['salary_structure_id' => $empty->id]);

    $cycles = app(CompensationCycles::class);
    $cycle = $cycles->create(($this->data)(['population' => ['organisation' => ['designation_id' => [$analyst->id]], 'exclude_codes' => $leftOut->employee_code,
        'codes' => implode(', ', [$kept->employee_code, $alsoKept->employee_code, $otherDesignation->employee_code, $leftOut->employee_code, $unpaid->employee_code, $refused->employee_code, $own->employee_code, 'NOPE-1'])]]), $own->user);
    expect($cycle->population)->toBe(['organisation' => ['designation_id' => [$analyst->id]], 'codes' => [$kept->employee_code, $alsoKept->employee_code, $otherDesignation->employee_code, $leftOut->employee_code, $unpaid->employee_code, $refused->employee_code, $own->employee_code, 'NOPE-1'], 'exclude_codes' => [$leftOut->employee_code]]);
    $result = $cycles->populate($cycle, $own->user);
    $lines = ($this->lines)($cycle);
    $errors = collect($cycle->refresh()->population_errors)->keyBy('employee_code');

    expect($lines->keys()->sort()->values()->all())->toBe(collect([$kept->id, $alsoKept->id])->sort()->values()->all())
        ->and($result)->toBe(['lines' => 2, 'skipped' => 6])
        ->and($errors[$otherDesignation->employee_code]['reason'])->toStartWith('Not in the plan')                 // other designation
        ->and($errors[$leftOut->employee_code]['reason'])->toStartWith('Not in the plan')                         // left out on purpose
        ->and($errors['NOPE-1']['reason'])->toStartWith('Not in the plan')
        ->and($errors[$unpaid->employee_code]['reason'])->toBe('No approved compensation in force on the effective date.')
        ->and($errors[$own->employee_code]['reason'])->toBe('Your own compensation: no one plans their own increment.')
        ->and($errors[$refused->employee_code]['reason'])->toContain('has no approved version in force');

    // The error file: one row per skip, formula-injection safe, audited; only for people who prepare cycles.
    $csv = app(IncrementCalculator::class)->errorCsv($cycle, $this->planner);
    $rows = array_map('str_getcsv', array_filter(explode("\n", trim($csv))));
    expect($rows[0])->toBe(['Employee code', 'Employee', 'Reason'])->and(count($rows))->toBe(7)
        ->and(collect($rows)->pluck(0))->toContain('NOPE-1')->toContain($unpaid->employee_code)
        ->and(AuditEvent::query()->withoutGlobalScopes()->where('action', 'EXPORT')->where('metadata->export', 'increment_errors')->exists())->toBeTrue()
        ->and(fn () => app(IncrementCalculator::class)->errorCsv($cycle, tenantUser($this->tenant, ['compensation.view'])))->toThrow(RuntimeException::class, 'compensation.cycles');

    $this->actingAs($this->planner);
    Livewire::test(IncrementCalculatorPage::class)->callTableAction('errorFile', $cycle)->assertFileDownloaded('increment-plan-'.strtolower($cycle->code).'-skipped.csv');
});

it('shows a manager only their own team\'s lines of a shared plan and takes their proposals within the grid and the team budget', function () {
    $manager = salariedEmployee(900000, ['employee.view', 'compensation.team'], '2026-04-01');
    $r1 = salariedEmployee(500000, ['task.view'], '2026-04-01', ['CONV' => 1600], $manager);
    $r2 = salariedEmployee(500000, ['task.view'], '2026-04-01', ['CONV' => 1600], $manager);
    $otherManager = salariedEmployee(900000, ['employee.view', 'compensation.team'], '2026-04-01');
    $other = salariedEmployee(500000, ['task.view'], '2026-04-01', ['CONV' => 1600], $otherManager);
    $noRight = salariedEmployee(800000, ['employee.view'], '2026-04-01');
    salariedEmployee(400000, ['task.view'], '2026-04-01', ['CONV' => 1600], $noRight);
    $performance = draftCycle(['code' => 'FY26']);
    poIncrementRatings([$r1->id => [4.5, 'Outstanding'], $r2->id => [3.0, 'Meets']]);
    $cycles = app(CompensationCycles::class);
    $sharing = app(IncrementPlanSharing::class);
    $cycle = $cycles->create(($this->data)(['performance_cycle_id' => $performance->id, 'rating_increase_percent' => ['bands' => [['ctc_from' => 0, 'ctc_to' => null, 'value' => 10, 'ratings' => [['from' => 4, 'to' => 5, 'value' => 20]]]]]]), $this->planner);
    $cycles->populate($cycle, $this->planner);
    $lines = ($this->lines)($cycle);
    expect((float) $lines[$r1->id]->ctc_annual)->toBe(600000.0)->and((float) $lines[$r2->id]->ctc_annual)->toBe(550000.0);   // team pool: 150,000

    // Sharing: only managers with compensation.team who manage someone in the plan; never the preparer.
    expect(TeamIncrements::canAccess())->toBeFalse();
    $results = $sharing->share($cycle, [$manager->user_id, $noRight->user_id, $this->planner->id], $this->planner);
    expect($results[$manager->user_id])->toBeTrue()->and($results[$noRight->user_id])->toContain('compensation.team')->and($results[$this->planner->id])->toContain('own preparer')
        ->and($sharing->managersFor($cycle)->pluck('id')->all())->toContain($manager->user_id)->toContain($otherManager->user_id)->not->toContain($noRight->user_id)
        ->and(NotificationDelivery::query()->where('user_id', $manager->user_id)->where('event', 'compensation.cycle.shared')->exists())->toBeTrue()
        ->and(fn () => $sharing->share($cycle, [$manager->user_id], tenantUser($this->tenant, ['compensation.view'])))->toThrow(RuntimeException::class, 'compensation.cycles');

    // What the manager sees: their two reports, never their own line or anyone else's.
    $this->actingAs($manager->user->fresh());
    expect($sharing->teamLines($cycle, $manager->user)->pluck('employee_id')->sort()->values()->all())->toBe(collect([$r1->id, $r2->id])->sort()->values()->all())
        ->and(TeamIncrements::canAccess())->toBeTrue()
        ->and($sharing->teamLines($cycle, $otherManager->user))->toBeEmpty();   // not shared with them
    Livewire::test(TeamIncrements::class)->assertOk()->assertCanSeeTableRecords([$lines[$r1->id], $lines[$r2->id]])->assertCanNotSeeTableRecords([$lines[$other->id], $lines[$manager->id]]);

    // Within the grid: the band runs from 10% to 20% (up to 100,000 a year here).
    expect(fn () => $sharing->propose($lines[$r2->id], 610000, null, $manager->user))->toThrow(RuntimeException::class, 'within the plan\'s grid')
        ->and(fn () => $sharing->propose($lines[$other->id], 520000, null, $manager->user))->toThrow(RuntimeException::class, 'only for the people you manage')
        ->and(fn () => $sharing->propose($lines[$manager->id], 950000, null, $manager->user))->toThrow(RuntimeException::class, 'only for the people you manage')
        ->and(fn () => $sharing->propose($lines[$other->id], 520000, null, $otherManager->user))->toThrow(RuntimeException::class, 'not shared with you');
    // Within the team's budget: 100,000 for r2 with r1 still at 100,000 is more than the 150,000 the plan gives the team.
    expect(fn () => $sharing->propose($lines[$r2->id], 600000, null, $manager->user))->toThrow(RuntimeException::class, 'more than the plan gives your team');
    $p1 = $sharing->propose($lines[$r1->id], 560000, 'Strong year, but r2 carried the release', $manager->user);
    Livewire::test(TeamIncrements::class)->callTableAction('propose', $lines[$r2->id], data: ['ctc_annual' => 590000, 'note' => 'Release lead']);
    $p2 = CompensationCycleProposal::query()->where('compensation_change_id', $lines[$r2->id]->id)->firstOrFail();
    expect((float) $p1->ctc_annual)->toBe(560000.0)->and((float) $p1->increase_percent)->toBe(12.0)->and($p1->status)->toBe('proposed')
        ->and((float) $p2->ctc_annual)->toBe(590000.0)
        ->and(CompensationCycleProposal::query()->count())->toBe(2)
        ->and((float) $lines[$r1->id]->refresh()->ctc_annual)->toBe(600000.0);   // nothing changes until HR accepts

    // HR finalises: only the preparer accepts (the line is adjusted, still a draft); another planner may decline.
    $otherPlanner = tenantUser($this->tenant, ['compensation.cycles', 'compensation.propose', 'compensation.view']);
    expect(fn () => $sharing->accept($p1, $otherPlanner))->toThrow(RuntimeException::class, 'Only the plan\'s preparer');
    $this->actingAs($this->planner);
    Livewire::test(LinesRelationManager::class, ['ownerRecord' => $cycle, 'pageClass' => ViewCompensationCycle::class])
        ->assertSee('560,000.00')->callTableAction('acceptProposal', $lines[$r1->id]);
    expect((float) $lines[$r1->id]->refresh()->ctc_annual)->toBe(560000.0)->and($lines[$r1->id]->status)->toBe('draft')->and((float) $lines[$r1->id]->increase_percent)->toBe(12.0)
        ->and($lines[$r1->id]->reason)->toEndWith('manager\'s proposal accepted')
        ->and($p1->refresh()->status)->toBe('accepted')->and((int) $p1->decided_by)->toBe($this->planner->id);
    $sharing->decline($p2, 'Keep the grid value', $otherPlanner);
    expect($p2->refresh()->status)->toBe('declined')->and((float) $lines[$r2->id]->refresh()->ctc_annual)->toBe(550000.0);

    // Submitting the plan lapses open proposals; no proposal is taken after the draft.
    $this->actingAs($manager->user->fresh());
    $p3 = $sharing->propose($lines[$r2->id], 550000, null, $manager->user);   // the pool is now 110,000 (r1 accepted at +60,000)
    $cycles->submit($cycle->refresh(), $this->planner);
    expect($p3->refresh()->status)->toBe('lapsed')
        ->and(fn () => $sharing->propose($lines[$r2->id]->refresh(), 560000, null, $manager->user))->toThrow(RuntimeException::class, 'while the plan is a draft');

    // Revoking the share hides the plan from the manager.
    $sharing->revoke($cycle, $manager->user_id, $this->planner);
    expect(CompensationCycleShare::query()->where('user_id', $manager->user_id)->first()->revoked_at)->not->toBeNull()
        ->and(TeamIncrements::canAccess())->toBeFalse()->and($sharing->teamLines($cycle, $manager->user))->toBeEmpty();
});

it('lapses managers\' open proposals when the plan is calculated again', function () {
    $manager = salariedEmployee(900000, ['employee.view', 'compensation.team'], '2026-04-01');
    $report = salariedEmployee(500000, ['task.view'], '2026-04-01', ['CONV' => 1600], $manager);
    $cycles = app(CompensationCycles::class);
    $sharing = app(IncrementPlanSharing::class);
    $cycle = $cycles->create(($this->data)(), $this->planner);
    $cycles->populate($cycle, $this->planner);
    $sharing->share($cycle, [$manager->user_id], $this->planner);
    $this->actingAs($manager->user->fresh());
    $proposal = $sharing->propose(($this->lines)($cycle)[$report->id], 530000, null, $manager->user);

    $cycles->populate($cycle->refresh(), $this->planner);
    expect($proposal->refresh()->status)->toBe('lapsed')->and($proposal->compensation_change_id)->toBeNull()->and($proposal->decision_note)->toBe('The plan was calculated again.')
        ->and(($this->lines)($cycle)->has($report->id))->toBeTrue();
});

it('clones a plan as the starting point of a new draft, with its rules and none of its lines', function () {
    $analyst = Designation::factory()->create(['name' => 'Analyst']);
    $employee = salariedEmployee(500000, from: '2026-04-01');
    EmployeePosition::query()->withoutGlobalScope(AccessScope::class)->where('employee_id', $employee->id)->update(['designation_id' => $analyst->id]);
    $cycles = app(CompensationCycles::class);
    $source = $cycles->create(($this->data)(['code' => 'SRC', 'increment_basis' => 'fixed_percent', 'prorate_on_tenure' => true, 'proration_basis' => 'role', 'proration_cutoff' => '2027-03-31', 'proration_full_months' => 6,
        'population' => ['organisation' => ['designation_id' => [$analyst->id]]], 'rating_increase_percent' => ['bands' => [['ctc_from' => 0, 'ctc_to' => null, 'value' => 7]]]]), $this->planner);
    $cycles->populate($source, $this->planner);

    $copy = $cycles->clone($source->refresh(), ['code' => 'COPY1', 'name' => 'Copy'], $this->planner);
    $rules = ['cycle_type', 'company_id', 'effective_from', 'default_increase_percent', 'rating_increase_percent', 'population', 'increment_basis', 'prorate_on_tenure', 'proration_basis', 'proration_cutoff', 'proration_full_months'];
    expect($copy->refresh()->only($rules))->toEqual($source->only($rules))
        ->and($copy->cloned_from_id)->toBe($source->id)->and($copy->status)->toBe('draft')->and($copy->employee_count)->toBe(0)
        ->and(CompensationChange::query()->where('compensation_cycle_id', $copy->id)->count())->toBe(0)
        ->and(AuditEvent::query()->withoutGlobalScopes()->where('entity_id', (string) $copy->id)->where('metadata->event', 'cloned')->exists())->toBeTrue();
    $later = $cycles->clone($source, ['code' => 'COPY2', 'name' => 'Next year', 'effective_from' => '2028-04-01', 'default_increase_percent' => 0], $this->planner);
    expect($later->effective_from->toDateString())->toBe('2028-04-01');

    expect(fn () => $cycles->clone($source, ['code' => 'COPY1', 'name' => 'Again'], $this->planner))->toThrow(RuntimeException::class, 'already exists')
        ->and(fn () => $cycles->clone($source, ['code' => 'COPY3', 'name' => 'X'], tenantUser($this->tenant, ['compensation.view'])))->toThrow(RuntimeException::class, 'compensation.cycles');
    $upload = CompensationCycle::query()->create(['code' => 'UPLOAD-1', 'name' => 'Upload', 'cycle_type' => CompensationCycle::UPLOAD, 'company_id' => $this->company->id, 'effective_from' => '2026-10-01', 'prepared_by' => $this->planner->id]);
    expect(fn () => $cycles->clone($upload, ['code' => 'COPY4', 'name' => 'X'], $this->planner))->toThrow(RuntimeException::class, 'not cloned');

    // The screen: the same wizard, filled from the plan; the copy is calculated at once.
    $this->actingAs($this->planner);
    $undo = Repeater::fake();
    Livewire::test(IncrementCalculatorPage::class)->mountTableAction('clone', $source)->assertTableActionDataSet(['name' => 'Copy of Increments 2027', 'increment_basis' => 'fixed_percent'])
        ->setTableActionData(['code' => 'COPY5'])->callMountedTableAction()->assertHasNoTableActionErrors();
    $undo();
    $five = CompensationCycle::query()->where('code', 'COPY5')->firstOrFail();
    expect($five->cloned_from_id)->toBe($source->id)->and($five->hasGrid())->toBeTrue()->and($five->employee_count)->toBe(1);
});

it('creates a plan from the wizard opened directly by URL, on the payroll desk and the manager\'s team area', function () {
    $employee = salariedEmployee(500000, from: '2026-04-01');
    $this->actingAs($this->planner);
    $this->get(IncrementCalculatorPage::getUrl())->assertOk()->assertSee('Increment calculator');
    // ?action=create: the page mounts the wizard on load (Filament's wire:init with the URL context).
    $page = Livewire::withQueryParams(['action' => 'create'])->test(IncrementCalculatorPage::class)->assertSet('defaultAction', 'create')
        ->call('mountAction', 'create', [], ['mountedFromUrl' => true]);
    expect($page->get('mountedActions')[0]['name'])->toBe('create')->and($page->get('mountedActions')[0]['context'])->toMatchArray(['mountedFromUrl' => true]);
    Livewire::withQueryParams([]);

    $undo = Repeater::fake();
    Livewire::test(IncrementCalculatorPage::class)->callAction('create', data: [
        'code' => 'WIZ27', 'name' => 'Wizard plan', 'cycle_type' => 'annual_increment', 'company_id' => $this->company->id, 'effective_from' => '2027-04-01',
        'increment_basis' => 'amount', 'default_increase_amount' => 25000, 'prorate_on_tenure' => false, 'populate_now' => true,
        'bands' => [['ctc_from' => 0, 'ctc_to' => null, 'value' => 40000, 'ratings' => []]],
    ])->assertHasNoActionErrors()->assertRedirect();
    $undo();
    $cycle = CompensationCycle::query()->where('code', 'WIZ27')->firstOrFail();
    expect($cycle->increment_basis)->toBe('amount')->and($cycle->hasGrid())->toBeTrue()->and((float) $cycle->default_increase_amount)->toBe(25000.0)
        ->and((float) ($this->lines)($cycle)[$employee->id]->ctc_annual)->toBe(540000.0)->and((int) $cycle->prepared_by)->toBe($this->planner->id);

    // The Review step: the summary of the calculation, or why the plan cannot be made.
    $review = (string) IncrementCalculatorWizard::reviewHtml(['code' => 'REV27', 'name' => 'Review', 'cycle_type' => 'annual_increment', 'company_id' => $this->company->id, 'effective_from' => '2027-04-01', 'increment_basis' => 'ctc_percent', 'default_increase_percent' => 10, 'bands' => []]);
    expect($review)->toContain('data-increment-summary')->toContain('INR 50,000.00')
        ->and((string) IncrementCalculatorWizard::reviewHtml(['code' => 'WIZ27', 'name' => 'Dup', 'cycle_type' => 'annual_increment', 'company_id' => $this->company->id, 'effective_from' => '2027-04-01']))->toContain('already exists');

    // Entry points: the payroll desk, its actions menu, the cycles list; the manager's page sits in My team.
    $this->actingAs($this->hr);
    $items = collect((new PayrollDesk)->deskSections())->flatMap(fn (array $s) => $s['items'])->keyBy('label');
    expect($items['Increment calculator']['url'])->toBe(IncrementCalculatorPage::getUrl())
        ->and($items['Create an increment plan']['url'])->toBe(IncrementCalculatorPage::getUrl(['action' => 'create']))
        ->and(ExperienceNavigation::MODULE_AREA['TeamIncrements'])->toBe('My team')->and(ExperienceNavigation::MODULE_SECTION['TeamIncrements'])->toBe('people')
        ->and(DataInventory::TABLES['compensation'])->toContain('compensation_cycle_shares')->toContain('compensation_cycle_proposals');

    // Without compensation.cycles there is no calculator.
    $this->actingAs(tenantUser($this->tenant, ['compensation.view', 'payroll.view']));
    expect(IncrementCalculatorPage::canAccess())->toBeFalse();
    $this->get(IncrementCalculatorPage::getUrl())->assertForbidden();
});
