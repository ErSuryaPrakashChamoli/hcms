<?php

use App\Domain\Audit\Models\AuditEvent;
use App\Domain\Compensation\Contracts\CompensationOutput;
use App\Domain\Compensation\Exceptions\CompensationRuleViolation;
use App\Domain\Compensation\Models\CompensationChange;
use App\Domain\Compensation\Models\EmployeeSalaryAssignment;
use App\Domain\Compensation\Models\SalaryStructure;
use App\Domain\Compensation\Services\CompensationStructures;
use App\Domain\Compensation\Services\SalaryAssignments;
use App\Domain\Compensation\Services\SalaryRevisions;
use App\Domain\Employment\Models\Employee;
use App\Domain\Experience\Services\Employee360;
use App\Domain\Lifecycle\Enums\LifecycleState;
use App\Domain\Payroll\Models\SalaryComponent;
use App\Filament\Pages\SalaryStructureRegister;
use App\Filament\Resources\Employees\EmployeeResource;
use App\Filament\Resources\Employees\Pages\ViewEmployee;
use App\Filament\Resources\Employees\RelationManagers\CompensationRelationManager;
use App\Filament\Support\Compensation\SalaryChangeWizard;
use App\Filament\Support\Compensation\SalaryFormat;
use App\Livewire\Compensation\CurrentSalary;
use Filament\Facades\Filament;
use Livewire\Livewire;

require_once __DIR__.'/../Workflow/WorkflowTestHelpers.php';
require_once __DIR__.'/../Payroll/PayrollTestHelpers.php';

/*
| Owner, 2026-11-27: "show the salary structure assigned to an employee with its breakup and the statutory details; make
| it easy to see who is assigned and who is left; revise from there with an audit trail and the whole history since
| joining; and just give a hike % on CTC, gross or net pay and let the structure work it out."
| Employee 360 → Current salary (App\Livewire\Compensation\CurrentSalary), the shared salary change wizard
| (SalaryChangeWizard), SalaryRevisions (the gross / net solver, before → after, the salary story) and the register's
| Assigned / Not assigned cards.
*/

beforeEach(function () {
    $this->travelTo('2026-09-01 10:00:00');
    syncComplianceRules();
    $this->tenant = provisionTenant();
    actAsTenant($this->tenant);
    $this->hr = tenantUser($this->tenant, ['*']);
    $this->actingAs($this->hr);
    Filament::getPanel('admin')->boot();
    $this->company = payrollCompany();
    $this->standard = SalaryStructure::query()->where('code', 'STANDARD')->firstOrFail();
    $this->employee = salariedEmployee(600000, ['task.view', 'compensation.self']);
    $this->travelTo('2026-10-15 10:00:00');
    $this->joiner = employeeWithUser();
    forceLifecycle($this->joiner, LifecycleState::Active, ['joining_date' => '2026-10-01']);
    $this->revisions = app(SalaryRevisions::class);
    $this->output = app(CompensationOutput::class);
});

/** The salary change wizard's answers. */
function csRevise(array $overrides = []): array
{
    return $overrides + ['change_type' => 'revision', 'effective_from' => '2026-11-01', 'salary_structure_id' => 'keep', 'basis' => 'ctc', 'ctc_mode' => 'percent',
        'currency' => 'INR', 'reason' => 'Annual increment', 'submit' => true];
}

/** What a compensation gives a month on a date: gross and net pay. */
function csFigures(Employee $employee, float $ctc, string $on = '2026-11-01'): array
{
    $revisions = app(SalaryRevisions::class);
    $segment = app(SalaryAssignments::class)->segment($employee, ['change_type' => 'revision', 'effective_from' => $on, 'salary_structure_id' => test()->standard->id,
        'ctc_annual' => $ctc, 'currency' => 'INR', 'component_values' => ['CONV' => 1600]]);
    $figures = $revisions->figures($employee, $segment);

    return ['gross' => (float) $figures['preview']['totals']['gross'], 'net' => (float) $figures['estimate']['take_home']];
}

// ---- Revising on CTC, gross or net pay ------------------------------------------------------------------------------

it('passes a CTC revision through as before and records its percentage', function () {
    $resolved = $this->revisions->resolve($this->employee, csRevise(['increase_percent' => 10]));
    expect($resolved['solved'])->toBeNull()->and($resolved['input'])->toMatchArray(['ctc_mode' => 'percent', 'increase_percent' => 10]);
    expect(app(SalaryAssignments::class)->check($this->employee, $resolved['input'], $this->hr))->toMatchArray(['ctc_annual' => 660000.0, 'increase_percent' => 10.0]);

    // A new amount on CTC records the percentage it comes to.
    $amount = $this->revisions->resolve($this->employee, csRevise(['ctc_mode' => 'amount', 'ctc_annual' => 630000]));
    expect($amount['input']['recorded_percent'])->toBe(5.0)
        ->and(app(SalaryAssignments::class)->check($this->employee, $amount['input'], $this->hr)['increase_percent'])->toBe(5.0);
});

it('works out the CTC that gives a hike on gross pay through the structure\'s own formulas', function () {
    $now = csFigures($this->employee, 600000);
    $resolved = $this->revisions->resolve($this->employee, csRevise(['basis' => 'gross', 'increase_percent' => 10]));
    $solved = $resolved['solved'];
    $target = round($now['gross'] * 1.1, 2);

    expect($solved)->toMatchArray(['basis' => 'gross', 'current' => round($now['gross'], 2), 'target' => $target, 'currency' => 'INR'])
        ->and(abs($solved['achieved'] - $target))->toBeLessThanOrEqual(0.5)
        ->and($resolved['input'])->toMatchArray(['ctc_mode' => 'amount', 'currency' => 'INR'])
        ->and($resolved['input']['ctc_annual'])->toBeGreaterThan(600000.0)
        // The CTC is the structure's: the breakup of that CTC gives the gross pay asked for.
        ->and(abs(csFigures($this->employee, $resolved['input']['ctc_annual'])['gross'] - $target))->toBeLessThanOrEqual(0.5)
        ->and($resolved['input']['recorded_percent'])->toBe(SalaryRevisions::percent(600000, $resolved['input']['ctc_annual']));
});

it('works out the CTC that gives a new net pay, and refuses what it cannot work out', function () {
    $resolved = $this->revisions->resolve($this->employee, csRevise(['basis' => 'net', 'ctc_mode' => 'amount', 'target_monthly' => 55000]));
    expect(abs($resolved['solved']['achieved'] - 55000))->toBeLessThanOrEqual(0.5)
        ->and(abs(csFigures($this->employee, $resolved['input']['ctc_annual'])['net'] - 55000))->toBeLessThanOrEqual(0.5);

    expect(fn () => $this->revisions->resolve($this->employee, csRevise(['basis' => 'net', 'ctc_mode' => 'amount', 'target_monthly' => null])))
        ->toThrow(CompensationRuleViolation::class, 'Enter the new net pay (take-home) (a month).')
        ->and(fn () => $this->revisions->resolve($this->joiner, csRevise(['basis' => 'gross', 'increase_percent' => 5, 'salary_structure_id' => $this->standard->id])))
        ->toThrow(CompensationRuleViolation::class, 'No salary is in force on 1 Nov 2026 to give a hike on')
        ->and(fn () => $this->revisions->resolve($this->employee, csRevise(['basis' => 'gross', 'increase_percent' => -100])))
        ->toThrow(CompensationRuleViolation::class, 'above -100');
});

it('scales the component amounts of a structure built from them to the CTC asked for', function () {
    $preparer = tenantUser($this->tenant, ['compensation.configure']);
    $approver = tenantUser($this->tenant, ['compensation.approve']);
    $wage = SalaryComponent::create(['name' => 'Wage', 'code' => 'WAGE', 'type' => 'earning', 'classification' => 'basic', 'calculation_method' => 'fixed', 'taxable' => true,
        'pf_applicable' => true, 'esi_applicable' => false, 'include_in_ctc' => true, 'include_in_gross' => true, 'is_recurring' => true, 'is_proratable' => false, 'sort_order' => 10, 'status' => 'active']);
    $structures = app(CompensationStructures::class);
    $daily = $structures->create(['name' => 'Daily rated', 'code' => 'DAILY', 'effective_from' => '2025-01-01', 'ctc_mode' => 'components',
        'components' => [['salary_component_id' => $wage->id, 'sort_order' => 10], ['salary_component_id' => SalaryComponent::query()->where('code', 'CONV')->value('id'), 'sort_order' => 20]]], $preparer);
    $structures->submit($daily->versions()->firstOrFail(), $preparer);
    $structures->approve($daily->versions()->firstOrFail(), $approver);
    $worker = employeeWithUser();
    forceLifecycle($worker, LifecycleState::Active, ['joining_date' => '2025-01-01']);
    compensate($worker, 1, '2025-01-01', ['WAGE' => 20000, 'CONV' => 1600], 'hire', 'test', 'DAILY');
    $before = $this->output->on($worker, '2026-11-01')->ctcAnnual;

    $resolved = $this->revisions->resolve($worker, csRevise(['increase_percent' => 10]));
    $proposal = app(SalaryAssignments::class)->check($worker, $resolved['input'], $this->hr);
    expect($resolved['solved']['basis'])->toBe('ctc')
        ->and(abs($proposal['ctc_annual'] - round($before * 1.1, 2)))->toBeLessThanOrEqual(1.0)
        ->and($proposal['component_values']['WAGE'])->toBeGreaterThan(20000.0)
        ->and(round($proposal['component_values']['CONV'] / $proposal['component_values']['WAGE'], 3))->toBe(round(1600 / 20000, 3));
});

// ---- Before → after, and the salary story ---------------------------------------------------------------------------

it('compares every line before and after with its change and percentage', function () {
    $data = SalaryChangeWizard::previewData($this->employee, csRevise(['basis' => 'gross', 'increase_percent' => 10]), 'revise');
    expect($data['failure'])->toBeNull()->and($data['solved']['basis'])->toBe('gross');
    $compare = $data['compare'];
    $rows = collect($compare['sections'])->flatMap(fn ($s) => collect($s['rows'])->map(fn ($r) => $r + ['section' => $s['key']]))->keyBy(fn ($r) => $r['code'] ?? $r['label']);

    expect($compare['first'])->toBeFalse()->and(collect($compare['sections'])->pluck('key')->all())->toBe(['earnings', 'deductions', 'employer', 'ctc'])
        ->and($rows['BASIC']['before'])->toBe(20000.0)
        ->and($rows['BASIC']['percent'])->toBe(SalaryRevisions::percent(20000, $rows['BASIC']['after']))
        ->and($rows['CONV']['change'])->toBe(0.0)
        ->and(abs($rows['Gross pay']['percent'] - 10))->toBeLessThan(0.01)
        ->and($compare['headline']['ctc'])->toBe($rows['CTC a year']['percent'])
        ->and($rows->has('Net pay (take-home, before TDS)'))->toBeTrue()
        // No verified rule today: net pay is said to be before the statutory deductions.
        ->and($compare['complete'])->toBeFalse()->and($compare['notes'])->toContain('Statutory deductions with no verified rule are not estimated; net pay is shown before them.');
});

it('shows the salary in force and the new one right under the hike, as it is typed', function () {
    // Owner, 2026-11-28: the comparison belongs where the hike is decided, not only on the next step.
    // Before a hike is typed: the salary in force alone, and what to do.
    $now = SalaryChangeWizard::impact($this->employee, csRevise(['basis' => 'gross', 'reason' => null]), 'revise');
    $rows = collect($now['rows'])->keyBy('key');
    expect($now['hasBefore'])->toBeTrue()->and($now['hasAfter'])->toBeFalse()->and($now['hint'])->toContain('hike %')
        ->and($rows['ctc']['before'])->toBe(600000.0)
        ->and(abs($rows['gross']['before'] - csFigures($this->employee, 600000)['gross']))->toBeLessThan(0.01)
        ->and($rows['gross']['after'])->toBeNull();

    // A 10% hike on gross pay: in force → new with the change and its %; the gross row is the one the hike is on.
    // The reason is typed below the hike: the figures do not wait for it.
    $hike = SalaryChangeWizard::impact($this->employee, csRevise(['basis' => 'gross', 'increase_percent' => 10, 'reason' => null]), 'revise');
    $rows = collect($hike['rows'])->keyBy('key');
    expect($hike['failure'])->toBeNull()->and($hike['hasAfter'])->toBeTrue()->and($hike['solved']['basis'])->toBe('gross')
        ->and(abs($rows['gross']['percent'] - 10))->toBeLessThan(0.01)
        ->and($rows['gross']['given'])->toBeTrue()->and($rows['ctc']['given'])->toBeFalse()
        ->and($rows['ctc']['after'])->toBeGreaterThan(600000.0)
        ->and($rows['net']['change'])->toBe(round($rows['net']['after'] - $rows['net']['before'], 2));
    expect(view('filament.compensation.salary-change-impact', $hike)->render())
        ->toContain('data-salary-impact="both"')->toContain('Hike on this')->toContain('In force')->toContain('6,00,000');

    // A hike that cannot be given says why, with the salary in force still shown.
    $refused = SalaryChangeWizard::impact($this->employee, csRevise(['increase_percent' => -100]), 'revise');
    expect($refused['failure'])->toContain('above -100')->and($refused['hasAfter'])->toBeFalse();

    // A first salary: what it comes to, with nothing before it.
    $first = SalaryChangeWizard::impact($this->joiner, csRevise(['change_type' => 'hire', 'effective_from' => '2026-10-01', 'salary_structure_id' => $this->standard->id,
        'ctc_mode' => 'amount', 'ctc_annual' => 480000, 'component_values' => ['CONV' => 1600]]), 'assign');
    expect($first['hasBefore'])->toBeFalse()->and($first['failure'])->toBeNull()->and(collect($first['rows'])->firstWhere('key', 'ctc')['after'])->toBe(480000.0);

    // On Salary details in the wizard itself.
    Livewire::test(CurrentSalary::class, ['ownerRecord' => $this->employee, 'pageClass' => ViewEmployee::class])
        ->mountAction('reviseSalary')
        ->assertMountedActionModalSeeHtml('data-salary-impact="before"')
        ->fillForm(['basis' => 'gross', 'increase_percent' => 10])
        ->assertMountedActionModalSeeHtml('data-salary-impact="both"')->assertMountedActionModalSee('Hike on gross pay (%)');
});

it('tells the salary story since joining: each change, who approved it, and the analysis', function () {
    $this->travelTo('2026-01-01 09:00:00');
    $january = compensate($this->employee, 660000, '2026-01-01', ['CONV' => 1600], 'annual_increment', 'Annual increment');
    $this->travelTo('2026-07-01 09:00:00');
    $july = compensate($this->employee, 726000, '2026-07-01', ['CONV' => 1600], 'promotion', 'Promotion');
    $this->travelTo('2026-10-15 10:00:00');

    $history = $this->revisions->history($this->employee);
    $a = $history['analysis'];
    expect(collect($history['rows'])->map(fn ($r) => [$r['from']->toDateString(), $r['ctc'], $r['percent'], $r['months_since_previous']])->all())->toBe([
        ['2025-01-01', 600000.0, null, null], ['2026-01-01', 660000.0, 10.0, 12], ['2026-07-01', 726000.0, 10.0, 6],
    ])
        ->and($a)->toMatchArray(['count' => 3, 'revisions' => 2, 'increases' => 2, 'decreases' => 0, 'total_percent' => 21.0, 'total_change' => 126000.0, 'average_percent' => 10.0, 'months_since_last' => 3, 'average_gap_months' => 9.0])
        ->and($a['last_revised']->toDateString())->toBe('2026-07-01')->and($a['highest']['percent'])->toBe(10.0)
        ->and($a['yearly_percent'])->toBeGreaterThan(10.0)->toBeLessThan(13.0)
        ->and($a['series'])->toHaveCount(3)
        ->and(collect($history['rows'][2]['audit'])->pluck('step')->all())->toContain('Proposed', 'Reviewed', 'Approved')
        ->and(collect($history['rows'][2]['audit'])->firstWhere('step', 'Approved')['who'])->toBe('Comp Approver');

    // The history list's Change column and its line-by-line Compare.
    expect($this->revisions->changesByAssignment($this->employee)[$july->id])->toBe(['previous' => 660000.0, 'change' => 66000.0, 'percent' => 10.0]);
    $revision = $this->revisions->revision($this->employee, (int) $july->id);
    expect($revision['failure'])->toBeNull()->and($revision['compare']['first'])->toBeFalse()->and($revision['compare']['headline']['ctc'])->toBe(10.0);
    Livewire::test(CompensationRelationManager::class, ['ownerRecord' => $this->employee, 'pageClass' => ViewEmployee::class])
        ->assertOk()->assertSee('Salary history')->assertTableColumnStateSet('change', '+10.00%', $july)->assertTableColumnStateSet('change', null, EmployeeSalaryAssignment::query()->where('employee_id', $this->employee->id)->orderBy('effective_from')->first())
        ->mountTableAction('compare', $january)->assertSet('mountedActions.0.name', 'compare');
    $html = view('filament.compensation.salary-revision', ['revision' => $revision, 'full' => true])->render();
    expect($html)->toContain('Audit trail')->toContain('Comp Approver')->toContain('+10.00%');
});

// ---- Employee 360 → Current salary -----------------------------------------------------------------------------------

it('shows the salary in force with its breakup, statutory details and the analysis, as an audited read', function () {
    $panel = Livewire::test(CurrentSalary::class, ['ownerRecord' => $this->employee, 'pageClass' => ViewEmployee::class])->assertOk()
        ->assertSee('Salary assigned')->assertSee('Standard structure')->assertSee('₹6,00,000')->assertSee('₹50,000')->assertSee('Breakup a month')->assertSee('Basic')
        ->assertSee('Gross pay')->assertSee('Take-home')->assertSee('Total employer contributions')->assertSee('Where ₹50,000 a month goes')
        ->assertSee('data-statutory-chip="PF"', false)->assertSee('Provident fund')->assertSee('Revisions since joining')->assertSee('Not revised yet')
        ->assertActionVisible('reviseSalary')->assertActionVisible('correctSalary')->assertActionVisible('printBreakup')->assertActionHidden('assignSalary');
    expect($panel->instance()->salary['breakup']['headline']['ctc_annual'])->toBe(600000.0)
        ->and(AuditEvent::query()->where('entity_type', Employee::class)->where('entity_id', $this->employee->id)->where('reason', 'current_salary')->exists())->toBeTrue();

    // No salary yet: said first, with Assign salary only.
    Livewire::test(CurrentSalary::class, ['ownerRecord' => $this->joiner, 'pageClass' => ViewEmployee::class])->assertOk()
        ->assertSee('No salary assigned')->assertActionVisible('assignSalary')->assertActionHidden('reviseSalary')->assertActionHidden('correctSalary');

    // It leads the Rewards view, and the 360 card opens it.
    expect(EmployeeResource::VIEW_LISTS['rewards'][0])->toBe(CurrentSalary::class);
    // Rewards opens on it: its overview cards, which only pointed here and to the payslips, are not repeated.
    $this->get(EmployeeResource::getUrl('view', ['record' => $this->employee]))->assertOk()->assertSee('id="list-CurrentSalary"', false)->assertDontSee('data-pos-card="compensation"', false);
    expect(collect(app(Employee360::class)->for($this->hr, $this->joiner))->firstWhere('key', 'compensation')['facts']['Salary'])->toBe('Not assigned');
});

it('keeps Current salary to the people who may see compensation, and points the employee to My compensation', function () {
    $this->actingAs(tenantUser($this->tenant, ['employee.view', 'employee.sensitive.view', 'payroll.view', 'payroll.manage']));
    expect(CurrentSalary::canViewForRecord($this->employee, ViewEmployee::class))->toBeFalse();

    $this->actingAs($this->employee->user->fresh());
    Livewire::test(CurrentSalary::class, ['ownerRecord' => $this->employee, 'pageClass' => ViewEmployee::class])->assertOk()
        ->assertSee('Your salary and its breakup are on My compensation.')->assertDontSee('₹6,00,000')->assertActionHidden('reviseSalary');

    // A reader without the right to propose sees the salary, with no change actions.
    $this->actingAs(tenantUser($this->tenant, ['compensation.view', 'employee.view']));
    Livewire::test(CurrentSalary::class, ['ownerRecord' => $this->employee, 'pageClass' => ViewEmployee::class])->assertOk()
        ->assertSee('Breakup a month')->assertDontSee('data-statutory-chip', false)->assertActionHidden('reviseSalary')->assertActionHidden('correctSalary');
});

it('revises from Employee 360 on net pay and only proposes it', function () {
    $rows = EmployeeSalaryAssignment::query()->count();
    Livewire::test(CurrentSalary::class, ['ownerRecord' => $this->employee, 'pageClass' => ViewEmployee::class])
        ->callAction('reviseSalary', data: csRevise(['basis' => 'net', 'increase_percent' => 8]))
        ->assertNotified('Salary proposed and submitted for review')
        ->assertSee('A salary change is waiting')->assertActionHidden('reviseSalary')->assertActionHidden('correctSalary');

    $change = CompensationChange::query()->where('employee_id', $this->employee->id)->where('status', 'submitted')->sole();
    $net = csFigures($this->employee, 600000)['net'];
    expect($change->only(['status', 'change_type', 'proposed_by', 'approved_by']))->toBe(['status' => 'submitted', 'change_type' => 'revision', 'proposed_by' => $this->hr->id, 'approved_by' => null])
        ->and(abs(csFigures($this->employee, (float) $change->ctc_annual)['net'] - round($net * 1.08, 2)))->toBeLessThanOrEqual(0.5)
        ->and((float) $change->increase_percent)->toBe(SalaryRevisions::percent(600000, (float) $change->ctc_annual))
        ->and(EmployeeSalaryAssignment::query()->count())->toBe($rows)
        ->and($this->output->on($this->employee, '2026-11-15')->ctcAnnual)->toBe(600000.0);
});

it('edits the salary in force as a correction on its own date, whatever the form sends', function () {
    Livewire::test(CurrentSalary::class, ['ownerRecord' => $this->employee, 'pageClass' => ViewEmployee::class])
        ->callAction('correctSalary', data: csRevise(['ctc_mode' => 'amount', 'ctc_annual' => 612000, 'reason' => 'Offer letter said 6.12 lakh', 'change_type' => 'revision', 'effective_from' => '2030-01-01']))
        ->assertNotified('Salary proposed and submitted for review');

    $change = CompensationChange::query()->where('employee_id', $this->employee->id)->where('status', 'submitted')->sole();
    expect($change->change_type)->toBe('correction')->and($change->effective_from->toDateString())->toBe('2025-01-01')
        ->and((float) $change->ctc_annual)->toBe(612000.0)->and((float) $change->increase_percent)->toBe(2.0);
});

it('formats salary amounts in the currency\'s own grouping, with paise only when there are any', function () {
    expect(SalaryFormat::money(4800000.0))->toBe('₹48,00,000')->and(SalaryFormat::money(1600.5))->toBe('₹1,600.50')
        ->and(SalaryFormat::money(4800000.0, 'USD'))->toBe('$4,800,000')->and(SalaryFormat::money(null))->toBe('—')
        ->and(SalaryFormat::signed(-1600.0))->toBe('−₹1,600')->and(SalaryFormat::percent(10.0))->toBe('+10%')->and(SalaryFormat::percent(-2.5))->toBe('−2.5%');
});

// ---- The register: who is assigned and who is left -------------------------------------------------------------------

it('says on the register who has a salary and who is left, and names the one salary action for each', function () {
    $page = Livewire::test(SalaryStructureRegister::class)->assertOk()
        ->assertTableColumnStateSet('salary_in_force', 'Assigned', $this->employee)
        ->assertTableColumnStateSet('salary_in_force', 'Not assigned', $this->joiner)
        ->assertTableActionHasLabel('assign', 'Revise salary', $this->employee)
        ->assertTableActionHasLabel('assign', 'Assign salary', $this->joiner)
        ->assertTableActionVisible('correct', $this->employee)->assertTableActionHidden('correct', $this->joiner)
        ->assertTableActionHasUrl('viewSalary', EmployeeResource::getUrl('view', ['record' => $this->employee]).'#list-CurrentSalary', $this->employee);
    expect($page->instance()->inForceCounts())->toBe(['assigned' => 1, 'not_assigned' => 1]);

    $page->call('showInForce', 'no')->assertCanSeeTableRecords([$this->joiner])->assertCanNotSeeTableRecords([$this->employee]);
    $this->get(SalaryStructureRegister::getUrl())->assertOk()->assertSee('Salary assigned')->assertSee('Not assigned yet');

    // The register's Revise salary is the same wizard: a hike on gross pay.
    Livewire::test(SalaryStructureRegister::class)->callTableAction('assign', $this->employee, data: csRevise(['basis' => 'gross', 'increase_percent' => 5]))
        ->assertHasNoTableActionErrors()->assertNotified('Salary proposed and submitted for review');
    $change = CompensationChange::query()->where('employee_id', $this->employee->id)->where('status', 'submitted')->sole();
    expect(abs(csFigures($this->employee, (float) $change->ctc_annual)['gross'] - round(csFigures($this->employee, 600000)['gross'] * 1.05, 2)))->toBeLessThanOrEqual(0.5);
});
