<?php

use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Audit\Models\AuditEvent;
use App\Domain\Configuration\Applicability\ApplicabilityRefusedException;
use App\Domain\Configuration\Applicability\ApplicabilitySelection;
use App\Domain\Configuration\Applicability\EntityApplicability;
use App\Domain\Configuration\Cloning\CloneRequest;
use App\Domain\Configuration\Cloning\ConfigurationCloner;
use App\Domain\Configuration\Cloning\Destination;
use App\Domain\Configuration\Overrides\ConfigurationOverrides;
use App\Domain\Employment\Actions\HireEmployeeAction;
use App\Domain\Employment\Imports\EmployeeImport;
use App\Domain\Employment\Imports\EmployeeImports;
use App\Domain\Employment\Imports\ImportTypes;
use App\Domain\Employment\Models\Employee;
use App\Domain\Identity\Services\AccessScopes;
use App\Domain\Organisation\Models\Company;
use App\Domain\Organisation\Models\Designation;
use App\Domain\Organisation\Models\Establishment;
use App\Domain\Organisation\Models\Grade;
use App\Domain\Organisation\Models\JobFamily;
use App\Domain\Organisation\Models\Level;
use App\Domain\Organisation\Models\Location;
use App\Domain\Organisation\Services\DesignationDefaults;
use App\Domain\Platform\Services\FeatureFlags;
use App\Domain\Workforce\Services\Positions;
use App\Filament\Resources\Employees\Pages\CreateEmployee;
use App\Filament\Resources\Employees\Pages\ViewEmployee;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

/*
| Master relationships R3: per-company designation defaults on the override framework. Sourcing Manager is L5 / T4
| (shared) and L6 / T5 at FynnEdge. Defaults prefill and compare; they never rewrite a position. Clones take the
| company's values; uploads set them with a Company column; dependency checks and tenant isolation respect them.
*/

beforeEach(function () {
    $this->travelTo('2026-11-24 10:00:00');
    Storage::fake('local');
    config(['peopleos.documents.disk' => 'local', 'peopleos.imports.workbooks.queue' => false]);
    $this->tenant = provisionTenant();
    actAsTenant($this->tenant);
    $this->admin = tenantUser($this->tenant, ['*']);
    $this->actingAs($this->admin);
    $this->mk = Company::factory()->create(['name' => 'Markedge Technologies', 'code' => 'MK']);
    $this->fe = Company::factory()->create(['name' => 'FynnEdge Advisory', 'code' => 'FE']);
    $this->l5 = Level::query()->create(['name' => 'L5', 'code' => 'R3-L5']);
    $this->l6 = Level::query()->create(['name' => 'L6', 'code' => 'R3-L6']);
    $this->t4 = Grade::query()->create(['name' => 'T4', 'code' => 'T4', 'level_id' => $this->l5->id]);
    $this->t5 = Grade::query()->create(['name' => 'T5', 'code' => 'T5', 'level_id' => $this->l6->id]);
    $this->family = JobFamily::query()->create(['name' => 'Procurement', 'code' => 'PROC']);
    $this->sourcing = Designation::query()->create(['name' => 'Sourcing Manager', 'code' => 'SRC-MGR', 'level_id' => $this->l5->id, 'grade_id' => $this->t4->id]);
});

function feDefaults(object $test, string $from = '2026-11-24'): void
{
    // The level and grade move together (remediation I.6): L6 with the shared T4 (a grade of L5) would disagree.
    $overrides = app(ConfigurationOverrides::class);
    $overrides->together(function () use ($overrides, $test, $from) {
        $overrides->set($test->sourcing, $test->fe->id, 'level_id', $test->l6->id, $from, $test->admin, 'FynnEdge levels');
        $overrides->set($test->sourcing, $test->fe->id, 'grade_id', $test->t5->id, $from, $test->admin, 'FynnEdge grades');
    });
}

function r3Hire(Company $company, array $position = [], string $date = '2026-11-24'): Employee
{
    return app(HireEmployeeAction::class)->handle(['first_name' => 'Asha', 'last_name' => 'Rao'], ['joining_date' => $date], ['company_id' => $company->id] + $position);
}

function r3Upload(string $csv, $user): EmployeeImport
{
    $path = EmployeeImports::directory().'/'.uniqid('des').'.csv';
    Storage::disk('local')->put($path, $csv);
    $import = ImportTypes::register('designations', $path, 'designations.csv', $user, ['mode' => 'both']);
    $service = ImportTypes::service('designations');
    $service->inspect($import);
    $service->map($import->refresh(), $import->refresh()->mapping);

    return $service->validate($import->refresh());
}

it('gives each company its own defaults of one shared designation, and the shared values elsewhere', function () {
    feDefaults($this);

    expect(app(DesignationDefaults::class)->for($this->sourcing, $this->fe->id))->toBe(['level_id' => $this->l6->id, 'grade_id' => $this->t5->id, 'job_family_id' => null])
        ->and(app(DesignationDefaults::class)->for($this->sourcing, $this->mk->id))->toBe(['level_id' => $this->l5->id, 'grade_id' => $this->t4->id, 'job_family_id' => null])
        ->and($this->sourcing->fresh()->only(['level_id', 'grade_id']))->toBe(['level_id' => $this->l5->id, 'grade_id' => $this->t4->id])
        ->and(app(ConfigurationOverrides::class)->supports($this->sourcing))->toBeTrue();
});

it('starts a company value on its date, restores the shared value when it ends, and never rewrites a position', function () {
    $employee = r3Hire($this->fe, ['designation_id' => $this->sourcing->id, 'grade_id' => $this->t4->id], '2026-10-01');
    feDefaults($this, '2026-12-01');

    expect(app(DesignationDefaults::class)->for($this->sourcing, $this->fe->id, '2026-11-30')['grade_id'])->toBe($this->t4->id)
        ->and(app(DesignationDefaults::class)->for($this->sourcing, $this->fe->id, '2026-12-01')['grade_id'])->toBe($this->t5->id)
        ->and($employee->positions()->sole()->grade_id)->toBe($this->t4->id);

    // Both end together (I.6: FynnEdge's own L6 with the shared T4 would disagree).
    $overrides = app(ConfigurationOverrides::class);
    $overrides->together(function () use ($overrides) {
        $overrides->end($this->sourcing, $this->fe->id, 'level_id', '2027-01-01', $this->admin, 'Back to the shared level');
        $overrides->end($this->sourcing, $this->fe->id, 'grade_id', '2027-01-01', $this->admin, 'Back to the shared grade');
    });
    expect(app(DesignationDefaults::class)->for($this->sourcing, $this->fe->id, '2027-01-02')['grade_id'])->toBe($this->t4->id)
        ->and(app(DesignationDefaults::class)->for($this->sourcing, $this->fe->id, '2026-12-15')['grade_id'])->toBe($this->t5->id);
});

it('refuses a company value the company may not use', function () {
    app(EntityApplicability::class)->apply($this->t5, ApplicabilitySelection::single($this->mk->id), $this->admin);
    $overrides = app(ConfigurationOverrides::class);

    expect(fn () => $overrides->set($this->sourcing, $this->fe->id, 'grade_id', $this->t5->id, '2026-11-24', $this->admin))
        ->toThrow(ApplicabilityRefusedException::class, 'Grade "T5" does not apply to FynnEdge Advisory');
    // T5 belongs to L6: Markedge takes both, together (I.6).
    $set = $overrides->together(function () use ($overrides) {
        $overrides->set($this->sourcing, $this->mk->id, 'level_id', $this->l6->id, '2026-11-24', $this->admin);

        return $overrides->set($this->sourcing, $this->mk->id, 'grade_id', $this->t5->id, '2026-11-24', $this->admin);
    });
    expect($set->value)->toBe($this->t5->id);
});

it("prefills the hire screen with the chosen company's defaults, keeps what is already chosen, and audits each company value", function () {
    feDefaults($this);

    Livewire::test(CreateEmployee::class)->fillForm(['company_id' => $this->fe->id])->fillForm(['designation_id' => $this->sourcing->id])
        ->assertFormSet(['level_id' => $this->l6->id, 'grade_id' => $this->t5->id]);
    Livewire::test(CreateEmployee::class)->fillForm(['company_id' => $this->mk->id])->fillForm(['designation_id' => $this->sourcing->id])
        ->assertFormSet(['level_id' => $this->l5->id, 'grade_id' => $this->t4->id]);
    Livewire::test(CreateEmployee::class)->fillForm(['company_id' => $this->fe->id, 'grade_id' => $this->t4->id])->fillForm(['designation_id' => $this->sourcing->id])
        ->assertFormSet(['level_id' => $this->l6->id, 'grade_id' => $this->t4->id]);

    $events = AuditEvent::query()->with('fieldChanges')->where('action', AuditAction::OverrideSet)->where('entity_id', (string) $this->sourcing->id)->orderBy('id')->get();
    expect($events)->toHaveCount(2)
        ->and($events->flatMap(fn ($e) => $e->fieldChanges->map(fn ($c) => [$c->field, $c->after]))->all())->toBe([['Level · FynnEdge Advisory', 'L6'], ['Grade · FynnEdge Advisory', 'T5']]);
});

it('prefills a transfer with the company defaults and seats take them too', function () {
    feDefaults($this);
    $employee = r3Hire($this->fe, [], '2026-10-01');

    Livewire::test(ViewEmployee::class, ['record' => $employee->getRouteKey()])
        ->callAction('assignPosition', data: ['change_type' => 'promotion', 'effective_from' => '2026-12-01', 'designation_id' => $this->sourcing->id, 'audit_reason' => 'Promoted'])
        ->assertHasNoActionErrors();
    $seat = app(Positions::class)->create(['code' => 'FE-SRC', 'title' => 'Sourcing lead', 'company_id' => $this->fe->id, 'designation_id' => $this->sourcing->id], $this->admin);

    expect($employee->positions()->effectiveOn('2026-12-02')->first()->only(['level_id', 'grade_id']))->toBe(['level_id' => $this->l6->id, 'grade_id' => $this->t5->id])
        ->and($seat->currentVersion->grade_id)->toBe($this->t5->id);
});

it("folds the company's values into a clone, which is independent of the shared designation", function () {
    feDefaults($this);

    $result = app(ConfigurationCloner::class)->execute(new CloneRequest('designation', $this->sourcing->id, [new Destination($this->fe->id, 'Sourcing Manager (FynnEdge)', 'SRC-MGR-FE')], reason: 'FynnEdge own designation', acknowledged: true), $this->admin);
    $copy = $result->copies[0]->fresh();
    $this->sourcing->update(['grade_id' => null]);

    expect($copy->only(['level_id', 'grade_id']))->toBe(['level_id' => $this->l6->id, 'grade_id' => $this->t5->id])
        ->and(app(EntityApplicability::class)->reach($copy))->toBe([$this->fe->id])
        ->and($this->sourcing->fresh()->level_id)->toBe($this->l5->id);
});

it('sets and ends company values from the designation upload with a Company column', function () {
    app(FeatureFlags::class)->setForTenant($this->tenant, EmployeeImports::FEATURE, true, platformAdmin(), 'Data migration agreed');
    $import = r3Upload("Code,Name,Level code,Grade code,Company\n"
        ."SRC-MGR,Sourcing Manager,R3-L6,T5,FE\n"
        ."SRC-MGR,Sourcing Manager,R3-L5,T4,MK\n"
        ."NOPE,Nope,,T5,FE\n"
        ."SRC-MGR,Sourcing Manager,,T5,XX\n", $this->admin);
    $rows = $import->rows()->orderBy('row_number')->get();

    expect($rows[0]->action)->toBe('update')->and($rows[0]->match['changes'])->toBe(['FynnEdge Advisory: level L6', 'FynnEdge Advisory: grade T5'])
        ->and($rows[1]->action)->toBe('skip')
        ->and($rows[2]->errors)->toBe(["Designation 'NOPE' is not on file: add it with a row without Company first (or earlier in this file)."])
        ->and($rows[3]->action)->toBe('error');

    $service = ImportTypes::service('designations');
    $service->approve($import->refresh(), $this->admin, 'test');
    $service->run($import->refresh(), $this->admin);
    expect(app(DesignationDefaults::class)->for($this->sourcing, $this->fe->id))->toBe(['level_id' => $this->l6->id, 'grade_id' => $this->t5->id, 'job_family_id' => null]);

    // (none) ends the company's own value: the shared value applies again. Ending the grade alone would leave L6 with
    // the shared T4 (a grade of L5): refused before approval (I.6). Both together are fine.
    $alone = r3Upload("Code,Name,Level code,Grade code,Company\nSRC-MGR,Sourcing Manager,,(none),FE\n", $this->admin);
    expect($alone->rows()->sole()->action)->toBe('error')->and(implode(' ', $alone->rows()->sole()->errors))->toContain('Grade T4 belongs to level L5, not L6');
    $again = r3Upload("Code,Name,Level code,Grade code,Company\nSRC-MGR,Sourcing Manager,(none),(none),FE\n", $this->admin);
    $service->approve($again->refresh(), $this->admin, 'test');
    $service->run($again->refresh(), $this->admin);
    expect(app(DesignationDefaults::class)->for($this->sourcing, $this->fe->id, '2026-11-25'))->toBe(['level_id' => $this->l5->id, 'grade_id' => $this->t4->id, 'job_family_id' => null]);
});

it('refuses company values to someone limited to another company', function () {
    app(FeatureFlags::class)->setForTenant($this->tenant, EmployeeImports::FEATURE, true, platformAdmin(), 'Data migration agreed');
    $limited = tenantUser($this->tenant, ['employee.import', 'people_setup.create', 'people_setup.update', 'people_setup.view', 'configuration.override']);
    app(AccessScopes::class)->assign($limited, ['company' => [$this->mk->id]]);
    $this->actingAs($limited);

    $rows = r3Upload("Code,Name,Grade code,Company\nSRC-MGR,Sourcing Manager,T5,FE\n", $limited)->rows()->get();
    expect($rows[0]->action)->toBe('error')->and(implode(' ', $rows[0]->errors))->toContain('FynnEdge Advisory');
});

it("checks dependencies against a company's own value, and keeps a location's establishment to its company", function () {
    // FynnEdge uses T5 through its own value: T5 cannot stop applying to FynnEdge while it does.
    feDefaults($this);
    expect(fn () => app(EntityApplicability::class)->apply($this->t5, ApplicabilitySelection::single($this->mk->id), $this->admin))
        ->toThrow(ApplicabilityRefusedException::class, 'uses this grade as its own value in FynnEdge Advisory');

    // A designation reaching FynnEdge only is fine when FynnEdge's own grade applies there, even if the shared one does not.
    app(EntityApplicability::class)->apply($this->t4, ApplicabilitySelection::single($this->mk->id), $this->admin);
    app(EntityApplicability::class)->apply($this->sourcing, ApplicabilitySelection::single($this->fe->id), $this->admin);
    expect(app(EntityApplicability::class)->reach($this->sourcing->fresh()))->toBe([$this->fe->id]);

    $establishment = Establishment::query()->where('company_id', $this->mk->id)->firstOrFail();
    $pune = Location::query()->create(['name' => 'Pune', 'code' => 'PUN', 'company_id' => $this->mk->id, 'establishment_id' => $establishment->id]);
    expect(fn () => app(EntityApplicability::class)->apply($pune, ApplicabilitySelection::selected([$this->mk->id, $this->fe->id]), $this->admin))
        ->toThrow(ApplicabilityRefusedException::class, 'belongs to Markedge Technologies');
});

it('never sets a company value for a company of another tenant', function () {
    $other = provisionTenant('Elsewhere');
    actAsTenant($other);
    $foreign = Company::factory()->create(['name' => 'Foreign Co']);
    actAsTenant($this->tenant);

    expect(fn () => app(ConfigurationOverrides::class)->set($this->sourcing, $foreign->id, 'grade_id', $this->t5->id, '2026-11-24', $this->admin))
        ->toThrow(ApplicabilityRefusedException::class);
    expect(app(DesignationDefaults::class)->for($this->sourcing, $foreign->id)['grade_id'])->toBe($this->t4->id);
});
