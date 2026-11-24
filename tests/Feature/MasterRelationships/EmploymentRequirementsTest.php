<?php

use App\Domain\Audit\Models\AuditEvent;
use App\Domain\Configuration\Exceptions\ConfigurationException;
use App\Domain\Configuration\Models\Policy;
use App\Domain\Configuration\Models\PolicyAssignmentRule;
use App\Domain\Configuration\Services\Policies;
use App\Domain\Employment\Actions\AssignPositionAction;
use App\Domain\Employment\Actions\HireEmployeeAction;
use App\Domain\Employment\Models\Employee;
use App\Domain\Employment\Models\EmployeePosition;
use App\Domain\Employment\Requirements\EmploymentRequirements;
use App\Domain\Employment\Requirements\EmploymentRequirementsDefaults;
use App\Domain\Employment\Requirements\EmploymentRequirementsException;
use App\Domain\Employment\Requirements\EmploymentRequirementsPreflight;
use App\Domain\Organisation\Models\Company;
use App\Domain\Organisation\Models\CostCentre;
use App\Domain\Organisation\Models\Department;
use App\Domain\Organisation\Models\Designation;
use App\Domain\Organisation\Models\Grade;
use App\Domain\Organisation\Models\Level;
use App\Domain\Organisation\Models\Location;

/*
| Master relationships R2: the "Employment data requirements" policy. Required fields and consistency checks, each Off,
| Warn or Block, checked in AssignPositionAction for every hire and change. Warn is the default (OD-3); Block only
| after a pre-flight. No policy: only the company is required, as before. New tenants get the OD-4 defaults.
*/

beforeEach(function () {
    $this->travelTo('2026-11-24 10:00:00');
    $this->tenant = provisionTenant();
    actAsTenant($this->tenant);
    $this->admin = tenantUser($this->tenant, ['*']);
    $this->actingAs($this->admin);
    $this->mk = Company::factory()->create(['name' => 'Markedge Technologies', 'code' => 'MK']);
    $this->fe = Company::factory()->create(['name' => 'FynnEdge Advisory', 'code' => 'FE']);
});

function erPolicy(array $settings, array $conditions = [], string $code = 'EDR', string $from = '2026-01-01'): Policy
{
    $policy = Policy::query()->create(['type' => EmploymentRequirements::TYPE, 'name' => 'Hire rules', 'code' => $code]);
    app(Policies::class)->draft($policy, $settings);
    app(Policies::class)->publish($policy, $from);
    PolicyAssignmentRule::query()->create(['policy_type' => EmploymentRequirements::TYPE, 'policy_id' => $policy->id, 'name' => 'Applicability', 'priority' => 100, 'conditions' => $conditions]);

    return $policy;
}

function erHire(Company $company, array $position = [], string $date = '2026-11-24'): Employee
{
    return app(HireEmployeeAction::class)->handle(['first_name' => 'Asha', 'last_name' => 'Rao'], ['joining_date' => $date], ['company_id' => $company->id] + $position);
}

it('requires only the company when there is no policy, exactly as before', function () {
    $employee = erHire($this->mk);

    expect($employee->requirementWarnings)->toBe([])
        ->and($employee->positions()->sole()->company_id)->toBe($this->mk->id);
});

it("warns about what a company's policy requires, records it, and leaves other companies alone", function () {
    erPolicy(['required_on_hire' => ['grade_id']], [['field' => 'company_id', 'operator' => 'in', 'value' => [$this->mk->id]]]);

    $markedge = erHire($this->mk);
    $fynnedge = erHire($this->fe);

    expect($markedge->requirementWarnings)->toBe(['Grade is required for Markedge Technologies employees (Employment data requirements: Hire rules, v1).'])
        ->and($markedge->positions()->count())->toBe(1)
        ->and($fynnedge->requirementWarnings)->toBe([])
        ->and(AuditEvent::query()->where('module', 'employment')->get()->filter(fn ($e) => isset($e->metadata['requirement_warnings']))->count())->toBe(1);
});

it('never accepts Block without a pre-flight, from the wizard or on publish', function () {
    expect(fn () => Policies::validateSettings(EmploymentRequirements::TYPE, ['required_on_hire' => ['grade_id'], 'required_mode' => 'block']))
        ->toThrow(ConfigurationException::class, 'only after a pre-flight check');

    $policy = erPolicy(['required_on_hire' => ['grade_id']]);
    app(Policies::class)->draft($policy, ['required_on_hire' => ['grade_id'], 'check_grade_level' => 'block']);
    expect(fn () => app(Policies::class)->publish($policy, '2026-12-01'))->toThrow(ConfigurationException::class, 'Grade sits under the level');
});

it('switches to Block after a pre-flight, then refuses a hire without the field and accepts it with one', function () {
    $grade = Grade::query()->create(['name' => 'T4', 'code' => 'T4']);
    $policy = erPolicy(['required_on_hire' => ['grade_id']], [['field' => 'company_id', 'operator' => 'in', 'value' => [$this->mk->id]]]);

    $report = app(EmploymentRequirementsPreflight::class)->run($policy);
    expect($report['rules']['required']['safe'])->toBeTrue()->and($report['companies'])->toBe([$this->mk->id => 'Markedge Technologies']);

    $version = app(EmploymentRequirementsPreflight::class)->switchToBlock($policy, ['required'], $this->admin, 'Grades are set up for Markedge');
    expect($version->version)->toBe(2)->and($version->settings['required_mode'])->toBe('block')
        ->and($version->settings['preflight']['safe_rules'])->toBe(['required']);

    expect(fn () => erHire($this->mk))->toThrow(EmploymentRequirementsException::class, 'Grade is required for Markedge Technologies employees (Employment data requirements: Hire rules, v2).')
        ->and(Employee::query()->count())->toBe(0);
    expect(erHire($this->mk, ['grade_id' => $grade->id])->requirementWarnings)->toBe([])
        ->and(erHire($this->fe)->requirementWarnings)->toBe([]);
});

it('refuses Block when a company has nothing that could meet the requirement', function () {
    $policy = erPolicy(['required_on_hire' => ['cost_centre_id']], [['field' => 'company_id', 'operator' => 'in', 'value' => [$this->mk->id]]]);

    expect(fn () => app(EmploymentRequirementsPreflight::class)->switchToBlock($policy, ['required'], $this->admin, 'Block'))
        ->toThrow(ConfigurationException::class, 'Markedge Technologies has no cost centre it may use.');

    CostCentre::query()->create(['name' => 'Delivery', 'code' => 'DEL', 'company_id' => $this->mk->id]);
    expect(app(EmploymentRequirementsPreflight::class)->switchToBlock($policy, ['required'], $this->admin, 'Block')->version)->toBe(2);
});

it('asks for consent when current people would fail, and then their next change must complete the data', function () {
    $grade = Grade::query()->create(['name' => 'T4', 'code' => 'T4']);
    $existing = erHire($this->mk, [], '2026-01-05');
    $before = $existing->positions()->sole()->getAttributes();
    $policy = erPolicy(['required_on_hire' => ['grade_id'], 'required_on_change' => ['grade_id']]);

    expect(fn () => app(EmploymentRequirementsPreflight::class)->switchToBlock($policy, ['required'], $this->admin, 'Block'))
        ->toThrow(ConfigurationException::class, '1 current employees would fail "Required fields" on their next change');
    app(EmploymentRequirementsPreflight::class)->switchToBlock($policy, ['required'], $this->admin, 'Block', acceptCurrentGaps: true);

    $location = Location::query()->create(['name' => 'Pune', 'code' => 'PUN', 'company_id' => $this->mk->id]);
    expect(fn () => app(AssignPositionAction::class)->handle($existing, ['location_id' => $location->id], 'transfer', '2026-12-01', 'Moved'))
        ->toThrow(EmploymentRequirementsException::class, 'Grade is required');
    // Refused before anything was written: the earlier position is exactly as it was.
    expect($existing->positions()->sole()->getAttributes())->toBe($before);

    $moved = app(AssignPositionAction::class)->handle($existing, ['location_id' => $location->id, 'grade_id' => $grade->id], 'transfer', '2026-12-01', 'Moved with grade');
    expect($moved->grade_id)->toBe($grade->id)->and($existing->positions()->count())->toBe(2);
});

it('needs a new pre-flight whenever the rules change: an edited version keeps Block only with one', function () {
    Grade::query()->create(['name' => 'T4', 'code' => 'T4']);
    $policy = erPolicy(['required_on_hire' => ['grade_id']]);
    $blocked = app(EmploymentRequirementsPreflight::class)->switchToBlock($policy, ['required'], $this->admin, 'Block');

    // What the wizard saves: the declared settings only, so the pre-flight record is gone.
    $edited = collect($blocked->settings)->except('preflight')->all();
    app(Policies::class)->draft($policy, $edited + ['required_on_change' => ['grade_id']]);
    expect(fn () => app(Policies::class)->publish($policy, '2026-12-01'))->toThrow(ConfigurationException::class, 'only after a pre-flight check');

    app(Policies::class)->draft($policy, ['required_mode' => 'warn'] + $edited);
    expect(app(Policies::class)->publish($policy, '2026-12-01')->version)->toBe(3);
});

it('follows the policy version in force on the effective date of the change', function () {
    Grade::query()->create(['name' => 'T4', 'code' => 'T4']);
    $policy = erPolicy(['required_on_hire' => ['grade_id']]);
    app(EmploymentRequirementsPreflight::class)->switchToBlock($policy, ['required'], $this->admin, 'Block from December', '2026-12-01');

    expect(erHire($this->mk, [], '2026-11-30')->requirementWarnings)->toHaveCount(1)
        ->and(fn () => erHire($this->fe, [], '2026-12-02'))->toThrow(EmploymentRequirementsException::class, 'Hire rules, v2');
});

it("checks the grade against the level and the designation's usual department, on Warn", function () {
    [$l1, $l2] = [Level::query()->create(['name' => 'L1', 'code' => 'EDR-L1']), Level::query()->create(['name' => 'L2', 'code' => 'EDR-L2'])];
    $grade = Grade::query()->create(['name' => 'T4', 'code' => 'T4', 'level_id' => $l1->id]);
    [$hr, $ops] = [Department::query()->create(['name' => 'Human Resources', 'code' => 'HR']), Department::query()->create(['name' => 'Operations', 'code' => 'OPS'])];
    $designation = Designation::query()->create(['name' => 'Sourcing Manager', 'code' => 'SRC', 'department_id' => $hr->id]);
    erPolicy([]);

    $employee = erHire($this->mk, ['level_id' => $l2->id, 'grade_id' => $grade->id, 'designation_id' => $designation->id, 'department_id' => $ops->id]);

    expect($employee->requirementWarnings)->toBe([
        'Grade T4 belongs to level L1, not L2 (Employment data requirements: Hire rules, v1).',
        "Sourcing Manager's usual department is Human Resources (information only); this position is in Operations (Employment data requirements: Hire rules, v1).",
    ])->and($employee->positions()->sole()->department_id)->toBe($ops->id);
});

it('refuses to switch to Block for someone not allowed to publish policies', function () {
    Grade::query()->create(['name' => 'T4', 'code' => 'T4']);
    $policy = erPolicy(['required_on_hire' => ['grade_id']]);
    $editor = tenantUser($this->tenant, ['policy.update', 'policy.view']);

    expect(fn () => app(EmploymentRequirementsPreflight::class)->switchToBlock($policy, ['required'], $editor, 'Block'))
        ->toThrow(ConfigurationException::class, 'publish policies')
        ->and($policy->versions()->count())->toBe(1);
});

it('gives a new tenant the OD-4 defaults on Warn and an existing tenant nothing', function () {
    expect(Policy::query()->where('type', EmploymentRequirements::TYPE)->exists())->toBeFalse();

    config(['peopleos.employment_requirements.seed_new_tenants' => true]);
    $newco = provisionTenant('Newco');
    actAsTenant($newco);
    $this->actingAs(tenantUser($newco, ['*']));
    $company = Company::factory()->create(['name' => 'Newco India']);
    $policy = Policy::query()->where('type', EmploymentRequirements::TYPE)->sole();

    expect($policy->code)->toBe(EmploymentRequirementsDefaults::CODE)
        ->and($policy->versionEffectiveOn(today())->settings['required_on_hire'])->toBe(['location_id', 'department_id', 'designation_id', 'employment_type_id'])
        ->and(collect(erHire($company)->requirementWarnings)->map(fn ($m) => strtok($m, ' ')))->toEqual(collect(['Location', 'Department', 'Designation', 'Employment']));
    expect(EmployeePosition::query()->count())->toBe(1);
});
