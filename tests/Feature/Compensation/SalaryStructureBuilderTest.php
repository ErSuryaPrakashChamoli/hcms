<?php

use App\Domain\Compensation\Exceptions\CompensationRuleViolation;
use App\Domain\Compensation\Models\SalaryStructure;
use App\Domain\Compensation\Services\CompensationStructures;
use App\Domain\Compensation\Services\StructureBuilder;
use App\Domain\Compensation\Support\PlainEnglishRules;
use App\Domain\Compensation\Support\StructureRules;
use App\Domain\Organisation\Models\Company;
use App\Domain\Organisation\Models\Grade;
use App\Domain\Payroll\Models\SalaryComponent;
use App\Domain\Payroll\Services\StructureFormulas;
use App\Filament\Pages\SalaryStructureBuilder;
use App\Filament\Resources\SalaryStructures\Pages\CreateSalaryStructure;
use App\Filament\Resources\SalaryStructures\Pages\ListSalaryStructures;
use App\Filament\Support\SalaryFormulaUi;
use Filament\Facades\Filament;
use Livewire\Livewire;

require_once __DIR__.'/../Workflow/WorkflowTestHelpers.php';
require_once __DIR__.'/../Payroll/PayrollTestHelpers.php';

/* The salary structure builder: plain rules (picked or written in plain English) compiled to the formulas payroll runs. */

beforeEach(function () {
    $this->travelTo('2026-09-21 09:00:00');
    syncComplianceRules();
    $this->tenant = provisionTenant();
    actAsTenant($this->tenant);
    $this->admin = tenantUser($this->tenant, ['*']);
    $this->actingAs($this->admin);
    $this->company = payrollCompany();
    $this->approver = tenantUser($this->tenant, ['compensation.approve', 'compensation.view']);
    $this->builder = app(StructureBuilder::class);
    $this->id = fn (string $code) => (string) SalaryComponent::query()->where('code', $code)->value('id');
    $this->row = fn (string $component, string $how, array $extra = []) => ['component' => $component, 'how' => $how, 'nature' => 'fixed', 'frequency' => 'monthly'] + $extra;
});

it('reads the usual ways people describe salary components in plain English', function () {
    $read = fn (string $line) => PlainEnglishRules::read($line);

    expect($read('Basic is 50% of CTC'))->toMatchArray(['name' => 'Basic', 'how' => 'percent_ctc', 'value' => 50.0])
        ->and($read('HRA is 40% of Basic, at most 20,000'))->toMatchArray(['name' => 'HRA', 'how' => 'percent_of', 'value' => 40.0, 'of' => 'Basic', 'max' => 20000.0])
        ->and($read('Basic: 40% of CTC (minimum 15,000)'))->toMatchArray(['how' => 'percent_ctc', 'value' => 40.0, 'min' => 15000.0])
        ->and($read('Conveyance is a fixed Rs 1,600 a month'))->toMatchArray(['name' => 'Conveyance', 'how' => 'fixed', 'value' => 1600.0])
        ->and($read('Medical allowance 15000 per year'))->toMatchArray(['name' => 'Medical allowance', 'how' => 'fixed', 'value' => 1250.0])
        ->and($read('Phone 1.2k'))->toMatchArray(['how' => 'fixed', 'value' => 1200.0])
        ->and($read('Performance bonus is 10% of CTC, variable'))->toMatchArray(['how' => 'percent_ctc', 'nature' => 'variable'])
        ->and($read('Food coupons is entered for each employee'))->toMatchArray(['how' => 'per_employee'])
        ->and($read('Special allowance is whatever is left of the CTC'))->toMatchArray(['name' => 'Special allowance', 'how' => 'balance'])
        ->and($read('The rest goes to Special allowance'))->toMatchArray(['name' => 'Special allowance', 'how' => 'balance'])
        ->and($read('50% of CTC as Basic'))->toMatchArray(['name' => 'Basic', 'how' => 'percent_ctc', 'value' => 50.0])
        ->and($read('Leave travel allowance is half of basic'))->toMatchArray(['how' => 'percent_of', 'value' => 50.0, 'of' => 'Basic'])
        ->and($read('Gratuity is 4.81% of Basic'))->toMatchArray(['how' => 'percent_of', 'type' => 'employer_contribution'])
        ->and($read('Canteen deduction 500'))->toMatchArray(['how' => 'fixed', 'type' => 'deduction'])
        ->and($read('Overtime = if(grade == "M2", 5000, 0)'))->toMatchArray(['how' => 'formula', 'formula' => 'if(grade == "M2", 5000, 0)'])
        ->and($read('Bonus 10%'))->toMatchArray(['how' => 'percent_ctc', 'note' => 'No “of …” given, so it is taken as a % of CTC.']);

    // Statutory lines are payroll's; gross is not something to take a share of; daily amounts need a formula.
    expect($read('PF 12% of basic')['skip'])->toContain('worked out by payroll')
        ->and($read('Professional tax')['skip'])->toContain('Professional tax')
        ->and($read('Basic 50% of gross')['error'])->toContain('Gross and net pay are added up')
        ->and($read('Shift allowance 200 per day')['error'])->toContain('custom formula')
        ->and($read('Basic is quite generous')['error'])->toContain('Could not tell how Basic is worked out');

    // A floor or a cap said as "the higher / lower of", "whichever is higher" or max(…) / min(…); a formula is never a name.
    expect($read('Max( Basic 50% of CTC, 15000)'))->toMatchArray(['name' => 'Basic', 'how' => 'percent_ctc', 'value' => 50.0, 'min' => 15000.0])
        ->and($read('Basic is max of 50% of CTC or 15000'))->toMatchArray(['how' => 'percent_ctc', 'value' => 50.0, 'min' => 15000.0])
        ->and($read('Basic is 40% of CTC or 15,000, whichever is higher'))->toMatchArray(['how' => 'percent_ctc', 'value' => 40.0, 'min' => 15000.0])
        ->and($read('HRA is the lower of 50% of basic and 20,000'))->toMatchArray(['how' => 'percent_of', 'of' => 'Basic', 'max' => 20000.0])
        ->and($read('Basic = min(50% of CTC, 90000)'))->toMatchArray(['how' => 'percent_ctc', 'max' => 90000.0])
        ->and($read('max(basic * 0.5, 15000)'))->toMatchArray(['name' => null])
        ->and($read('Leave travel allowance (LTA) is 1600'))->toMatchArray(['name' => 'Leave travel allowance (LTA)', 'how' => 'fixed']);
    expect(StructureBuilder::isName('Medical allowance'))->toBeTrue()
        ->and(StructureBuilder::isName('Max( Basic'))->toBeFalse()
        ->and(StructureBuilder::isName('basic * 2'))->toBeFalse()
        ->and(StructureBuilder::isName('50% of CTC'))->toBeFalse();

    // A list on one line, and a formula's own commas, split the right way.
    expect(array_column(PlainEnglishRules::parse("Basic 50% of CTC, HRA 40% of basic and conveyance 1600\nOT = max(0, 1600)"), 'name'))
        ->toBe(['Basic', 'HRA', 'Conveyance', 'OT']);
});

it('writes each rule as a formula and reads saved formulas back as rules', function () {
    expect(StructureRules::formula(['how' => 'percent_ctc', 'value' => 50]))->toBe('ctc_monthly * 50 / 100')
        ->and(StructureRules::formula(['how' => 'percent_of', 'value' => 8.33, 'max' => 2000], 'basic'))->toBe('min(basic * 8.33 / 100, 2000)')
        ->and(StructureRules::formula(['how' => 'percent_ctc', 'value' => 40, 'min' => 15000, 'max' => 90000]))->toBe('clamp(ctc_monthly * 40 / 100, 15000, 90000)')
        ->and(StructureRules::formula(['how' => 'fixed', 'value' => 1600.0]))->toBe('1600')
        ->and(StructureRules::formula(['how' => 'per_employee']))->toBeNull();

    $variables = ['basic' => '7'];
    expect(StructureRules::fromFormula('ctc_monthly * 0.40', $variables))->toMatchArray(['how' => 'percent_ctc', 'value' => 40.0])
        ->and(StructureRules::fromFormula('basic * 0.50', $variables))->toMatchArray(['how' => 'percent_of', 'value' => 50.0, 'of' => '7'])
        ->and(StructureRules::fromFormula('max(ctc_monthly * basic_pct / 100, 15000)', $variables, ['basic_pct' => 45]))->toMatchArray(['how' => 'percent_ctc', 'value' => 45.0, 'min' => 15000.0])
        ->and(StructureRules::fromFormula('clamp(basic * 10 / 100, 500, 2000)', $variables))->toMatchArray(['how' => 'percent_of', 'min' => 500.0, 'max' => 2000.0])
        ->and(StructureRules::fromFormula('max(0, ctc_monthly - basic - hra - pf_employer)', $variables))->toMatchArray(['how' => 'balance'])
        ->and(StructureRules::fromFormula('1600', $variables))->toMatchArray(['how' => 'fixed', 'value' => 1600.0])
        ->and(StructureRules::fromFormula(null, $variables))->toMatchArray(['how' => 'per_employee'])
        ->and(StructureRules::fromFormula('lookup(grade, "G1", n, 2000)', $variables, ['n' => 1500]))->toMatchArray(['how' => 'formula', 'formula' => 'lookup(grade, "G1", 1500, 2000)']);

    // Each sentence reads back to the rule it came from.
    $names = ['1' => 'Basic', '2' => 'HRA'];
    foreach ([['component' => '1', 'how' => 'percent_ctc', 'value' => 40, 'min' => 15000], ['component' => '2', 'how' => 'percent_of', 'value' => 40, 'of' => '1', 'max' => 20000],
        ['component' => '2', 'how' => 'fixed', 'value' => 1600], ['component' => '2', 'how' => 'balance'], ['component' => '2', 'how' => 'per_employee', 'nature' => 'variable']] as $rule) {
        $read = PlainEnglishRules::read(StructureRules::sentence($rule, $names));
        expect($read['how'])->toBe($rule['how'])->and($read['error'])->toBeNull()
            ->and($read['nature'])->toBe($rule['nature'] ?? 'fixed');
        if (isset($rule['value'])) {
            expect($read['value'])->toEqual((float) $rule['value']);
        }
    }
});

it('matches written names to the component list and adds the ones it does not have', function () {
    $result = $this->builder->fromEnglish("Basic is 50% of CTC\nHouse rent allowance is 40% of basic\nTransport 1600\nMedical allowance is 15,000 a year\nPF 12% of basic\nSpecial allowance is the balance\nBonus is 20% of Gross");
    $rows = array_values($result['rows']);

    expect($this->builder->keyFor('House rent allowance (HRA)'))->toBe(($this->id)('HRA'))
        ->and(array_values($this->builder->fromEnglish('Basic = max(ctc_monthly * 0.5, 15000)')['rows'])[0])->toMatchArray(['how' => 'percent_ctc', 'value' => 50.0, 'min' => 15000.0]);

    expect(array_column($rows, 'component'))->toBe([($this->id)('BASIC'), ($this->id)('HRA'), ($this->id)('CONV'), 'new:Medical allowance', ($this->id)('SPECIAL')])
        ->and($rows[1]['of'])->toBe(($this->id)('BASIC'))
        ->and(array_column($result['report'], 'status'))->toBe(['ok', 'ok', 'ok', 'note', 'skipped', 'ok', 'error'])
        ->and($result['report'][3]['text'])->toContain('a new component')->toContain('paid as 1,250 a month');

    // A % of something not listed, a % of CTC when there is no CTC, and adding components without payroll setup access.
    expect($this->builder->fromEnglish('HRA is 40% of Basic')['report'][0]['text'])->toContain('which is not in your list')
        ->and($this->builder->fromEnglish('Special allowance is the rest', 'components')['report'][0]['text'])->toContain('no CTC to balance')
        ->and($this->builder->fromEnglish('Fuel card 3000', 'ctc', tenantUser($this->tenant, ['compensation.configure']))['report'][0]['text'])->toContain('needs payroll setup access');
});

it('puts components in calculation order, balances to CTC and previews a sample salary', function () {
    $rows = [
        'a' => ($this->row)(($this->id)('SPECIAL'), 'balance'),
        'b' => ($this->row)(($this->id)('HRA'), 'percent_of', ['value' => 40, 'of' => ($this->id)('BASIC')]),
        'c' => ($this->row)(($this->id)('BASIC'), 'percent_ctc', ['value' => 50]),
        'd' => ($this->row)(($this->id)('CONV'), 'fixed', ['value' => 1600]),
    ];
    $compiled = $this->builder->compile($rows);

    expect($compiled['problems'])->toBe([])
        ->and(collect($compiled['lines'])->map(fn ($l) => [$l['component']->code, $l['formula_override']])->all())->toBe([
            ['BASIC', 'ctc_monthly * 50 / 100'], ['HRA', 'basic * 40 / 100'], ['CONV', '1600'], ['SPECIAL', 'max(0, ctc_monthly - basic - hra - conv - pf_employer)'],
        ]);

    // 21 Sep 2026: employer PF is 12% of Basic up to the 25,000 wage ceiling.
    $preview = $this->builder->preview($rows, 'ctc', ['ctc' => 600000, 'pf' => true]);
    expect(collect($preview['rows'])->pluck('monthly', 'name')->all())->toEqual(['Basic' => 25000.0, 'House rent allowance' => 10000.0, 'Conveyance allowance' => 1600.0, 'Special allowance' => 10400.0])
        ->and($preview['totals'])->toMatchArray(['breakup' => 50000.0, 'difference' => 0.0, 'pf_employer' => 3000.0])
        ->and($preview['warnings'])->toBe([]);

    // Without the balancing component the breakup falls short, said in plain words.
    unset($rows['a']);
    expect($this->builder->preview($rows, 'ctc', ['ctc' => 600000])['warnings'][0])->toContain('13,400 less than the monthly CTC of 50,000');
});

it('says what is wrong in plain words', function () {
    $problems = fn (array $rows, string $mode = 'ctc') => $this->builder->compile($rows, $mode)['problems'];
    $basic = ($this->id)('BASIC');
    $hra = ($this->id)('HRA');
    $special = ($this->id)('SPECIAL');

    expect($problems([]))->toBe(['Add at least one component.'])
        ->and($problems([($this->row)($basic, 'percent_ctc', ['value' => 50]), ($this->row)($basic, 'fixed', ['value' => 1])]))->toContain('Basic is in the list twice; keep one row for it.')
        ->and($problems([($this->row)($basic, 'percent_ctc', ['value' => 150])]))->toBe(['Basic: a share of CTC cannot be more than 100%.'])
        ->and($problems([($this->row)($hra, 'percent_of', ['value' => 40, 'of' => $basic])]))->toBe(['House rent allowance is a % of Basic, which is not in the list.'])
        ->and($problems([($this->row)($basic, 'balance'), ($this->row)($special, 'balance')]))->toContain('Only one component can take whatever is left of the CTC; Basic and Special allowance all do.')
        ->and($problems([($this->row)($basic, 'percent_of', ['value' => 10, 'of' => $hra]), ($this->row)($hra, 'formula', ['formula' => 'basic * 2'])]))->toContain('Basic and House rent allowance depend on each other; one of them has to be worked out first.')
        ->and($problems([($this->row)($special, 'balance')], 'components'))->toContain('Special allowance: this structure adds up the CTC from component amounts, so there is no CTC to balance. Use a fixed amount, an amount per employee, or a % of another component.')
        ->and($problems([($this->row)($basic, 'per_employee')]))->toContain('Basic is worked out by its own formula in the component list, so it cannot be entered per employee. Choose another way, or pick a different component.')
        ->and($problems([($this->row)($basic, 'formula', ['formula' => 'ctc_monthly * 40%'])])[0])->toStartWith('Basic: ');
});

it('saves a new structure for one company, adds a new component, and sends it for approval', function () {
    $company = Company::factory()->create(['name' => 'Northwind Retail']);
    $rows = $this->builder->fromEnglish("Basic is 40% of CTC, at least 15,000\nHRA is 50% of Basic\nMedical allowance is 15,000 a year\nPerformance bonus is 10% of CTC, variable\nSpecial allowance is whatever is left of the CTC")['rows'];
    $version = $this->builder->save(['name' => 'Retail staff', 'company_id' => $company->id, 'effective_from' => '2026-10-01', 'ctc_mode' => 'ctc', 'rules' => $rows], null, $this->admin, submit: true);

    $structure = $version->structure;
    expect($structure->code)->toBe('RETAIL_STAFF')->and($version->status)->toBe('pending_approval')->and($version->company_id)->toBe($company->id)
        ->and($version->effective_from->toDateString())->toBe('2026-10-01')
        ->and($version->balancingComponentId())->toBe((int) ($this->id)('SPECIAL'));

    $medical = SalaryComponent::query()->where('code', 'MEDICAL_ALLOWANCE')->sole();
    $bonus = SalaryComponent::query()->where('code', 'PERFORMANCE_BONUS')->sole();
    expect($medical->only(['type', 'calculation_method', 'formula', 'taxable', 'pf_applicable', 'include_in_ctc', 'pay_nature']))
        ->toBe(['type' => 'earning', 'calculation_method' => 'formula', 'formula' => '1250', 'taxable' => true, 'pf_applicable' => false, 'include_in_ctc' => true, 'pay_nature' => 'fixed'])
        ->and($bonus->pay_nature)->toBe('variable')->and($bonus->is_proratable)->toBeFalse();

    expect($version->components()->with('component')->get()->map(fn ($c) => [$c->component->code, $c->formula_override, $c->pay_nature])->all())->toBe([
        ['BASIC', 'max(ctc_monthly * 40 / 100, 15000)', 'fixed'],
        ['HRA', 'basic * 50 / 100', 'fixed'],
        ['MEDICAL_ALLOWANCE', '1250', 'fixed'],
        ['PERFORMANCE_BONUS', 'ctc_monthly * 10 / 100', 'variable'],
        ['SPECIAL', 'max(0, ctc_monthly - basic - hra - medical_allowance - performance_bonus - pf_employer)', 'fixed'],
    ]);

    // Another person approves it, and it is the version in force from its date.
    app(CompensationStructures::class)->approve($version, $this->approver);
    expect($structure->versionOn('2026-10-15')?->id)->toBe($version->id)
        ->and(app(StructureFormulas::class)->preview($version->formulaLines(), [], ['ctc_annual' => 300000, 'ctc_mode' => 'ctc', 'balancing_component_id' => $version->balancingComponentId(), 'pf_applies' => false])['rows'][0]['monthly'])->toBe(15000.0);
});

it('changes an approved structure as a new draft from a later date, with a note of what changed', function () {
    $standard = SalaryStructure::query()->where('code', 'STANDARD')->firstOrFail();
    $state = $this->builder->load($standard);
    $rows = $state['rules'];

    expect(collect($rows)->map(fn ($r) => [$r['component'], $r['how'], $r['value']])->values()->all())->toBe([
        [($this->id)('BASIC'), 'percent_ctc', 40.0], [($this->id)('HRA'), 'percent_of', 50.0], [($this->id)('CONV'), 'per_employee', null], [($this->id)('SPECIAL'), 'balance', null],
    ])->and($state['effective_from'])->toBe('2026-09-01');

    $hra = collect($rows)->search(fn ($r) => $r['component'] === ($this->id)('HRA'));
    $rows[$hra]['value'] = 40;
    expect(fn () => $this->builder->save(['effective_from' => '1999-12-01', 'rules' => $rows] + $state, $standard, $this->admin))
        ->toThrow(CompensationRuleViolation::class, 'changes must start after that date');

    $draft = $this->builder->save(['rules' => $rows] + $state, $standard, $this->admin);
    expect($draft->version)->toBe(2)->and($draft->status)->toBe('draft')
        ->and($draft->change_note)->toBe('Changed: House rent allowance is 50% of Basic → House rent allowance is 40% of Basic.')
        ->and($standard->versions()->count())->toBe(2);

    // Saving again changes the same draft; a version waiting for approval cannot be changed.
    $again = $this->builder->save(['rules' => $rows] + $state, $standard, $this->admin, submit: true);
    expect($again->id)->toBe($draft->id)->and($again->status)->toBe('pending_approval')
        ->and(fn () => $this->builder->save(['rules' => $rows] + $state, $standard, $this->admin))->toThrow(CompensationRuleViolation::class, 'waiting for approval');
});

it('walks a first-time user through the five steps, in plain English, to a structure sent for approval', function () {
    Filament::getPanel('admin')->boot();
    $page = Livewire::test(SalaryStructureBuilder::class)->assertOk()->assertSee('Name and company')->assertSee('Download guide');

    $page->call('next')->assertHasErrors(['data.name'])
        ->set('data.name', 'Head office')->set('data.company_id', $this->company->id)->call('next')->assertSet('step', 1)
        ->call('next')->assertSet('step', 2)
        ->assertSet('data.english', "Basic is 50% of CTC\nHouse rent allowance is 40% of Basic\nSpecial allowance is whatever is left of the CTC");
    expect(count($page->get('data.rules')))->toBe(3);

    $page->set('data.input_mode', 'english')
        ->set('data.english', "Basic is 50% of CTC\nHRA is 40% of Basic\nFood coupons is entered for each employee\nSpecial allowance is the rest\nBasic is generous")
        ->call('next')->assertSet('step', 2)->assertSee('Some lines were not understood')->assertSee('Could not tell how Basic is worked out')
        ->set('data.english', "Basic is 50% of CTC\nHRA is 40% of Basic\nFood coupons is entered for each employee\nSpecial allowance is the rest")
        ->call('next')->assertSet('step', 3)->assertSee('Food coupons a month')->assertSee('Adds up')->assertSee('25,000');

    $page->call('next')->assertSet('step', 4)->assertSee('Special allowance is whatever is left of the CTC')->assertSee('New: added to your component list')
        ->call('sendForApproval')->assertNotified('Head office sent for approval')->assertRedirect();

    $version = SalaryStructure::query()->where('name', 'Head office')->sole()->versions()->sole();
    expect($version->status)->toBe('pending_approval')->and($version->company_id)->toBe($this->company->id)
        ->and(SalaryComponent::query()->where('code', 'FOOD_COUPONS')->value('calculation_method'))->toBe('fixed');
});

it('opens an existing structure, narrows it to grades, and keeps a structure waiting for approval read-only', function () {
    Filament::getPanel('admin')->boot();
    $standard = SalaryStructure::query()->where('code', 'STANDARD')->firstOrFail();
    $grade = Grade::factory()->create(['name' => 'G2']);

    Livewire::test(SalaryStructureBuilder::class, ['structureId' => $standard->id])->assertOk()->assertSet('reached', 4)
        ->assertSet('data.name', $standard->name)
        ->set('data.narrow_by', ['grade_id'])->set('data.grade_ids', [$grade->id])
        ->call('goTo', 4)->assertSee('Grade: G2')
        ->call('saveDraft')->assertNotified();

    $draft = $standard->versions()->getQuery()->where('status', 'draft')->sole();
    expect($draft->grade_ids)->toBe([$grade->id]);

    app(CompensationStructures::class)->submit($draft, $this->admin);
    Livewire::test(SalaryStructureBuilder::class, ['structureId' => $standard->id])->assertSee('Waiting for approval')
        ->call('saveDraft')->assertNotNotified();
});

it('opens the builder from the structure list, and keeps the old create link working', function () {
    Filament::getPanel('admin')->boot();
    Livewire::test(ListSalaryStructures::class)->assertOk()->assertActionHasUrl('create', SalaryStructureBuilder::getUrl());
    Livewire::test(CreateSalaryStructure::class)->assertRedirect(SalaryStructureBuilder::getUrl());

    $this->actingAs(tenantUser($this->tenant, ['payroll.view']));
    $this->get(SalaryStructureBuilder::getUrl())->assertForbidden();
});

it('explains the six ways and plain English in the guide, and downloads it', function () {
    Filament::getPanel('admin')->boot();
    expect(SalaryFormulaUi::guideAction()->getModalContent()->render())
        ->toContain('Set up a structure in five steps')->toContain('Six ways to work out a component')->toContain('Writing it in plain English')
        ->toContain('Examples for different companies')->toContain('For advanced users');
    expect(SalaryFormulaUi::guideDocument())->toContain('Print or save as PDF')->toContain('<title>Salary structure guide</title>');

    Livewire::test(SalaryStructureBuilder::class)->callAction('downloadGuide')->assertFileDownloaded(SalaryFormulaUi::GUIDE_FILE);

    // Every example in the guide reads cleanly.
    foreach (["Basic is 40% of CTC, at least 15,000\nHRA is 50% of Basic\nConveyance is a fixed 1,600 a month\nMedical allowance is 15,000 a year\nLTA is 8.33% of Basic\nSpecial allowance is whatever is left of the CTC",
        "Basic is 50% of CTC\nHRA is 40% of Basic\nGratuity is 4.81% of Basic (employer contribution)\nCanteen recovery is a fixed 500 a month (deduction)\nSpecial allowance is whatever is left of the CTC"] as $example) {
        expect(collect($this->builder->fromEnglish($example)['report'])->pluck('status')->intersect(['error', 'skipped'])->all())->toBe([]);
    }
});
