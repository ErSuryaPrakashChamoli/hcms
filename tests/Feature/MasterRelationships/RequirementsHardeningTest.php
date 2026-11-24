<?php

use App\Domain\Attendance\Models\Shift;
use App\Domain\Compensation\Models\CompensationCycle;
use App\Domain\Compensation\Services\CompensationCycles;
use App\Domain\Configuration\Applicability\ApplicabilitySelection;
use App\Domain\Configuration\Applicability\EntityApplicability;
use App\Domain\Configuration\Cloning\CloneRequest;
use App\Domain\Configuration\Cloning\ConfigurationCloner;
use App\Domain\Configuration\Cloning\Destination;
use App\Domain\Configuration\Exceptions\ConfigurationException;
use App\Domain\Configuration\Models\Policy;
use App\Domain\Configuration\Models\PolicyAssignmentRule;
use App\Domain\Configuration\Overrides\ConfigurationOverrides;
use App\Domain\Configuration\Services\Policies;
use App\Domain\Employment\Actions\HireEmployeeAction;
use App\Domain\Employment\Imports\EmployeeImport;
use App\Domain\Employment\Imports\EmployeeImports;
use App\Domain\Employment\Imports\ImportTypes;
use App\Domain\Employment\Models\Employee;
use App\Domain\Employment\Requirements\EmploymentRequirements;
use App\Domain\Employment\Requirements\EmploymentRequirementsPreflight;
use App\Domain\Employment\Services\TransferRequests;
use App\Domain\Identity\Services\AccessScopes;
use App\Domain\Organisation\Exceptions\InvalidHierarchyException;
use App\Domain\Organisation\Models\Company;
use App\Domain\Organisation\Models\Department;
use App\Domain\Organisation\Models\Designation;
use App\Domain\Organisation\Models\Establishment;
use App\Domain\Organisation\Models\Grade;
use App\Domain\Organisation\Models\Location;
use App\Domain\Platform\Services\FeatureFlags;
use App\Filament\Pages\UnmappedMastersPage;
use App\Filament\RelationManagers\Masters\MasterLinksTab;
use App\Filament\Resources\Designations\Pages\EditDesignation;
use App\Filament\Resources\Locations\Pages\EditLocation;
use App\Filament\Resources\TransferRequests\Pages\ListTransferRequests;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

/*
| Master relationships R2–R4 hardening (independent review, 24 Nov 2026): the pre-flight counts everyone a policy
| reaches and each company with its own values; a Block rule blocks only where a pre-flight covered it; copies start
| on Warn; a person limited to some companies cannot change or block a policy shared beyond them; transfer approvals
| refuse without an error page; a location's establishment stays with its company; company values stay within the
| viewer's companies; uploads chain rows of one person; increment exclusions follow the cycle's other filters.
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

function rhPolicy(array $settings, array $conditions = [], string $code = 'EDR'): Policy
{
    $policy = Policy::query()->create(['type' => EmploymentRequirements::TYPE, 'name' => 'Hire rules', 'code' => $code]);
    app(Policies::class)->draft($policy, $settings);
    app(Policies::class)->publish($policy, '2026-01-01');
    PolicyAssignmentRule::query()->create(['policy_type' => EmploymentRequirements::TYPE, 'policy_id' => $policy->id, 'name' => 'Applicability', 'priority' => 100, 'conditions' => $conditions]);

    return $policy;
}

function rhHire(Company $company, array $position = [], string $date = '2026-11-02'): Employee
{
    return app(HireEmployeeAction::class)->handle(['first_name' => 'Asha', 'last_name' => 'Rao'], ['joining_date' => $date], ['company_id' => $company->id] + $position);
}

function rhLimited(object $test, array $permissions, Company $company)
{
    $user = tenantUser($test->tenant, $permissions);
    app(AccessScopes::class)->assign($user, ['company' => [$company->id]]);

    return $user;
}

function rhUpload(string $type, string $csv, $user): EmployeeImport
{
    $path = EmployeeImports::directory().'/'.uniqid($type).'.csv';
    Storage::disk('local')->put($path, $csv);
    $import = ImportTypes::register($type, $path, "{$type}.csv", $user, ['mode' => 'both']);
    $service = ImportTypes::service($type);
    $service->inspect($import);
    $service->map($import->refresh(), $import->refresh()->mapping);

    return $service->validate($import->refresh());
}

it('counts everyone a policy reaches in the pre-flight whatever the viewer may see, and names only people they may see', function () {
    $mine = rhHire($this->mk);
    rhHire($this->fe);
    $policy = rhPolicy(['required_on_change' => ['grade_id']]);
    $this->actingAs(rhLimited($this, ['policy.view', 'employee.view'], $this->mk));

    $report = app(EmploymentRequirementsPreflight::class)->run($policy);

    expect($report['employees'])->toBe(2)
        ->and($report['rules']['required']['violations'])->toBe(2)
        ->and($report['rules']['required']['samples'])->toHaveCount(1)
        ->and($report['rules']['required']['samples'][0])->toStartWith($mine->employee_code.' — ');
});

it('checks each company with its own values of the policy in the pre-flight', function () {
    rhHire($this->mk);
    $theirs = rhHire($this->fe);
    $policy = rhPolicy(['required_on_hire' => ['grade_id']]);
    app(ConfigurationOverrides::class)->set($policy, $this->fe->id, 'required_on_change', ['grade_id'], '2026-11-24', $this->admin, 'FynnEdge needs grades on every change');

    $report = app(EmploymentRequirementsPreflight::class)->run($policy);

    expect($report['rules']['required']['violations'])->toBe(1)
        ->and($report['rules']['required']['samples'][0])->toStartWith($theirs->employee_code.' — ');
});

it('keeps the rules already on Block when another rule is switched to Block', function () {
    $policy = rhPolicy(['required_on_hire' => ['grade_id']]);
    $preflight = app(EmploymentRequirementsPreflight::class);
    $preflight->switchToBlock($policy, ['required'], $this->admin, 'Block missing grades');

    $version = $preflight->switchToBlock($policy, ['grade_level'], $this->admin, 'Block grades outside the level', '2026-11-25');

    expect($version->settings['required_mode'])->toBe('block')
        ->and($version->settings['check_grade_level'])->toBe('block')
        ->and($version->settings['preflight']['safe_rules'])->toEqualCanonicalizing(['required', 'grade_level']);
});

it('blocks only where a pre-flight covered the company: a company added later gets a warning until a new pre-flight', function () {
    $policy = rhPolicy(['required_on_hire' => ['grade_id']]);
    app(EmploymentRequirementsPreflight::class)->switchToBlock($policy, ['required'], $this->admin, 'Block');
    $newco = Company::factory()->create(['name' => 'Newco Labs', 'code' => 'NEW']);

    expect(fn () => rhHire($this->mk, [], '2026-11-24'))->toThrow(InvalidArgumentException::class, 'Grade is required');
    $hired = rhHire($newco, [], '2026-11-24');
    expect($hired->requirementWarnings)->toHaveCount(1)
        ->and($hired->requirementWarnings[0])->toEndWith('It blocks once a pre-flight covers Newco Labs.');

    app(EmploymentRequirementsPreflight::class)->switchToBlock($policy, ['required'], $this->admin, 'Newco has grades too', '2026-11-25', acceptCurrentGaps: true);
    expect(fn () => rhHire($newco, [], '2026-11-25'))->toThrow(InvalidArgumentException::class, 'Grade is required');
});

it('copies a policy on Block as Warn, without its pre-flight', function () {
    $policy = rhPolicy(['required_on_hire' => ['grade_id']]);
    app(EmploymentRequirementsPreflight::class)->switchToBlock($policy, ['required'], $this->admin, 'Block');

    app(ConfigurationCloner::class)->execute(new CloneRequest('policy', $policy->id, [new Destination($this->fe->id, 'Hire rules (FynnEdge)', 'EDR_FE')], reason: 'FynnEdge rules', acknowledged: true), $this->admin);
    $draft = Policy::query()->where('code', 'EDR_FE')->sole()->draft()->sole();

    expect($draft->settings['required_mode'])->toBe('warn')
        ->and($draft->settings)->not->toHaveKey('preflight')
        ->and($draft->settings['required_on_hire'])->toBe(['grade_id']);
});

it('refuses a person limited to some companies to change, publish or block a policy shared beyond them', function () {
    $policy = rhPolicy(['required_on_hire' => ['grade_id']]);
    $limited = rhLimited($this, ['policy.view', 'policy.update', 'policy.publish'], $this->mk);
    $this->actingAs($limited);

    expect(fn () => app(Policies::class)->draft($policy, ['required_on_hire' => []]))->toThrow(ConfigurationException::class, 'outside your access')
        ->and(fn () => app(EmploymentRequirementsPreflight::class)->switchToBlock($policy, ['required'], $limited, 'Block'))->toThrow(ConfigurationException::class, 'outside your access')
        ->and($policy->versions()->count())->toBe(1);

    // A policy of their own company only is theirs to change.
    $this->actingAs($this->admin);
    $own = rhPolicy(['required_on_hire' => ['grade_id']], [], 'EDR_MK');
    app(EntityApplicability::class)->apply($own, ApplicabilitySelection::single($this->mk->id), $this->admin);
    $this->actingAs($limited);
    expect(app(Policies::class)->draft($own, ['required_on_hire' => []])->version)->toBe(2);
});

it('refuses a transfer request approval that a Block rule refuses, as a message and without changing anything', function () {
    $employee = rhHire($this->mk, [], '2026-01-05');
    $policy = rhPolicy(['required_on_change' => ['grade_id']]);
    app(EmploymentRequirementsPreflight::class)->switchToBlock($policy, ['required'], $this->admin, 'Block', acceptCurrentGaps: true);
    $pune = Location::query()->create(['name' => 'Pune', 'code' => 'PUN', 'company_id' => $this->mk->id]);
    $request = app(TransferRequests::class)->request($employee, '2026-12-01', ['location_id' => $pune->id], null, null, 'Move to Pune', tenantUser($this->tenant, ['employee.position', 'employee.view']));

    Livewire::test(ListTransferRequests::class)->callTableAction('approve', $request, data: ['note' => 'Approved'])->assertNotified('Not done');

    expect($request->fresh()->status)->toBe('pending')->and($employee->positions()->count())->toBe(1);
});

it("keeps a location's establishment to its own company, and off a location shared with other companies", function () {
    $principal = Establishment::query()->where('company_id', $this->mk->id)->firstOrFail();
    $theirs = Establishment::query()->where('company_id', $this->fe->id)->firstOrFail();
    $pune = Location::query()->create(['name' => 'Pune', 'code' => 'PUN', 'company_id' => $this->mk->id]);

    expect(fn () => $pune->update(['establishment_id' => $theirs->id]))->toThrow(InvalidHierarchyException::class, 'belongs to another company');
    $pune = $pune->fresh();
    app(EntityApplicability::class)->apply($pune, ApplicabilitySelection::selected([$this->mk->id, $this->fe->id]), $this->admin);
    expect(fn () => $pune->fresh()->update(['establishment_id' => $principal->id]))->toThrow(InvalidHierarchyException::class, 'shared with other companies');
    Livewire::test(EditLocation::class, ['record' => $pune->getRouteKey()])->fillForm(['establishment_id' => $principal->id])->call('save')
        ->assertHasFormErrors(['establishment_id']);
    expect($pune->fresh()->establishment_id)->toBeNull();

    $mumbai = Location::query()->create(['name' => 'Mumbai', 'code' => 'MUM', 'company_id' => $this->mk->id, 'establishment_id' => $principal->id]);
    expect($mumbai->establishment_id)->toBe($principal->id);
});

it("shows a person limited to some companies only those companies' own values", function () {
    $designation = Designation::query()->create(['name' => 'Sourcing Manager', 'code' => 'SRC']);
    $t5 = Grade::query()->create(['name' => 'T5', 'code' => 'T5']);
    app(ConfigurationOverrides::class)->set($designation, $this->fe->id, 'grade_id', $t5->id, '2026-11-24', $this->admin, 'FynnEdge');
    app(ConfigurationOverrides::class)->set($designation, $this->mk->id, 'grade_id', $this->grade->id, '2026-11-24', $this->admin, 'Markedge');
    $this->actingAs(rhLimited($this, ['organisation.view', 'people_setup.view'], $this->mk));

    Livewire::test(MasterLinksTab::class, ['ownerRecord' => $designation, 'pageClass' => EditDesignation::class])
        ->assertSee('Markedge Technologies: T4')->assertDontSee('FynnEdge Advisory: T5');
    $row = collect(Livewire::test(UnmappedMastersPage::class)->instance()->designations())->firstWhere('code', 'SRC');
    expect($row['own'])->toBe(['Markedge Technologies']);
});

it('checks a later upload row of the same person against what the earlier row leaves', function () {
    app(FeatureFlags::class)->setForTenant($this->tenant, EmployeeImports::FEATURE, true, platformAdmin(), 'Data migration agreed');
    Department::query()->create(['name' => 'Operations', 'code' => 'OPS']);
    Location::query()->create(['name' => 'Pune', 'code' => 'PUN', 'company_id' => $this->mk->id]);
    $employee = rhHire($this->mk, [], '2026-01-05');
    $policy = rhPolicy(['required_on_change' => ['department_id']]);
    app(EmploymentRequirementsPreflight::class)->switchToBlock($policy, ['required'], $this->admin, 'Block', acceptCurrentGaps: true);

    $csv = "Employee code,Effective date,Change type,Department code,Location code\n"
        ."{$employee->employee_code},2026-12-01,transfer,OPS,\n{$employee->employee_code},2026-12-15,transfer,,PUN\n";
    $rows = rhUpload('position_changes', $csv, $this->admin)->rows()->orderBy('row_number')->get();

    expect($rows->pluck('action')->all())->toBe(['update', 'update']);
});

it('counts as excluded from an increment only the people every other filter of the cycle keeps', function () {
    $sales = Department::query()->create(['name' => 'Sales', 'code' => 'SALES']);
    $mumbai = Location::query()->create(['name' => 'Mumbai', 'code' => 'MUM', 'company_id' => $this->mk->id]);
    $pune = Location::query()->create(['name' => 'Pune', 'code' => 'PUN', 'company_id' => $this->mk->id]);
    forceLifecycle(rhHire($this->mk, ['location_id' => $mumbai->id], '2026-01-05'), 'active');
    forceLifecycle(rhHire($this->mk, ['location_id' => $pune->id], '2026-01-05'), 'active');
    forceLifecycle(rhHire($this->mk, [], '2026-01-05'), 'active');
    $cycle = new CompensationCycle(['company_id' => $this->mk->id, 'department_id' => $sales->id, 'location_id' => $mumbai->id, 'effective_from' => '2026-12-01']);

    expect(app(CompensationCycles::class)->excluded($cycle, $this->admin))->toBe([
        ['column' => 'location_id', 'reason' => 'no location', 'count' => 1],
        ['column' => 'department_id', 'reason' => 'no department', 'count' => 2],
    ]);
});

it('keeps the shared value in a copy where a company value is blank but the column cannot be empty', function () {
    $shift = Shift::create(['name' => 'General', 'code' => 'GEN', 'type' => 'fixed', 'start_time' => '09:00:00', 'end_time' => '18:00:00',
        'break_minutes' => 60, 'full_day_minutes' => 480, 'half_day_minutes' => 240, 'grace_in_minutes' => 5, 'grace_out_minutes' => 0]);
    app(ConfigurationOverrides::class)->set($shift, $this->fe->id, 'grace_in_minutes', null, '2026-11-24', $this->admin, 'Blank for FynnEdge');
    app(ConfigurationOverrides::class)->set($shift, $this->fe->id, 'grace_out_minutes', 10, '2026-11-24', $this->admin, 'FynnEdge leaves early');

    app(ConfigurationCloner::class)->execute(new CloneRequest('shift', $shift->id, [new Destination($this->fe->id, 'General (FynnEdge)', 'GEN_FE')], reason: 'FynnEdge shift', acknowledged: true), $this->admin);

    expect(Shift::query()->where('code', 'GEN_FE')->sole()->only(['grace_in_minutes', 'grace_out_minutes']))->toBe(['grace_in_minutes' => 5, 'grace_out_minutes' => 10]);
});
