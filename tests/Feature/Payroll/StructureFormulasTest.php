<?php

use App\Domain\Compensation\Exceptions\CompensationRuleViolation;
use App\Domain\Compensation\Models\SalaryStructure;
use App\Domain\Compensation\Services\CompensationStructures;
use App\Domain\Employment\Models\EmployeePosition;
use App\Domain\Organisation\Models\Grade;
use App\Domain\Organisation\Models\Location;
use App\Domain\Payroll\Models\PayrollPeriod;
use App\Domain\Payroll\Models\SalaryComponent;
use App\Domain\Payroll\Services\FormulaEngine;
use App\Domain\Payroll\Services\PayrollCalculator;
use App\Domain\Payroll\Services\StructureFormulas;
use App\Domain\Payroll\Support\FormulaVariables;
use App\Filament\Resources\SalaryStructures\Pages\EditSalaryStructure;
use App\Filament\Resources\SalaryStructures\RelationManagers\VersionsRelationManager;
use App\Filament\Support\SalaryFormulaUi;
use Filament\Actions\Testing\TestAction;
use Livewire\Livewire;

require_once __DIR__.'/../Workflow/WorkflowTestHelpers.php';
require_once __DIR__.'/PayrollTestHelpers.php';

/* §30 formula flexibility: structure parameters, employee values, the draft check, the breakup preview and the guide. */

beforeEach(function () {
    $this->travelTo('2026-09-21 09:00:00');
    syncComplianceRules();
    $this->tenant = provisionTenant();
    actAsTenant($this->tenant);
    $this->admin = tenantUser($this->tenant, ['*']);
    $this->actingAs($this->admin);
    $this->company = payrollCompany();
    $this->preparer = tenantUser($this->tenant, ['compensation.configure']);
    $this->approver = tenantUser($this->tenant, ['compensation.approve']);
    $this->structures = app(CompensationStructures::class);
    $this->formulas = app(StructureFormulas::class);
    $this->standard = SalaryStructure::query()->where('code', 'STANDARD')->firstOrFail();
    $this->component = fn (string $code) => SalaryComponent::query()->where('code', $code)->firstOrFail();
    $this->line = fn (string $code, int $sort, ?string $override = null) => ['component' => ($this->component)($code), 'formula_override' => $override, 'sort_order' => $sort];
});

/** The STANDARD draft's rows with overrides by code, and extra rows. */
function standardRows($version, array $overrides = [], array $extra = []): array
{
    $rows = $version->components()->with('component')->get()
        ->map(fn ($c) => ['formula_override' => $overrides[$c->component->code] ?? $c->formula_override] + $c->only(['salary_component_id', 'pay_nature', 'frequency', 'sort_order']))->all();

    return [...$rows, ...$extra];
}

it('documents every engine function and keeps variable names out of component codes', function () {
    expect(array_keys(FormulaVariables::FUNCTIONS))->toEqualCanonicalizing(array_keys(FormulaEngine::FUNCTIONS))
        ->and(FormulaVariables::reserved())->toContain('ctc_monthly', 'grade', 'lookup')
        ->and(array_intersect(FormulaVariables::reserved(), array_map('strtolower', array_column(config('peopleos.payroll.defaults'), 'code'))))->toBe([]);

    foreach ([...FormulaVariables::PAY, ...FormulaVariables::EMPLOYEE] as $row) {
        expect(fn () => app(FormulaEngine::class)->validate(end($row)))->not->toThrow(RuntimeException::class);
    }
    foreach (FormulaVariables::RECIPES as [, $formula]) {
        app(FormulaEngine::class)->validate($formula);
    }
});

it('checks a structure: unknown names with a suggestion, order, missing components, statutory codes and parameters', function () {
    $check = $this->formulas->check([
        ($this->line)('BASIC', 10, 'ctc_monthly * basic_pct / 100'),
        ($this->line)('HRA', 20, 'basci * 0.5'),
        ($this->line)('CONV', 30, 'special * 0.1'),
        ($this->line)('SPECIAL', 40, 'max(0, ctc_monthly - basic - hra - bonus - pf_ee)'),
    ], ['basic_pct' => 50, 'ctc_monthly' => 1, 'hra' => 2, '9x' => 1]);

    expect($check['errors'])->toContain(
        'HRA uses an unknown name “basci”; did you mean “basic”? A formula can use the variables in the formula guide, the structure parameters and the components above it.',
        'CONV uses SPECIAL, which is calculated after it. Give SPECIAL a lower sort order than CONV.',
        'SPECIAL uses BONUS, which is not in this structure. Add it above SPECIAL.',
        'Parameter “ctc_monthly” is a reserved name; choose another.',
        'Parameter “hra” has the same name as the component HRA; choose another.',
        'Parameter “9x”: use lowercase letters, digits and _, starting with a letter.',
    )->and(collect($check['errors'])->first(fn ($e) => str_starts_with($e, 'SPECIAL uses PF_EE')))->toContain('compliance engine');

    $ok = $this->formulas->check([($this->line)('BASIC', 10), ($this->line)('HRA', 20), ($this->line)('CONV', 30), ($this->line)('SPECIAL', 40)]);
    expect($ok)->toBe(['errors' => [], 'warnings' => []]);

    // pf_employer counts only the PF wages above it.
    $basic = ($this->component)('BASIC');
    $warn = $this->formulas->check([($this->line)('SPECIAL', 10, 'max(0, ctc_monthly - pf_employer)'), ['component' => $basic, 'formula_override' => '1000', 'sort_order' => 20]]);
    expect($warn['errors'])->toBe([])->and($warn['warnings'][0])->toContain('BASIC is PF wages and comes later');
});

it('stops a draft with formula mistakes when it is saved, and accepts parameters that it versions and copies', function () {
    $draft = $this->structures->newVersion($this->standard, $this->preparer, '2026-10-01');

    expect(fn () => $this->structures->updateDraft($draft, ['components' => standardRows($draft, ['HRA' => 'basic * hra_pct / 100'])], $this->preparer))
        ->toThrow(CompensationRuleViolation::class, 'HRA uses an unknown name “hra_pct”');
    expect($draft->refresh()->components()->whereNotNull('formula_override')->count())->toBe(0); // rolled back

    expect(fn () => $this->structures->updateDraft($draft, ['parameters' => ['basic_pct' => 'half']], $this->preparer))
        ->toThrow(CompensationRuleViolation::class, 'Parameter “basic_pct” needs a number');

    $this->structures->updateDraft($draft, ['parameters' => ['Basic_Pct' => '50', 'hra_pct' => 40, '' => ''], 'components' => standardRows($draft, ['BASIC' => 'ctc_monthly * basic_pct / 100', 'HRA' => 'basic * hra_pct / 100'])], $this->preparer);
    expect($draft->refresh()->parameters)->toEqual(['basic_pct' => 50, 'hra_pct' => 40]);

    $this->structures->submit($draft, $this->preparer);
    $approved = $this->structures->approve($draft, $this->approver);
    expect(fn () => $approved->update(['parameters' => ['basic_pct' => 60]]))->toThrow(CompensationRuleViolation::class, 'parameters');

    $next = $this->structures->newVersion($this->standard, $this->preparer, '2026-11-01');
    expect($next->parameters)->toEqual(['basic_pct' => 50, 'hra_pct' => 40]);
});

it('pays with structure parameters, employee values and overrides on fixed components', function () {
    $employee = salariedEmployee(600000, ['task.view'], '2026-01-01');
    $grade = Grade::factory()->create(['code' => 'G2', 'name' => 'G2', 'rank' => 6]);
    $location = Location::query()->first() ?? Location::factory()->create();
    $location->update(['city' => 'Mumbai']);
    EmployeePosition::query()->where('employee_id', $employee->id)->update(['grade_id' => $grade->id, 'location_id' => $location->id]);

    $draft = $this->structures->newVersion($this->standard, $this->preparer, '2026-09-01');
    $this->structures->updateDraft($draft, ['parameters' => ['basic_pct' => 50], 'components' => standardRows($draft, [
        'BASIC' => 'ctc_monthly * basic_pct / 100',
        'HRA' => 'basic * if(in(work_city, "Mumbai", "Delhi"), 0.50, 0.40)',
        'CONV' => 'lookup(grade, "G1", 1600, "G2", 2400, 3200) + if(grade_rank >= 5 && years_of_service >= 1, 100, 0)',
    ])], $this->preparer);
    $this->structures->approve($this->structures->submit($draft, $this->preparer), $this->approver);

    $c = app(PayrollCalculator::class)->calculate($employee->fresh(), PayrollPeriod::for($this->company, 2026, 9));

    expect($c->exceptions)->not->toContain(fn ($e) => $e['type'] === 'formula_error')
        ->and($c->amount('BASIC'))->toBe(25000.0)
        ->and($c->amount('HRA'))->toBe(12500.0)
        ->and($c->amount('CONV'))->toBe(2500.0) // the override replaces the fixed 1,600 on the compensation
        ->and($c->inputs['calculation_version'])->toBe('payroll-2.5');
});

it('previews the breakup the payroll calculator pays, with guardrail warnings', function () {
    $employee = salariedEmployee(600000, ['task.view'], '2026-01-01');
    $c = app(PayrollCalculator::class)->calculate($employee, PayrollPeriod::for($this->company, 2026, 9));
    $version = $this->structures->versionOn($this->standard, '2026-09-30');

    $preview = $this->formulas->preview($version->formulaLines(), [], ['ctc_annual' => 600000, 'values' => ['conv' => 1600], 'days' => 30, 'on' => '2026-09-30']);
    $rows = collect($preview['rows'])->keyBy('code');

    foreach (['BASIC', 'HRA', 'CONV', 'SPECIAL'] as $code) {
        expect($rows[$code]['monthly'])->toBe($c->amount($code));
    }
    expect($preview['errors'])->toBe([])
        ->and($preview['totals']['difference'])->toBe(0.0)
        ->and($preview['totals']['pf_employer'])->toBe($c->amount('PF_ER'))
        ->and($preview['totals']['wage_share'])->toBe(round(20000 / $preview['totals']['gross'] * 100, 1))
        ->and($preview['warnings'])->toHaveCount(1)
        ->and($preview['warnings'][0])->toContain('Code on Wages');

    // Errors and an unbalanced breakup show inline instead of failing the preview.
    $broken = $this->formulas->preview([($this->line)('BASIC', 10, 'ctc_monthly * 0.5'), ($this->line)('HRA', 20, 'lookup(grade, "G1", 100)')], [], ['ctc_annual' => 600000]);
    expect(collect($broken['rows'])->firstWhere('code', 'HRA')['error'])->toContain('no entry for ""')
        ->and($broken['errors'])->toContain('HRA: lookup() has no entry for "" and no final value for anything else.')
        ->and(collect($broken['warnings'])->first(fn ($w) => str_contains($w, 'less than monthly CTC')))->not->toBeNull();

    config(['peopleos.payroll.guardrails.wage_share' => null]);
    expect($this->formulas->preview($version->formulaLines(), [], ['ctc_annual' => 600000, 'values' => ['CONV' => 1600], 'on' => '2026-09-30'])['warnings'])->toBe([]);
});

it('shows the formula guide and the preview on the structure screen, and saves parameters from the draft form', function () {
    expect(SalaryFormulaUi::guideAction()->getModalContent()->render())
        ->toContain('How salary formulas work')->toContain('lookup(key, k1, v1, k2, v2, …, otherwise)')->toContain('PF_EE')->toContain('years_of_service');

    $version = $this->standard->versions()->sole();
    $result = $this->formulas->preview($version->formulaLines(), [], ['ctc_annual' => 600000, 'values' => ['CONV' => 1600], 'on' => '2026-09-30']);
    expect(view('filament.payroll.structure-preview', ['result' => $result, 'failure' => null])->render())
        ->toContain('Breakup total vs CTC')->toContain('20,000.00')->toContain('Special allowance')->toContain('Code on Wages');

    $draft = $this->structures->newVersion($this->standard, $this->admin, '2026-10-01');
    $screen = fn () => Livewire::test(VersionsRelationManager::class, ['ownerRecord' => $this->standard, 'pageClass' => EditSalaryStructure::class])->assertOk();
    $screen()->mountAction(TestAction::make('formulaGuide')->table())->assertActionMounted(TestAction::make('formulaGuide')->table());
    $screen()->mountAction(TestAction::make('preview')->table($version))->assertActionMounted(TestAction::make('preview')->table($version));
    $screen()->callAction(TestAction::make('edit')->table($draft), ['parameters' => ['basic_pct' => '50'], 'preview_ctc' => 900000])
        ->assertHasNoFormErrors()->assertNotified('Draft saved');

    expect($draft->refresh()->parameters)->toEqual(['basic_pct' => 50]);
});

it('works the Standard structure through for a sample CTC in the guide, and downloads the guide', function () {
    // 21 Sep 2026: the EPF wage ceiling is 25,000, so all of Basic is PF wages; ESI wages are above 21,000.
    $example = $this->formulas->example();
    expect(collect($example['preview']['rows'])->pluck('monthly', 'code')->all())->toEqual(['BASIC' => 20000.0, 'HRA' => 10000.0, 'CONV' => 1600.0, 'SPECIAL' => 16000.0])
        ->and($example['working'])->toBe(['BASIC' => '50000 * 0.40', 'HRA' => '20000 * 0.50', 'SPECIAL' => 'max(0, 50000 - 20000 - 10000 - 1600 - 2400)'])
        ->and($example['preview']['totals'])->toMatchArray(['gross' => 47600.0, 'pf_employer' => 2400.0, 'breakup' => 50000.0, 'difference' => 0.0])
        ->and($example['pf'])->toMatchArray(['base' => 20000.0, 'wage_ceiling' => 25000.0, 'employee' => 2400.0, 'employer' => 2400.0])
        ->and($example['esi'])->toMatchArray(['covered' => false, 'employee' => 0.0])
        ->and($example['take_home'])->toBe(45200.0)
        ->and($example['lop'])->toMatchArray(['paid_days' => 28, 'gross' => 44426.66])
        ->and($example['lop']['amounts']['BASIC'])->toBe(18666.67);

    expect(SalaryFormulaUi::guideDocument())->toStartWith('<!doctype html>')->toContain('Worked example: CTC 600,000.00 a year')
        ->toContain('max(0, 50000 - 20000 - 10000 - 1600 - 2400)')->toContain('45,200.00')->toContain('above the 21,000.00 ceiling')->toContain('lookup(key, k1, v1, k2, v2, …, otherwise)');

    Livewire::test(VersionsRelationManager::class, ['ownerRecord' => $this->standard, 'pageClass' => EditSalaryStructure::class])
        ->callAction([TestAction::make('formulaGuide')->table(), TestAction::make('downloadGuide')])->assertFileDownloaded('salary-formula-guide.html');
});
