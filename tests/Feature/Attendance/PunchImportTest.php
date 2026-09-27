<?php

use App\Domain\Attendance\Imports\PunchImports;
use App\Domain\Attendance\Models\AttendancePunch;
use App\Domain\Attendance\Models\AttendanceRecord;
use App\Domain\Audit\Models\AuditEvent;
use App\Domain\Employment\Actions\HireEmployeeAction;
use App\Domain\Employment\Imports\EmployeeImport;
use App\Domain\Employment\Imports\EmployeeImports;
use App\Domain\Organisation\Models\Company;
use App\Domain\Organisation\Models\Location;
use App\Filament\Resources\EmployeeImports\EmployeeImportResource;
use App\Filament\Resources\PunchImports\PunchImportResource;
use Illuminate\Support\Facades\Storage;

require_once __DIR__.'/AttendanceTestHelpers.php';

/* Phase 2 §32: raw punches are the ingestion boundary; the file never writes attendance records directly. */

beforeEach(function () {
    $this->travelTo('2026-09-24 10:00:00');
    Storage::fake('local');
    config(['peopleos.documents.disk' => 'local']);
    $this->tenant = provisionTenant();
    actAsTenant($this->tenant);
    $this->hr = tenantUser($this->tenant, ['*']);
    $this->actingAs($this->hr);
    $this->company = Company::factory()->create();
    $this->schedule = weeklySchedule(generalShift());
    $kolkata = Location::factory()->create(['company_id' => $this->company->id, 'timezone' => 'Asia/Kolkata']);
    $this->asha = app(HireEmployeeAction::class)->handle(['first_name' => 'Asha', 'last_name' => 'Rao'], ['joining_date' => '2025-01-01', 'employee_code' => 'EMP1'], ['company_id' => $this->company->id, 'location_id' => $kolkata->id]);
    assignSchedule($this->asha, $this->schedule);
    $this->imports = app(PunchImports::class);
});

function stagePunchCsv(string $csv): EmployeeImport
{
    $path = EmployeeImports::directory().'/'.uniqid().'.csv';
    Storage::disk('local')->put($path, $csv);

    return app(PunchImports::class)->register($path, 'punches.csv', auth()->user());
}

it('imports punches through mapping, validation, duplicate detection, approval and audit, then recalculates attendance', function () {
    $csv = "Employee,Timestamp,Direction,Punch Id\nEMP1,2026-09-23 09:00:00,in,P1\nEMP1,2026-09-23 18:00:00,out,P2\nEMP1,2026-09-23 18:00:00,out,P2\nNOBODY,2026-09-23 09:00:00,in,P3\nEMP1,not a time,in,P4\n";
    $import = $this->imports->inspect(stagePunchCsv($csv));
    expect($import->type)->toBe('punches')->and($import->mapping['Employee'])->toBe('employee_code')->and($import->mapping['Timestamp'])->toBe('punched_at')->and($import->mapping['Punch Id'])->toBe('external_id');

    $import = $this->imports->map($import, $import->mapping, ['timezone' => 'Asia/Kolkata']);
    $import = $this->imports->validate($import);
    $rows = $import->rows()->get()->keyBy('row_number');
    expect($import->create_count)->toBe(2)->and($import->error_count)->toBe(3)
        ->and($rows[3]->errors[0])->toContain('Duplicate of row 2')
        ->and($rows[4]->errors[0])->toContain("'NOBODY' does not exist")
        ->and($rows[5]->errors[0])->toContain('not a valid date');
    expect(AttendancePunch::query()->count())->toBe(0)->and(AttendanceRecord::query()->count())->toBe(0);

    $import = $this->imports->run($this->imports->approve($import, $this->hr, 'Device export'), $this->hr);
    expect($import->status)->toBe('imported')->and($import->create_count)->toBe(2)->and($import->failure_count)->toBe(0);
    expect(AttendancePunch::query()->count())->toBe(2)
        ->and(AttendancePunch::query()->where('external_id', 'P1')->first()->punched_at->toIso8601String())->toBe('2026-09-23T03:30:00+00:00') // IST -> UTC
        ->and(AttendancePunch::query()->where('external_id', 'P1')->first()->source_type)->toBe('import');
    expect(AttendanceRecord::query()->where('employee_id', $this->asha->id)->whereDate('date', '2026-09-23')->value('status'))->toBe('present');
    expect(AuditEvent::query()->where('operation_id', $import->operation_id)->where('action', 'PUNCH_IMPORTED')->count())->toBe(2)
        ->and(AuditEvent::query()->where('operation_id', $import->operation_id)->where('action', 'BULK_OPERATION')->exists())->toBeTrue();

    // Re-importing the same file records nothing twice.
    $again = $this->imports->validate($this->imports->map($this->imports->inspect(stagePunchCsv($csv)), $import->mapping, ['timezone' => 'Asia/Kolkata']));
    expect($again->create_count)->toBe(0)->and($again->skip_count)->toBe(2);
    expect(fn () => $this->imports->approve($again, $this->hr))->toThrow(RuntimeException::class, 'Nothing to import');
});

it('keeps punch imports apart from employee imports and behind attendance.manage + employee.import', function () {
    $import = $this->imports->inspect(stagePunchCsv("Employee,Timestamp\nEMP1,2026-09-23 09:00:00\n"));
    expect(fn () => app(EmployeeImports::class)->inspect($import))->toThrow(RuntimeException::class);
    $this->get(PunchImportResource::getUrl('view', ['record' => $import]))->assertOk()->assertSee('punches.csv');
    $this->get(EmployeeImportResource::getUrl('view', ['record' => $import]))->assertNotFound();

    $viewer = tenantUser($this->tenant, ['attendance.view', 'employee.import']);
    $this->actingAs($viewer)->get(PunchImportResource::getUrl('index'))->assertForbidden();
});
