<?php

use App\Domain\Attendance\Imports\DayOverrideImports;
use App\Domain\Attendance\Imports\GeoLocationImports;
use App\Domain\Attendance\Imports\OvertimeImports;
use App\Domain\Attendance\Imports\ShiftRosterImports;
use App\Domain\Attendance\Models\AttendanceRecord;
use App\Domain\Attendance\Models\GeoFence;
use App\Domain\Attendance\Models\ShiftOverride;
use App\Domain\Attendance\Models\TimeOfficeRequest;
use App\Domain\Employment\Actions\HireEmployeeAction;
use App\Domain\Employment\Imports\EmployeeImport;
use App\Domain\Employment\Imports\EmployeeImports;
use App\Domain\Employment\Imports\RowImports;
use App\Domain\Employment\Imports\RowImportTypes;
use App\Domain\Leave\Imports\LeaveBalanceImports;
use App\Domain\Leave\Imports\LeaveHistoryImports;
use App\Domain\Leave\Models\LeaveRequest;
use App\Domain\Leave\Models\LeaveType;
use App\Domain\Leave\Services\LeaveAdjustments;
use App\Domain\Leave\Services\LeaveBalances;
use App\Domain\Organisation\Models\Company;
use App\Domain\Organisation\Models\Location;
use App\Filament\Support\ImportActions;
use Illuminate\Support\Facades\Storage;

require_once __DIR__.'/../Attendance/AttendanceTestHelpers.php';
require_once __DIR__.'/../Leave/LeaveTestHelpers.php';

/* HROne parity HP-1b: time-office uploads on the staged pipeline (inspect → map → validate → approve → run). */

beforeEach(function () {
    $this->travelTo('2026-09-25 10:00:00');
    Storage::fake('local');
    config(['peopleos.documents.disk' => 'local']);
    $this->tenant = provisionTenant();
    actAsTenant($this->tenant);
    $this->hr = tenantUser($this->tenant, ['*']);
    $this->actingAs($this->hr);
    $company = Company::factory()->create();
    $this->location = Location::factory()->create(['company_id' => $company->id, 'code' => 'DEL']);
    $this->shift = generalShift();
    $this->asha = app(HireEmployeeAction::class)->handle(['first_name' => 'Asha', 'last_name' => 'Rao'], ['joining_date' => '2025-01-01', 'employee_code' => 'EMP1'], ['company_id' => $company->id, 'location_id' => $this->location->id]);
    assignSchedule($this->asha, weeklySchedule($this->shift));
});

/** Stage, inspect, map automatically, validate; returns the validated import. */
function stageRows(RowImports $service, string $csv, array $options = []): EmployeeImport
{
    $path = EmployeeImports::directory().'/'.uniqid().'.csv';
    Storage::disk('local')->put($path, $csv);
    $import = $service->register($path, 'upload.csv', auth()->user());
    $service->inspect($import);
    $service->map($import->refresh(), $import->refresh()->mapping, $options);

    return $service->validate($import->refresh());
}

function runRows(RowImports $service, EmployeeImport $import): EmployeeImport
{
    $service->approve($import, auth()->user(), 'Go-live');

    return $service->run($import->refresh(), auth()->user());
}

it('keeps every upload type within the 16 characters employee_imports.type holds', function () {
    foreach (array_keys(RowImportTypes::TYPES) as $type) {
        expect(strlen($type))->toBeLessThanOrEqual(16, $type);
    }
});

it('registers every time-office upload with the pipeline and its format guide', function () {
    foreach ([LeaveBalanceImports::TYPE, LeaveHistoryImports::TYPE, ShiftRosterImports::TYPE, GeoLocationImports::TYPE, OvertimeImports::TYPE, DayOverrideImports::TYPE] as $type) {
        $data = ImportActions::formatData($type);
        expect($data['columns'])->not->toBeEmpty()->and($data['sampleRows'])->not->toBeEmpty();
    }
});

it('loads opening balances once and posts adjustments through the ledger', function () {
    leavePolicy(['EL' => ['days' => 0], 'CL' => ['days' => 0]]);
    $service = app(LeaveBalanceImports::class);
    $csv = "Employee code,Leave code,Days,Kind (opening/adjustment),Leave year\nEMP1,EL,12,opening,2026\nEMP1,CL,-1,adjustment,2026\nNOPE,EL,1,opening,2026\n";

    $import = stageRows($service, $csv);
    expect($import->create_count)->toBe(1)->and($import->update_count)->toBe(1)->and($import->error_count)->toBe(1);
    runRows($service, $import);

    $el = LeaveType::query()->where('code', 'EL')->first();
    expect(app(LeaveBalances::class)->balance($this->asha, $el, 2026)->available())->toBe(12.0);

    $again = stageRows($service, "Employee code,Leave code,Days\nEMP1,EL,15\n");
    expect($again->skip_count)->toBe(1)->and($again->create_count)->toBe(0);
});

it('loads leave history as approved requests and skips leave already on file', function () {
    leavePolicy(['CL' => ['days' => 12]]);
    $service = app(LeaveHistoryImports::class);
    app(LeaveAdjustments::class)->opening($this->asha, LeaveType::query()->where('code', 'CL')->first(), 6, 2026, 'Go-live');
    $import = runRows($service, stageRows($service, "Employee code,Leave code,From date,To date\nEMP1,CL,2026-09-21,2026-09-22\n"));

    expect($import->create_count)->toBe(1)->and(LeaveRequest::query()->where('employee_id', $this->asha->id)->value('status'))->toBe('approved');
    expect(stageRows($service, "Employee code,Leave code,From date\nEMP1,CL,2026-09-22\n")->skip_count)->toBe(1);
});

it('rosters shifts and weekly offs from a file', function () {
    $service = app(ShiftRosterImports::class);
    runRows($service, stageRows($service, "Employee code,Date,To date,Shift code (WO = weekly off)\nEMP1,2026-09-28,2026-09-29,GEN\nEMP1,2026-09-30,,WO\nEMP1,2026-10-01,,NOPE\n"));

    expect(ShiftOverride::query()->where('employee_id', $this->asha->id)->count())->toBe(3)
        ->and(ShiftOverride::query()->whereDate('date', '2026-09-30')->value('shift_id'))->toBeNull()
        ->and(ShiftOverride::query()->value('source'))->toBe('import');
});

it('adds and updates geo locations by name and owner', function () {
    $service = app(GeoLocationImports::class);
    runRows($service, stageRows($service, "Name,Latitude,Longitude,Radius (m),Work location code\nHQ,28.6139,77.2090,150,DEL\nBad,95,77,100,\n"));
    expect(GeoFence::query()->count())->toBe(1)->and(GeoFence::query()->value('location_id'))->toBe($this->location->id);

    $update = stageRows($service, "Name,Latitude,Longitude,Radius (m),Work location code\nHQ,28.6139,77.2090,300,DEL\n");
    expect($update->update_count)->toBe(1);
    runRows($service, $update);
    expect(GeoFence::query()->value('radius_m'))->toBe(300);
});

it('approves overtime from a file as claims and applies day overrides', function () {
    $ot = app(OvertimeImports::class);
    runRows($ot, stageRows($ot, "Employee code,Date,Overtime hours\nEMP1,2026-09-23,01:30\n"));
    $claim = TimeOfficeRequest::query()->sole();
    expect($claim->type)->toBe('overtime')->and($claim->status)->toBe('approved')->and($claim->minutes)->toBe(90)
        ->and(AttendanceRecord::query()->whereDate('date', '2026-09-23')->value('overtime_approved_minutes'))->toBe(90);

    $overrides = app(DayOverrideImports::class);
    runRows($overrides, stageRows($overrides, "Employee code,Date,Status,Reason\nEMP1,2026-09-24,present,Biometric fault\n"));
    expect(AttendanceRecord::query()->whereDate('date', '2026-09-24')->value('status'))->toBe('present');
});
