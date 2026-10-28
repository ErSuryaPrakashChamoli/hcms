<?php

use App\Domain\Audit\Models\AuditEvent;
use App\Domain\Configuration\Services\Blueprints;
use App\Domain\Employment\Actions\HireEmployeeAction;
use App\Domain\Employment\Imports\EmployeeImport;
use App\Domain\Employment\Imports\EmployeeImports;
use App\Domain\Employment\Models\Employee;
use App\Domain\Organisation\Models\Company;
use App\Domain\Organisation\Models\Department;
use App\Domain\Organisation\Models\Designation;
use App\Domain\Organisation\Models\Location;
use App\Domain\Platform\Models\TenantFeature;
use App\Domain\Platform\Services\FeatureFlags;
use App\Filament\Resources\EmployeeImports\EmployeeImportResource;
use App\Filament\Resources\EmployeeImports\Pages\ListEmployeeImports;
use App\Filament\Resources\EmployeeImports\Pages\ViewEmployeeImport;
use App\Filament\Resources\Employees\Pages\ListEmployees;
use App\Filament\Resources\Tenants\Pages\ListTenants;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

/* Phase 1 §38–§41, §62: staged imports never touch employees before approval; duplicates are matched deterministically; everything is audited. */

function stageCsv(string $csv, string $name = 'people.csv'): EmployeeImport
{
    $path = EmployeeImports::directory().'/'.uniqid().'.csv';
    Storage::disk('local')->put($path, $csv);

    return app(EmployeeImports::class)->register($path, $name, auth()->user());
}

beforeEach(function () {
    $this->travelTo('2026-09-21 09:00:00');
    Storage::fake('local');
    config(['peopleos.documents.disk' => 'local']);
    $this->tenant = provisionTenant();
    $this->tenantB = provisionTenant('B');
    actAsTenant($this->tenant);
    app(FeatureFlags::class)->setForTenant($this->tenant, EmployeeImports::FEATURE, true, platformAdmin(), 'Data migration agreed');
    $this->hr = tenantUser($this->tenant, ['*']);
    $this->actingAs($this->hr);
    $this->company = Company::factory()->create(['code' => 'ALPHA']);
    Location::factory()->create(['company_id' => $this->company->id, 'code' => 'DEL', 'name' => 'Delhi']);
    Department::factory()->create(['company_id' => $this->company->id, 'code' => 'ENG', 'name' => 'Engineering']);
    Designation::factory()->create(['code' => 'SE', 'name' => 'Software Engineer']);
    $this->boss = app(HireEmployeeAction::class)->handle(['first_name' => 'Bhavna', 'last_name' => 'Boss'], ['joining_date' => '2024-01-01', 'employee_code' => 'BOSS1'], ['company_id' => $this->company->id]);
    $this->csv = "First Name,Last Name,Email,DOJ,Company Code,Location Code,Department Code,Designation Code,Manager,External Reference\n"
        ."Asha,Rao,asha@alpha.test,2026-01-05,ALPHA,DEL,ENG,SE,BOSS1,ATS-1\n"
        ."Vikram,Sen,vikram@alpha.test,2026-02-01,ALPHA,DEL,ENG,SE,,ATS-2\n";
});

it('runs upload, inspect, map, validate, preview, approve and import without touching employees before the run, and audits it as one operation', function () {
    $import = stageCsv($this->csv);
    expect($import->status)->toBe('uploaded');

    $import = app(EmployeeImports::class)->inspect($import);
    expect($import->row_count)->toBe(2)->and($import->headers)->toContain('DOJ')
        ->and($import->mapping['First Name'])->toBe('person.first_name')->and($import->mapping['DOJ'])->toBe('employee.joining_date')->and($import->mapping['Manager'])->toBe('manager_code');

    $import = app(EmployeeImports::class)->map($import, $import->mapping + ['Email' => 'employee.work_email']);
    $import = app(EmployeeImports::class)->validate($import);
    expect($import->status)->toBe('validated')->and($import->valid_count)->toBe(2)->and($import->create_count)->toBe(2)->and($import->error_count)->toBe(0);
    expect(Employee::query()->count())->toBe(1); // nothing created yet

    $preview = app(EmployeeImports::class)->preview($import);
    expect($preview['will_create'])->toBe(2)->and($preview['sample'][0]['person']['first_name'])->toBe('Asha');

    expect(fn () => app(EmployeeImports::class)->run($import, $this->hr))->toThrow(RuntimeException::class, 'expected approved');
    $import = app(EmployeeImports::class)->approve($import, $this->hr, 'Q4 joiners');
    $import = app(EmployeeImports::class)->run($import, $this->hr);

    expect($import->status)->toBe('imported')->and($import->create_count)->toBe(2)->and($import->failure_count)->toBe(0)->and($import->operation_id)->not->toBeNull();
    $asha = Employee::query()->where('work_email', 'asha@alpha.test')->with(['person', 'currentPosition', 'currentManager'])->first();
    expect($asha->person->last_name)->toBe('Rao')
        ->and($asha->source)->toBe('import')->and($asha->external_reference)->toBe('ATS-1')
        ->and($asha->joining_date->toDateString())->toBe('2026-01-05')
        ->and($asha->currentPosition->department->code)->toBe('ENG')
        ->and($asha->currentManager->manager_id)->toBe($this->boss->id);

    $summary = AuditEvent::query()->where('operation_id', $import->operation_id)->where('action', 'BULK_OPERATION')->first();
    expect($summary->metadata['success_count'])->toBe(2)
        ->and(AuditEvent::query()->where('operation_id', $import->operation_id)->where('entity_type', Employee::class)->where('action', 'CREATE')->count())->toBe(2)
        ->and($import->rows()->where('status', 'imported')->count())->toBe(2);
});

it('reports row-level errors for required fields, bad dates, unknown codes and in-file duplicates, and refuses approval without mapping required fields', function () {
    $csv = "First Name,Last Name,DOJ,Company Code,Department Code,Email\n"
        .",Nolast,2026-01-01,ALPHA,ENG,a@x.test\n"
        ."Bad,Date,not-a-date,ALPHA,ENG,b@x.test\n"
        ."Wrong,Code,2026-01-01,NOPE,ZZZ,c@x.test\n"
        ."Dup,One,2026-01-01,ALPHA,ENG,dup@x.test\n"
        ."Dup,Two,2026-01-01,ALPHA,ENG,dup@x.test\n";
    $import = app(EmployeeImports::class)->inspect(stageCsv($csv));
    expect(fn () => app(EmployeeImports::class)->map($import, ['First Name' => 'person.first_name']))->toThrow(RuntimeException::class, 'Required fields are not mapped');

    $import = app(EmployeeImports::class)->map($import, $import->mapping + ['Email' => 'employee.work_email']);
    $import = app(EmployeeImports::class)->validate($import);
    $rows = $import->rows()->get()->keyBy('row_number');

    expect($import->error_count)->toBe(4)->and($import->create_count)->toBe(1)
        ->and($rows[1]->errors)->toContain('First name is required.')
        ->and($rows[2]->errors)->toContain('Joining date is not a valid date.')
        ->and($rows[3]->errors)->toContain("Company code 'NOPE' does not exist.")
        ->and($rows[5]->errors[0])->toContain('also appears in row 4');
    expect(Employee::query()->count())->toBe(1);
});

it('matches existing people deterministically: definite matches update or skip, possible matches wait for review', function () {
    app(HireEmployeeAction::class)->handle(['first_name' => 'Asha', 'last_name' => 'Rao', 'date_of_birth' => '1990-05-05'], ['joining_date' => '2025-01-01', 'work_email' => 'asha@alpha.test'], ['company_id' => $this->company->id]);
    $csv = "First Name,Last Name,Email,DOJ,Company Code,Nationality,DOB\n"
        ."Asha,Rao,asha@alpha.test,2025-01-01,ALPHA,IN,1990-05-05\n"      // definite (work email) -> update
        ."Asha,Rao,,2026-03-01,ALPHA,IN,1990-05-05\n"                       // possible (name + dob) -> review
        ."Neel,Das,neel@alpha.test,2026-03-01,ALPHA,IN,\n";                 // new -> create
    $import = app(EmployeeImports::class)->inspect(stageCsv($csv));
    $import = app(EmployeeImports::class)->map($import, $import->mapping + ['Email' => 'employee.work_email', 'DOB' => 'person.date_of_birth']);
    $import = app(EmployeeImports::class)->validate($import);

    $rows = $import->rows()->get()->keyBy('row_number');
    expect($rows[1]->action)->toBe('update')->and($rows[1]->match['matched_on'])->toContain('work email')
        ->and($rows[2]->action)->toBe('review')
        ->and($rows[3]->action)->toBe('create')
        ->and($import->review_count)->toBe(1);

    expect(fn () => app(EmployeeImports::class)->approve($import, $this->hr))->toThrow(RuntimeException::class, 'need review');
    app(EmployeeImports::class)->resolveReview($rows[2], 'skip');
    $import = app(EmployeeImports::class)->approve($import->refresh(), $this->hr, 'Reviewed');
    $import = app(EmployeeImports::class)->run($import, $this->hr);

    expect($import->create_count)->toBe(1)->and($import->update_count)->toBe(1)->and($import->skip_count)->toBe(1)
        ->and(Employee::query()->count())->toBe(3)
        ->and(Employee::query()->where('work_email', 'asha@alpha.test')->first()->person->nationality)->toBe('IN');

    // "Add new employees" turns definite matches into skips.
    $again = app(EmployeeImports::class)->inspect(stageCsv($csv));
    $again = app(EmployeeImports::class)->map($again, $again->mapping + ['Email' => 'employee.work_email'], ['mode' => 'new']);
    $again = app(EmployeeImports::class)->validate($again);
    expect($again->rows()->where('row_number', 1)->first()->action)->toBe('skip');
});

it('offers a template whose headers map every field automatically and whose example row validates with tenant codes', function () {
    Livewire::test(ListEmployeeImports::class)->callAction('downloadTemplate')->assertFileDownloaded('employee-import-template.csv');

    $import = app(EmployeeImports::class)->inspect(stageCsv(app(EmployeeImports::class)->template(), 'template.csv'));
    expect($import->row_count)->toBe(1)->and(array_values($import->mapping))->toEqualCanonicalizing(array_keys(EmployeeImports::FIELDS));

    $import = app(EmployeeImports::class)->validate(app(EmployeeImports::class)->map($import, $import->mapping));
    $row = $import->rows()->first();
    expect($row->action)->toBe('create')->and($row->errors)->toBeNull()
        ->and($row->data)->toMatchArray(['Company code' => 'ALPHA', 'Location code' => 'DEL', 'Department code' => 'ENG', 'Designation code' => 'SE', 'Employee code' => '']);
});

it('exports the listed employees in the template format, and the edited file comes back as updates', function () {
    Livewire::test(ListEmployees::class)->callAction('exportEmployees')->assertFileDownloaded('employees-2026-09-21.csv');

    $csv = app(EmployeeImports::class)->export(Employee::query(), $this->hr);
    expect(str_getcsv(strtok($csv, "\n"), escape: ''))->toBe(array_column(EmployeeImports::FIELDS, 0))
        ->and($csv)->toContain('Bhavna,,Boss,')->toContain(',BOSS1,2024-01-01,')->toContain(',ALPHA,');

    $import = app(EmployeeImports::class)->inspect(stageCsv(str_replace('Bhavna,', 'Bhavana,', $csv), 'employees.csv'));
    $import = app(EmployeeImports::class)->validate(app(EmployeeImports::class)->map($import, $import->mapping));
    expect($import->update_count)->toBe(1)->and($import->create_count)->toBe(0)->and($import->error_count)->toBe(0)->and($import->review_count)->toBe(0);

    app(EmployeeImports::class)->run(app(EmployeeImports::class)->approve($import, $this->hr), $this->hr);
    expect($this->boss->person->refresh()->first_name)->toBe('Bhavana')
        ->and(AuditEvent::query()->where('action', 'EXPORT')->where('module', 'employment')->count())->toBe(2);
});

it('stays off until a platform operator switches it on, and the organisation cannot switch it itself', function () {
    $operator = platformAdmin();
    $flags = app(FeatureFlags::class);
    $flags->setForTenant($this->tenant, EmployeeImports::FEATURE, false, $operator, 'Customer paused bulk loads');

    $this->get(EmployeeImportResource::getUrl('index'))->assertForbidden();
    Livewire::test(ListEmployees::class)->assertActionHidden('exportEmployees');
    expect(fn () => stageCsv($this->csv))->toThrow(RuntimeException::class, 'not switched on')
        ->and(fn () => app(EmployeeImports::class)->export(Employee::query(), $this->hr))->toThrow(RuntimeException::class, 'not switched on');

    // Not the organisation's own administrators (even with every permission), not a blueprint.
    $flag = TenantFeature::query()->where('feature', EmployeeImports::FEATURE)->firstOrFail();
    expect($this->hr->can('update', $flag))->toBeFalse()
        ->and(fn () => $flags->set(EmployeeImports::FEATURE, true))->toThrow(RuntimeException::class)
        ->and(fn () => $flags->setForTenant($this->tenant, EmployeeImports::FEATURE, true, $this->hr, 'We want it'))->toThrow(RuntimeException::class, 'Only platform operators')
        ->and(app(Blueprints::class)->export()['features'])->not->toHaveKey(EmployeeImports::FEATURE);
    app(Blueprints::class)->import(['format' => Blueprints::FORMAT, 'features' => [EmployeeImports::FEATURE => true]]);
    expect(EmployeeImports::enabled())->toBeFalse();

    // The operator switches it on from the platform console, with a reason, on both chains.
    actAsTenant(null);
    Livewire::actingAs($operator)->test(ListTenants::class)
        ->callTableAction('importExport', $this->tenant, data: ['enabled' => true, 'reason' => 'Signed data migration addendum'])->assertHasNoTableActionErrors();
    $events = AuditEvent::query()->withoutTenancy()->where('action', 'FEATURE_CHANGED')->where('reason', 'Signed data migration addendum')->get();
    expect($events->pluck('tenant_id')->all())->toEqualCanonicalizing([$this->tenant->id, null]);

    actAsTenant($this->tenant);
    $this->actingAs($this->hr)->get(EmployeeImportResource::getUrl('index'))->assertOk();
});

it('maps columns from the screen', function () {
    $import = app(EmployeeImports::class)->inspect(stageCsv($this->csv));

    Livewire::test(ViewEmployeeImport::class, ['record' => $import->id])->callAction('map')->assertHasNoActionErrors();

    expect($import->refresh()->status)->toBe('mapped')->and($import->mapping['DOJ'])->toBe('employee.joining_date');
});

it('keeps imports inside the tenant and behind the employee.import permission', function () {
    $import = app(EmployeeImports::class)->inspect(stageCsv($this->csv));

    $viewer = tenantUser($this->tenant, ['employee.view']);
    $this->actingAs($viewer)->get(EmployeeImportResource::getUrl('index'))->assertForbidden();
    expect($viewer->can('view', $import))->toBeFalse();

    $this->actingAs($this->hr)->get(EmployeeImportResource::getUrl('view', ['record' => $import]))->assertOk()->assertSee('people.csv');

    $other = tenantUser($this->tenantB, ['*']);
    $this->actingAs($other)->get(EmployeeImportResource::getUrl('view', ['record' => $import]))->assertNotFound();
    actAsTenant($this->tenantB);
    expect(EmployeeImport::query()->count())->toBe(0);
    actAsTenant($this->tenant);

    // Files sit on the private disk under the tenant prefix, never on a public URL.
    expect($import->path)->toStartWith('tenants/'.$this->tenant->id.'/imports/')->and($import->disk)->toBe('local');
    expect(fn () => app(EmployeeImports::class)->discard(app(EmployeeImports::class)->approve(app(EmployeeImports::class)->validate(app(EmployeeImports::class)->map($import, $import->mapping + ['Email' => 'employee.work_email'])), $this->hr)))->not->toThrow(RuntimeException::class);
});

it('updates existing employees from a file with only the employee code and the columns that change, leaving out people not on file', function () {
    $import = app(EmployeeImports::class)->inspect(stageCsv("Employee code,Personal phone,Nationality\nBOSS1,+919800000001,IN\nNEW9,+919800000002,IN\n"));
    expect(fn () => app(EmployeeImports::class)->map($import, $import->mapping))->toThrow(RuntimeException::class, 'Required fields are not mapped: First name');

    $import = app(EmployeeImports::class)->validate(app(EmployeeImports::class)->map($import, $import->mapping, ['mode' => 'update']));
    $rows = $import->rows()->get()->keyBy('row_number');
    expect($import->options)->toBe(['mode' => 'update'])->and($import->update_count)->toBe(1)->and($import->skip_count)->toBe(1)->and($import->error_count)->toBe(0)
        ->and($rows[1]->action)->toBe('update')->and($rows[1]->employee_id)->toBe($this->boss->id)
        ->and($rows[2]->action)->toBe('skip')->and($rows[2]->match['reason'])->toContain('No employee on file has code NEW9');

    app(EmployeeImports::class)->run(app(EmployeeImports::class)->approve($import, $this->hr), $this->hr);
    expect($this->boss->person->refresh()->personal_phone)->toBe('+919800000001')->and($this->boss->person->first_name)->toBe('Bhavna')
        ->and(Employee::query()->count())->toBe(1)
        ->and($import->rows()->where('row_number', 2)->value('result'))->toContain('Skipped: No employee on file has code NEW9');
});

it('keeps review decisions inside the chosen mode', function () {
    app(HireEmployeeAction::class)->handle(['first_name' => 'Asha', 'last_name' => 'Rao', 'date_of_birth' => '1990-05-05'], ['joining_date' => '2025-01-01'], ['company_id' => $this->company->id]);
    $import = app(EmployeeImports::class)->inspect(stageCsv("First Name,Last Name,DOJ,Company Code,DOB\nAsha,Rao,2026-03-01,ALPHA,1990-05-05\n"));
    $import = app(EmployeeImports::class)->validate(app(EmployeeImports::class)->map($import, $import->mapping + ['DOB' => 'person.date_of_birth'], ['mode' => 'new']));
    $row = $import->rows()->first();

    expect($row->action)->toBe('review')
        ->and(fn () => app(EmployeeImports::class)->resolveReview($row, 'update'))->toThrow(RuntimeException::class, 'add new employees')
        ->and(app(EmployeeImports::class)->resolveReview($row, 'skip')->action)->toBe('skip');
});
