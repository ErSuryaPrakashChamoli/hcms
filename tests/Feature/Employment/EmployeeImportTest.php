<?php

use App\Domain\Audit\Models\AuditEvent;
use App\Domain\Employment\Actions\HireEmployeeAction;
use App\Domain\Employment\Imports\EmployeeImport;
use App\Domain\Employment\Imports\EmployeeImports;
use App\Domain\Employment\Models\Employee;
use App\Domain\Organisation\Models\Company;
use App\Domain\Organisation\Models\Department;
use App\Domain\Organisation\Models\Designation;
use App\Domain\Organisation\Models\Location;
use App\Filament\Resources\EmployeeImports\EmployeeImportResource;
use Illuminate\Support\Facades\Storage;

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

    // "skip" option turns definite matches into skips.
    $again = app(EmployeeImports::class)->inspect(stageCsv($csv));
    $again = app(EmployeeImports::class)->map($again, $again->mapping + ['Email' => 'employee.work_email'], ['on_duplicate' => 'skip']);
    $again = app(EmployeeImports::class)->validate($again);
    expect($again->rows()->where('row_number', 1)->first()->action)->toBe('skip');
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
