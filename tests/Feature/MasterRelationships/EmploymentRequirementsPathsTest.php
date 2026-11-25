<?php

use App\Domain\Compensation\Models\CompensationCycle;
use App\Domain\Compensation\Services\CompensationCycles;
use App\Domain\Configuration\Applicability\ApplicabilitySelection;
use App\Domain\Configuration\Applicability\EntityApplicability;
use App\Domain\Configuration\Models\Policy;
use App\Domain\Configuration\Models\PolicyAssignmentRule;
use App\Domain\Configuration\Services\Policies;
use App\Domain\Employment\Actions\AssignPositionAction;
use App\Domain\Employment\Actions\HireEmployeeAction;
use App\Domain\Employment\Imports\EmployeeImport;
use App\Domain\Employment\Imports\EmployeeImports;
use App\Domain\Employment\Imports\ImportTypes;
use App\Domain\Employment\Models\Employee;
use App\Domain\Employment\Requirements\EmploymentRequirements;
use App\Domain\Employment\Requirements\EmploymentRequirementsPreflight;
use App\Domain\Integration\Services\ApiKeys;
use App\Domain\Organisation\Models\Company;
use App\Domain\Organisation\Models\Department;
use App\Domain\Organisation\Models\Designation;
use App\Domain\Organisation\Models\Establishment;
use App\Domain\Organisation\Models\Grade;
use App\Domain\Organisation\Models\Location;
use App\Domain\Payroll\Services\PayrollReadiness;
use App\Domain\Payroll\Services\PayrollRunChecklist;
use App\Domain\Platform\Services\FeatureFlags;
use App\Filament\Pages\EmploymentRequirementsPage;
use App\Filament\Resources\Employees\Pages\ViewEmployee;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

/*
| Master relationships R2 through every path: the API (422 for a refusal, warnings in meta), the job-change and employee
| uploads (Block a row error before approval, Warn a note), the transfer screen, payroll readiness and the run checklist,
| increment previews (who a filter leaves out and why) and the pre-flight page.
*/

beforeEach(function () {
    $this->travelTo('2026-11-24 10:00:00');
    Storage::fake('local');
    config(['peopleos.documents.disk' => 'local']);
    $this->tenant = provisionTenant();
    actAsTenant($this->tenant);
    $this->admin = tenantUser($this->tenant, ['*']);
    $this->actingAs($this->admin);
    $this->mk = Company::factory()->create(['name' => 'Markedge Technologies', 'code' => 'MK']);
    $this->fe = Company::factory()->create(['name' => 'FynnEdge Advisory', 'code' => 'FE']);
    $this->grade = Grade::query()->create(['name' => 'T4', 'code' => 'T4']);
});

function erpPolicy(array $settings, array $conditions = []): Policy
{
    $policy = Policy::query()->create(['type' => EmploymentRequirements::TYPE, 'name' => 'Hire rules', 'code' => 'EDR']);
    app(Policies::class)->draft($policy, $settings);
    app(Policies::class)->publish($policy, '2026-01-01');
    PolicyAssignmentRule::query()->create(['policy_type' => EmploymentRequirements::TYPE, 'policy_id' => $policy->id, 'name' => 'Applicability', 'priority' => 100, 'conditions' => $conditions]);

    return $policy;
}

function erpHire(Company $company, array $position = [], string $date = '2026-11-02'): Employee
{
    return app(HireEmployeeAction::class)->handle(['first_name' => 'Asha', 'last_name' => 'Rao'], ['joining_date' => $date], ['company_id' => $company->id] + $position);
}

function erpUpload(string $type, string $csv, $user): EmployeeImport
{
    $path = EmployeeImports::directory().'/'.uniqid($type).'.csv';
    Storage::disk('local')->put($path, $csv);
    $import = ImportTypes::register($type, $path, "{$type}.csv", $user, ['mode' => 'both']);
    $service = ImportTypes::service($type);
    $service->inspect($import);
    $service->map($import->refresh(), $import->refresh()->mapping);

    return $service->validate($import->refresh());
}

it('answers 422 through the API for a Block refusal or a master that does not apply, and returns warnings in meta', function () {
    $policy = erpPolicy(['required_on_hire' => ['grade_id']], [['field' => 'company_id', 'operator' => 'in', 'value' => [$this->mk->id]]]);
    $designation = Designation::query()->create(['name' => 'Partner', 'code' => 'PTR']);
    app(EntityApplicability::class)->apply($designation, ApplicabilitySelection::single($this->fe->id), $this->admin);
    $key = app(ApiKeys::class)->issue('HRIS', ['employees.write', 'employees.read']);
    auth()->logout();
    actAsTenant(null);
    $payload = fn (array $position) => ['person' => ['first_name' => 'Kiran', 'last_name' => 'Rao'], 'employee' => ['joining_date' => '2026-11-30'], 'position' => ['company_code' => 'MK'] + $position];

    $warning = 'Grade is required for Markedge Technologies employees: pay bands, increments and salary formulas by grade read it (Employment data requirements: Hire rules, v1).';
    $this->withHeader('X-Api-Key', $key['plaintext'])->postJson('/api/v1/employees', $payload([]))
        ->assertCreated()->assertJsonPath('meta.requirement_warnings.0', $warning)
        // The same warning with its rule and field, as documented (RecordWithRequirementWarnings in docs/api/openapi.json).
        ->assertJsonPath('meta.requirement_warning_details', [['rule' => 'required', 'field' => 'grade_id', 'mode' => 'warn', 'message' => $warning]]);
    $this->withHeader('X-Api-Key', $key['plaintext'])->postJson('/api/v1/employees', $payload(['designation_code' => 'PTR', 'grade_code' => 'T4']))
        ->assertStatus(422)->assertJsonPath('code', 'master_not_applicable')->assertJsonPath('message', fn (string $m) => str_contains($m, 'Partner'))
        ->assertJsonMissingPath('requirements');

    actAsTenant($this->tenant);
    $this->actingAs($this->admin);
    app(EmploymentRequirementsPreflight::class)->switchToBlock($policy, ['required'], $this->admin, 'Block', acceptCurrentGaps: true);
    auth()->logout();
    actAsTenant(null);
    $blocked = 'Grade is required for Markedge Technologies employees: pay bands, increments and salary formulas by grade read it (Employment data requirements: Hire rules, v2).';
    $before = Employee::query()->withoutGlobalScopes()->count();
    $this->withHeader('X-Api-Key', $key['plaintext'])->postJson('/api/v1/employees', $payload([]))
        ->assertStatus(422)->assertJsonPath('message', $blocked)->assertJsonPath('code', 'employment_requirements')
        ->assertJsonPath('requirements', [['rule' => 'required', 'field' => 'grade_id', 'mode' => 'block', 'message' => $blocked]])
        ->assertJsonStructure(['message', 'code', 'request_id', 'requirements']);
    expect(Employee::query()->withoutGlobalScopes()->count())->toBe($before);   // refused: nothing written
    $this->withHeader('X-Api-Key', $key['plaintext'])->postJson('/api/v1/employees', $payload(['grade_code' => 'T4']))->assertCreated()->assertJsonMissingPath('meta');
});

it('answers the recruitment hand-over (pre-employees) with the same warnings and refusal codes', function () {
    $policy = erpPolicy(['required_on_hire' => ['grade_id']], [['field' => 'company_id', 'operator' => 'in', 'value' => [$this->mk->id]]]);
    $key = app(ApiKeys::class)->issue('RMS', ['rms.write']);
    auth()->logout();
    actAsTenant(null);
    $offer = fn (string $reference, array $position = []) => ['person' => ['first_name' => 'Meera', 'last_name' => 'Iyer'],
        'offer' => ['external_reference' => $reference, 'expected_joining_date' => '2026-12-01'], 'position' => ['company_code' => 'MK'] + $position];

    $this->withHeader('X-Api-Key', $key['plaintext'])->postJson('/api/v1/pre-employees', $offer('OFF-1'))
        ->assertCreated()->assertJsonPath('meta.requirement_warning_details.0.rule', 'required')
        ->assertJsonPath('meta.requirement_warning_details.0.field', 'grade_id')->assertJsonPath('meta.requirement_warning_details.0.mode', 'warn')
        ->assertJsonPath('meta.requirement_warnings.0', fn (string $m) => str_starts_with($m, 'Grade is required for Markedge Technologies employees'));

    actAsTenant($this->tenant);
    $this->actingAs($this->admin);
    app(EmploymentRequirementsPreflight::class)->switchToBlock($policy, ['required'], $this->admin, 'Block', acceptCurrentGaps: true);
    auth()->logout();
    actAsTenant(null);
    $this->withHeader('X-Api-Key', $key['plaintext'])->postJson('/api/v1/pre-employees', $offer('OFF-2'))
        ->assertStatus(422)->assertJsonPath('code', 'employment_requirements')->assertJsonPath('requirements.0.mode', 'block');
    $this->withHeader('X-Api-Key', $key['plaintext'])->postJson('/api/v1/pre-employees', $offer('OFF-3', ['grade_code' => 'T4']))
        ->assertCreated()->assertJsonMissingPath('meta');
});

it('shows a Block refusal as a row error before approval in the job-change upload, and Warn as a note', function () {
    app(FeatureFlags::class)->setForTenant($this->tenant, EmployeeImports::FEATURE, true, platformAdmin(), 'Data migration agreed');
    $pune = Location::query()->create(['name' => 'Pune', 'code' => 'PUN', 'company_id' => $this->mk->id]);
    $first = erpHire($this->mk, ['grade_id' => $this->grade->id]);
    $second = erpHire($this->mk);
    $policy = erpPolicy(['required_on_change' => ['grade_id']]);
    app(EmploymentRequirementsPreflight::class)->switchToBlock($policy, ['required'], $this->admin, 'Block', acceptCurrentGaps: true);
    $csv = "Employee code,Effective date,Change type,Location code\n{$first->employee_code},2026-12-01,transfer,PUN\n{$second->employee_code},2026-12-01,transfer,PUN\n";

    $blocked = erpUpload('position_changes', $csv, $this->admin)->rows()->orderBy('row_number')->get();
    expect($blocked[0]->action)->toBe('update')
        ->and($blocked[1]->action)->toBe('error')
        ->and($blocked[1]->errors)->toBe(['Grade is required for Markedge Technologies employees: pay bands, increments and salary formulas by grade read it (Employment data requirements: Hire rules, v2).']);

    // Back on Warn: the row goes through with the warning as a note, and the run says so.
    app(Policies::class)->draft($policy, ['required_on_change' => ['grade_id'], 'required_mode' => 'warn']);
    app(Policies::class)->publish($policy, '2026-11-25');
    $import = erpUpload('position_changes', $csv, $this->admin);
    $rows = $import->rows()->orderBy('row_number')->get();
    expect($rows[1]->action)->toBe('update')
        ->and($rows[1]->match['warnings'])->toBe(['Grade is required for Markedge Technologies employees: pay bands, increments and salary formulas by grade read it (Employment data requirements: Hire rules, v3).']);

    $service = ImportTypes::service('position_changes');
    $service->approve($import->refresh(), $this->admin, 'test');
    $done = $service->run($import->refresh(), $this->admin)->rows()->orderBy('row_number')->get();
    expect($done[1]->result)->toContain('warning: Grade is required')
        ->and($second->positions()->effectiveOn('2026-12-02')->value('location_id'))->toBe($pune->id);
});

it('refuses a new hire row before approval in the employee upload when a rule is on Block', function () {
    app(FeatureFlags::class)->setForTenant($this->tenant, EmployeeImports::FEATURE, true, platformAdmin(), 'Data migration agreed');
    $policy = erpPolicy(['required_on_hire' => ['grade_id']]);
    app(EmploymentRequirementsPreflight::class)->switchToBlock($policy, ['required'], $this->admin, 'Block');

    $import = erpUpload('employees', "Employee code,First name,Last name,Joining date,Company code,Grade code\nN1,Ravi,Kumar,2026-12-01,MK,\nN2,Meera,Iyer,2026-12-01,MK,T4\n", $this->admin);
    $rows = $import->rows()->orderBy('row_number')->get();

    expect($rows[0]->action)->toBe('error')
        ->and($rows[0]->errors)->toBe(['Grade is required for Markedge Technologies employees: pay bands, increments and salary formulas by grade read it (Employment data requirements: Hire rules, v2).'])
        ->and($rows[1]->action)->toBe('create');
});

it('notifies the warnings after a transfer on screen, and refuses a master that does not apply without an error page', function () {
    $employee = erpHire($this->mk, ['grade_id' => $this->grade->id], '2026-10-01');
    erpPolicy(['required_on_change' => ['department_id']]);
    $designation = Designation::query()->create(['name' => 'Partner', 'code' => 'PTR']);
    app(EntityApplicability::class)->apply($designation, ApplicabilitySelection::single($this->fe->id), $this->admin);

    Livewire::test(ViewEmployee::class, ['record' => $employee->getRouteKey()])
        ->callAction('assignPosition', data: ['change_type' => 'transfer', 'effective_from' => '2026-12-01', 'grade_id' => $this->grade->id, 'audit_reason' => 'Move'])
        ->assertNotified('Saved. Some employment data needs attention');
    Livewire::test(ViewEmployee::class, ['record' => $employee->getRouteKey()])
        ->callAction('assignPosition', data: ['change_type' => 'transfer', 'effective_from' => '2027-01-01', 'designation_id' => $designation->id, 'audit_reason' => 'Move'])
        ->assertNotified('Not allowed');

    expect($employee->positions()->count())->toBe(2);
});

it('lists missing employment data, the head-office fallback and people without a leave policy in payroll readiness', function () {
    $employee = erpHire($this->mk);
    erpPolicy(['required_on_change' => ['department_id']]);
    $principal = Establishment::query()->where('company_id', $this->mk->id)->firstOrFail();
    Establishment::query()->create(['company_id' => $this->mk->id, 'legal_entity_id' => $principal->legal_entity_id, 'code' => 'MYS', 'name' => 'Mysuru', 'state' => 'KA', 'effective_from' => '2020-01-01']);
    $leave = Policy::query()->create(['type' => 'leave', 'name' => 'FynnEdge leave', 'code' => 'FE_LEAVE']);
    app(Policies::class)->draft($leave, ['entitlements' => []]);
    app(Policies::class)->publish($leave, '2026-01-01');
    PolicyAssignmentRule::query()->create(['policy_type' => 'leave', 'policy_id' => $leave->id, 'name' => 'FynnEdge', 'priority' => 100, 'conditions' => [['field' => 'company_id', 'operator' => 'in', 'value' => [$this->fe->id]]]]);

    $checks = app(PayrollReadiness::class)->evaluate($this->mk->id, activeOnly: false)['checks'];

    expect(collect($checks['employment_data']['rows'])->pluck('employee_code')->all())->toBe([$employee->employee_code])
        ->and($checks['employment_data']['rows'][0]['missing'])->toBe('Department')
        ->and(collect($checks['head_office_fallback']['rows'])->pluck('employee_code')->all())->toBe([$employee->employee_code])
        ->and(collect($checks['no_leave_policy']['rows'])->pluck('employee_code')->all())->toBe([$employee->employee_code])
        ->and($checks['employment_data']['blocking'] ?? false)->toBeFalse();

    $items = collect(app(PayrollRunChecklist::class)->items($this->mk->id, 2026, 11))->keyBy('key');
    expect($items['employment_data']['status'])->toBe(PayrollRunChecklist::WARNING)
        ->and($items['placement_data']['count'])->toBe(2);
});

it('blocks the payroll run checklist only when the missing data is on Block', function () {
    $employee = erpHire($this->mk);
    $policy = erpPolicy(['required_on_change' => ['department_id']]);
    Department::query()->create(['name' => 'Operations', 'code' => 'OPS']);
    app(EmploymentRequirementsPreflight::class)->switchToBlock($policy, ['required'], $this->admin, 'Block', acceptCurrentGaps: true);

    $items = collect(app(PayrollRunChecklist::class)->items($this->mk->id, 2026, 11))->keyBy('key');

    expect($items['employment_data']['status'])->toBe(PayrollRunChecklist::BLOCKED)
        ->and(app(PayrollRunChecklist::class)->blockers($this->mk->id, 2026, 11))->toContain('Employment data: 1 to clear.');
});

it('says who an increment filter leaves out only because the value is missing', function () {
    $sales = Department::query()->create(['name' => 'Sales', 'code' => 'SALES']);
    forceLifecycle(erpHire($this->mk, ['department_id' => $sales->id], '2026-01-05'), 'active');
    forceLifecycle(erpHire($this->mk, [], '2026-01-05'), 'active');
    forceLifecycle(erpHire($this->mk, [], '2026-01-05'), 'active');
    $cycle = new CompensationCycle(['company_id' => $this->mk->id, 'department_id' => $sales->id, 'effective_from' => '2026-12-01']);

    expect(app(CompensationCycles::class)->excluded($cycle, $this->admin))->toBe([['column' => 'department_id', 'reason' => 'no department', 'count' => 2]]);
});

it('warns when a transfer moves someone outside their salary structure', function () {
    $employee = erpHire($this->mk, ['grade_id' => $this->grade->id], '2026-01-05');
    forceLifecycle($employee, 'active');
    compensate($employee, 600000, '2026-02-01');
    approveStructureVersion('STANDARD', '2026-11-01', [], ['grade_ids' => [$this->grade->id]]);
    erpPolicy([]);
    $other = Grade::query()->create(['name' => 'T5', 'code' => 'T5']);

    $position = app(AssignPositionAction::class)->handle($employee, ['grade_id' => $other->id], 'promotion', '2026-12-01', 'Promoted');

    expect($position->requirementWarnings)->toBe(["Salary structure STANDARD does not apply to the new position's grade: review the salary (Employment data requirements: Hire rules, v1)."]);
});

it('counts salary structure mismatches in the pre-flight with one salary query per batch, not per person', function () {
    $other = Grade::query()->create(['name' => 'T5', 'code' => 'T5']);
    foreach (range(1, 3) as $i) {
        $employee = erpHire($this->mk, ['grade_id' => $this->grade->id], '2026-01-05');
        forceLifecycle($employee, 'active');
        compensate($employee, 600000, '2026-02-01');
    }
    $outside = erpHire($this->mk, ['grade_id' => $other->id], '2026-01-05');
    forceLifecycle($outside, 'active');
    compensate($outside, 600000, '2026-02-01');
    forceLifecycle(erpHire($this->mk, ['grade_id' => $other->id], '2026-01-05'), 'active');   // no salary: nothing to compare
    approveStructureVersion('STANDARD', '2026-11-01', [], ['grade_ids' => [$this->grade->id]]);
    $policy = erpPolicy([]);

    DB::flushQueryLog();
    DB::enableQueryLog();
    $report = app(EmploymentRequirementsPreflight::class)->run($policy);
    $salaryQueries = collect(DB::getQueryLog())->filter(fn (array $q) => preg_match('/from [`"]?employee_salary_assignments\b/i', $q['query']) === 1)->count();
    DB::disableQueryLog();

    expect($report['employees'])->toBe(5)
        ->and($report['rules']['salary_structure']['violations'])->toBe(1)
        ->and($report['rules']['salary_structure']['samples'])->toHaveCount(1)
        ->and($report['rules']['salary_structure']['samples'][0])->toStartWith($outside->employee_code.' — Salary structure STANDARD does not apply')
        ->and($salaryQueries)->toBe(1);

    // The batch is forgotten afterwards: a later check of one person reads their salary afresh.
    expect(app(EmploymentRequirementsPreflight::class)->run($policy)['rules']['salary_structure']['violations'])->toBe(1);
});

it('shows the pre-flight on its page and switches chosen rules to Block from there', function () {
    erpHire($this->mk, [], '2026-01-05');
    $policy = erpPolicy(['required_on_hire' => ['grade_id'], 'required_on_change' => ['grade_id']]);

    Livewire::test(EmploymentRequirementsPage::class)
        ->assertSee('Hire rules')->assertSee('Would fail today')->assertSee('Required fields')
        ->callAction('switchToBlock', data: ['rules' => ['required'], 'accept_gaps' => false, 'effective_from' => '2026-11-24', 'audit_reason' => 'Block'])
        ->assertNotified('Not switched to Block')
        ->callAction('switchToBlock', data: ['rules' => ['required'], 'accept_gaps' => true, 'effective_from' => '2026-11-24', 'audit_reason' => 'Block'])
        ->assertNotified('Published with Block');

    expect($policy->fresh()->versionEffectiveOn('2026-11-24')->settings['required_mode'])->toBe('block');

    $this->actingAs(tenantUser($this->tenant, ['employee.view']));
    $this->get(EmploymentRequirementsPage::getUrl())->assertForbidden();
});
