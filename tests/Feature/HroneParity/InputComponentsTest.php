<?php

use App\Domain\Compensation\Contracts\CompensationOutput;
use App\Domain\Compensation\Exceptions\CompensationRuleViolation;
use App\Domain\Compensation\Imports\SalaryImports;
use App\Domain\Compensation\Models\EmployeeSalaryAssignment;
use App\Domain\Compensation\Models\SalaryStructure;
use App\Domain\Compensation\Services\CompensationStructures;
use App\Domain\Compliance\Services\StatutoryEngine;
use App\Domain\Lifecycle\Enums\LifecycleState;
use App\Domain\Payroll\Models\PayrollPeriod;
use App\Domain\Payroll\Models\SalaryComponent;
use App\Domain\Payroll\Services\PayrollCalculator;
use App\Domain\Payroll\Services\StructureFormulas;
use App\Filament\Pages\MyCompensation;
use App\Filament\Resources\SalaryStructures\Pages\EditSalaryStructure;
use App\Filament\Resources\SalaryStructures\RelationManagers\VersionsRelationManager;
use Filament\Actions\Testing\TestAction;
use Livewire\Livewire;

require_once __DIR__.'/../Workflow/WorkflowTestHelpers.php';
require_once __DIR__.'/../Payroll/PayrollTestHelpers.php';

/* HROne setup parity BP-2: input components (CTC formula tags), the CTC balancing component and structures built without CTC. */

beforeEach(function () {
    $this->travelTo('2026-09-21 09:00:00');
    syncComplianceRules();
    $this->tenant = provisionTenant();
    actAsTenant($this->tenant);
    $this->hr = tenantUser($this->tenant, ['*']);
    $this->actingAs($this->hr);
    $this->company = payrollCompany();
    $this->preparer = tenantUser($this->tenant, ['compensation.configure']);
    $this->approver = tenantUser($this->tenant, ['compensation.approve']);
    $this->structures = app(CompensationStructures::class);
    $this->formulas = app(StructureFormulas::class);
    $this->component = fn (string $code) => SalaryComponent::query()->where('code', $code)->firstOrFail();
    $this->line = fn (string $code, int $sort, ?string $override = null) => ['component' => ($this->component)($code), 'formula_override' => $override, 'sort_order' => $sort];
});

/** A non-statutory component for these tests. */
function icxComponent(string $code, string $method, array $attributes = []): SalaryComponent
{
    return SalaryComponent::create($attributes + ['name' => ucfirst(strtolower($code)), 'code' => $code, 'type' => 'earning', 'classification' => 'allowance', 'calculation_method' => $method,
        'taxable' => true, 'pf_applicable' => false, 'esi_applicable' => false, 'include_in_ctc' => true, 'include_in_gross' => true, 'is_recurring' => true, 'is_proratable' => false, 'sort_order' => 60, 'status' => 'active']);
}

/** Prepare, submit and approve a new structure (two people). */
function icxStructure(array $data): SalaryStructure
{
    $structures = app(CompensationStructures::class);
    $structure = $structures->create($data, test()->preparer);
    $version = $structure->versions()->firstOrFail();
    $structures->submit($version, test()->preparer);
    $structures->approve($version, test()->approver);

    return $structure;
}

it('reads an input in formulas wherever it sits in the structure and never pays or prints it', function () {
    $rate = icxComponent('FUEL_RATE', 'input', ['include_in_ctc' => false, 'include_in_gross' => false, 'sort_order' => 95]);
    $fuel = icxComponent('FUEL', 'formula', ['formula' => 'fuel_rate * 100', 'include_in_ctc' => false, 'sort_order' => 50]);
    // The input comes after the formula that reads it: inputs are not calculated, so their place does not matter.
    approveStructureVersion('STANDARD', '2026-01-01', [$fuel->id => 50, $rate->id => 95]);
    $employee = salariedEmployee(600000, from: '2026-01-01', componentValues: ['CONV' => 1600, 'FUEL_RATE' => 12.5]);

    $c = app(PayrollCalculator::class)->calculate($employee->refresh(), PayrollPeriod::for($this->company, 2026, 9));
    expect($c->amount('FUEL'))->toBe(1250.0)
        ->and(collect($c->lines)->pluck('code')->all())->not->toContain('FUEL_RATE')
        ->and($c->inputs['calculation_version'])->toBe('payroll-2.5');

    // Without a value on the compensation the input reads as 0, like a fixed amount not set.
    $other = salariedEmployee(600000, from: '2026-01-01');
    expect(app(PayrollCalculator::class)->calculate($other->refresh(), PayrollPeriod::for($this->company, 2026, 9))->has('FUEL'))->toBeTrue()
        ->and(app(PayrollCalculator::class)->calculate($other->refresh(), PayrollPeriod::for($this->company, 2026, 9))->amount('FUEL'))->toBe(0.0);

    // The breakup preview shows the input with its sample value and leaves it out of every total.
    $lines = [($this->line)('BASIC', 10), ($this->line)('HRA', 20), ($this->line)('CONV', 30), ($this->line)('SPECIAL', 40), ($this->line)('FUEL', 50), ($this->line)('FUEL_RATE', 95)];
    $preview = $this->formulas->preview($lines, [], ['ctc_annual' => 600000, 'values' => ['CONV' => 1600, 'FUEL_RATE' => 12.5]]);
    $row = collect($preview['rows'])->keyBy('code');
    expect($preview['errors'])->toBe([])->and($row['FUEL_RATE']['source'])->toBe('input')->and($row['FUEL_RATE']['monthly'])->toBeNull()
        ->and($row['FUEL_RATE']['note'])->toContain('12.50')->and($row['FUEL']['monthly'])->toBe(1250.0);

    // My compensation lists only the amounts that are paid.
    $snapshot = app(CompensationOutput::class)->on($employee);
    expect((new MyCompensation)->paidValues($snapshot))->toBe(['CONV' => 1600.0])
        ->and(SalaryImports::fields())->toHaveKey('component.FUEL_RATE');
});

it('refuses a formula override on an input and an input missing from the structure', function () {
    $rate = icxComponent('KM_RATE', 'input', ['include_in_ctc' => false]);
    $travel = icxComponent('TRAVEL', 'formula', ['formula' => 'km_rate * 20', 'include_in_ctc' => false]);

    $missing = $this->formulas->check([($this->line)('BASIC', 10), ['component' => $travel, 'formula_override' => null, 'sort_order' => 20]]);
    expect($missing['errors'][0])->toContain('KM_RATE, which is not in this structure');

    $override = $this->formulas->check([['component' => $rate, 'formula_override' => '15', 'sort_order' => 10], ['component' => $travel, 'formula_override' => null, 'sort_order' => 20]]);
    expect($override['errors'])->toBe(['KM_RATE is an input taken from each employee\'s compensation; remove its formula override.']);

    $fine = $this->formulas->check([['component' => $travel, 'formula_override' => null, 'sort_order' => 10], ['component' => $rate, 'formula_override' => null, 'sort_order' => 90]]);
    expect($fine['errors'])->toBe([]);
});

it('gives a balancing component its remainder formula and refuses one that cannot balance to CTC', function () {
    $flexi = icxComponent('FLEXI', 'fixed', ['sort_order' => 90]);
    $basic = ($this->component)('BASIC');
    $hra = ($this->component)('HRA');
    $structure = $this->structures->create(['name' => 'Flexi', 'code' => 'FLEXI_PAY', 'effective_from' => '2026-01-01', 'balancing_component_id' => $flexi->id,
        'components' => [['salary_component_id' => $basic->id, 'sort_order' => 10], ['salary_component_id' => $hra->id, 'sort_order' => 20], ['salary_component_id' => $flexi->id, 'sort_order' => 30]]], $this->preparer);
    $draft = $structure->versions()->firstOrFail();
    expect($draft->components()->where('salary_component_id', $flexi->id)->value('formula_override'))->toBe('max(0, ctc_monthly - basic - hra - pf_employer)');

    $preview = $this->formulas->preview($draft->formulaLines(), [], ['ctc_annual' => 600000] + $draft->formulaOptions());
    expect($preview['errors'])->toBe([])->and($preview['totals']['difference'])->toBe(0.0);

    // A CTC component calculated after the balancing one, a component outside CTC, a formula that ignores CTC.
    $rows = fn (array $sorts, array $overrides = []) => collect([$basic, $hra, $flexi])->map(fn ($c, $i) => ['salary_component_id' => $c->id, 'sort_order' => $sorts[$i], 'formula_override' => $overrides[$c->code] ?? null])->all();
    expect(fn () => $this->structures->updateDraft($draft, ['components' => $rows([10, 40, 30], ['FLEXI' => 'max(0, ctc_monthly - basic)'])], $this->preparer))
        ->toThrow(CompensationRuleViolation::class, 'HRA is part of CTC but calculated after the balancing component FLEXI');
    expect(fn () => $this->structures->updateDraft($draft->refresh(), ['components' => $rows([10, 20, 30], ['FLEXI' => '5000'])], $this->preparer))
        ->toThrow(CompensationRuleViolation::class, 'FLEXI balances to CTC, so its formula must work from ctc_monthly');
    $bonus = ($this->component)('BONUS');
    expect($this->formulas->check([($this->line)('BASIC', 10), ['component' => $bonus, 'formula_override' => null, 'sort_order' => 20]], [], ['balancing_component_id' => $bonus->id])['errors'])
        ->toContain('BONUS cannot balance to CTC: choose an earning that is part of CTC and calculated every month.');
    expect(fn () => $this->structures->updateDraft($draft->refresh(), ['balancing_component_id' => $flexi->id, 'ctc_mode' => 'components'], $this->preparer))
        ->toThrow(CompensationRuleViolation::class, 'no CTC to balance');
});

it('works out CTC from component amounts in a structure built without CTC and pays the amounts', function () {
    $wage = icxComponent('WAGE', 'fixed', ['classification' => 'basic', 'pf_applicable' => true, 'sort_order' => 10]);
    $conv = ($this->component)('CONV');
    icxStructure(['name' => 'Daily rated', 'code' => 'DAILY', 'effective_from' => '2026-01-01', 'ctc_mode' => 'components',
        'components' => [['salary_component_id' => $wage->id, 'sort_order' => 10], ['salary_component_id' => $conv->id, 'sort_order' => 20]]]);

    $employee = employeeWithUser(null, ['task.view']);
    forceLifecycle($employee, LifecycleState::Active, ['joining_date' => '2025-01-01']);
    $row = compensate($employee, 1, '2026-01-01', ['WAGE' => 20000, 'CONV' => 1600], 'hire', 'test', 'DAILY');
    $employerPf = app(StatutoryEngine::class)->employerPfOn(20000, '2026-01-01', true);
    expect($employerPf)->toBeGreaterThan(0.0)
        ->and((float) EmployeeSalaryAssignment::query()->find($row->id)->ctc_annual)->toBe(round((20000 + 1600 + $employerPf) * 12, 2));

    $c = app(PayrollCalculator::class)->calculate($employee->refresh(), PayrollPeriod::for($this->company, 2026, 9));
    expect($c->amount('WAGE'))->toBe(20000.0)->and($c->amount('CONV'))->toBe(1600.0);

    // No balancing warning in the preview, and CTC is the worked-out figure.
    $version = SalaryStructure::query()->where('code', 'DAILY')->firstOrFail()->versionOn('2026-09-01');
    $preview = $this->formulas->preview($version->formulaLines(), [], ['values' => ['WAGE' => 20000, 'CONV' => 1600], 'on' => '2026-01-01'] + $version->formulaOptions());
    expect(collect($preview['warnings'])->filter(fn ($w) => str_contains($w, 'monthly CTC'))->all())->toBe([])
        ->and($preview['totals']['ctc_monthly'])->toBe(round(20000 + 1600 + $employerPf, 2))->and($preview['totals']['ctc_mode'])->toBe('components');

    // Formulas in such a structure cannot use CTC.
    expect(fn () => icxStructure(['name' => 'Broken', 'code' => 'BROKEN', 'effective_from' => '2026-01-01', 'ctc_mode' => 'components',
        'components' => [['salary_component_id' => ($this->component)('BASIC')->id, 'sort_order' => 10]]]))->toThrow(CompensationRuleViolation::class, 'built from component amounts');
});

it('sets the CTC mode and balancing component on the draft screen', function () {
    $version = $this->structures->newVersion(SalaryStructure::query()->where('code', 'STANDARD')->firstOrFail(), $this->hr, '2026-10-01');
    $special = ($this->component)('SPECIAL');

    Livewire::test(VersionsRelationManager::class, ['ownerRecord' => SalaryStructure::query()->where('code', 'STANDARD')->firstOrFail(), 'pageClass' => EditSalaryStructure::class])
        ->callAction(TestAction::make('edit')->table($version), ['ctc_mode' => 'ctc', 'balancing_component_id' => $special->id])
        ->assertHasNoFormErrors()->assertNotified('Draft saved');

    expect($version->refresh()->formulaOptions())->toBe(['ctc_mode' => 'ctc', 'balancing_component_id' => $special->id]);
});
