<?php

use App\Domain\Attendance\Imports\AttendanceSheetImports;
use App\Domain\Attendance\Models\AttendanceMark;
use App\Domain\Attendance\Models\AttendanceRecord;
use App\Domain\Attendance\Services\AttendanceOutput;
use App\Domain\Attendance\Services\AttendanceProcessor;
use App\Domain\Audit\Models\AuditEvent;
use App\Domain\Employment\Actions\HireEmployeeAction;
use App\Domain\Employment\Enums\ImportMode;
use App\Domain\Employment\Imports\EmployeeImport;
use App\Domain\Employment\Imports\EmployeeImports;
use App\Domain\Employment\Models\Employee;
use App\Domain\Leave\Models\LeaveRequest;
use App\Domain\Leave\Models\LeaveType;
use App\Domain\Organisation\Models\Company;
use App\Domain\Payroll\Services\PayCycle;
use App\Filament\Resources\AttendanceSheetImports\AttendanceSheetImportResource;
use App\Filament\Resources\AttendanceSheetImports\Pages\ListAttendanceSheetImports;
use App\Filament\Resources\AttendanceSheetImports\Pages\ViewAttendanceSheetImport;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

require_once __DIR__.'/AttendanceTestHelpers.php';

/* Monthly attendance uploads: a sheet of day codes becomes marks the engine applies; the file never writes attendance days. */

beforeEach(function () {
    $this->travelTo('2026-10-20 10:00:00'); // 1 October 2026 is a Thursday
    Storage::fake('local');
    config(['peopleos.documents.disk' => 'local']);
    $this->tenant = provisionTenant();
    actAsTenant($this->tenant);
    $this->hr = tenantUser($this->tenant, ['employee.view', 'employee.import', 'attendance.view', 'attendance.manage']);
    $this->actingAs($this->hr);
    $company = Company::factory()->create();
    $schedule = weeklySchedule(generalShift());
    $hire = function (string $first, string $code, string $joined) use ($company, $schedule) {
        $employee = app(HireEmployeeAction::class)->handle(['first_name' => $first, 'last_name' => 'Test'], ['joining_date' => $joined, 'employee_code' => $code], ['company_id' => $company->id]);
        assignSchedule($employee, $schedule);

        return $employee;
    };
    $this->asha = $hire('Asha', 'EMP1', '2025-01-01');
    $this->vikram = $hire('Vikram', 'EMP2', '2026-10-07');
    $this->neel = $hire('Neel', 'EMP3', '2025-01-01');
    $this->imports = app(AttendanceSheetImports::class);
});

function stageSheetCsv(string $csv, string $month = '2026-10', ImportMode $mode = ImportMode::Both, string $blank = 'calculate'): EmployeeImport
{
    $path = EmployeeImports::directory().'/'.uniqid().'.csv';
    Storage::disk('local')->put($path, $csv);

    return app(AttendanceSheetImports::class)->register($path, 'attendance.csv', auth()->user(), $month, $mode, $blank);
}

function runSheet(string $csv, ImportMode $mode = ImportMode::Both, string $blank = 'calculate', string $month = '2026-10'): EmployeeImport
{
    $imports = app(AttendanceSheetImports::class);
    $import = $imports->inspect(stageSheetCsv($csv, $month, $mode, $blank));
    $import = $imports->validate($imports->map($import, $import->mapping));

    return $import->create_count + $import->update_count > 0 ? $imports->run($imports->approve($import, auth()->user(), 'October attendance'), auth()->user()) : $import;
}

function dayStatus(Employee $employee, string $date): ?string
{
    return AttendanceRecord::query()->where('employee_id', $employee->id)->whereDate('date', $date)->value('status');
}

it('marks the month from the sheet, recalculates the marked days and feeds absences to the payroll output', function () {
    $csv = "Employee code,Employee name,1 Thu,2 Fri,5 Mon,6 Tue,7 Wed,8 Thu,25 Sun\n"
        ."EMP1,Asha Test,P,a,HD,P,WFH,on duty,\n"
        ."EMP2,Vikram Test,,P,,,P,P,\n"         // day 2 is before Vikram joined
        ."NOBODY,,P,,,,,,\n"
        ."EMP3,Neel Test,X,CL,,Sick leave,,,P\n" // not a code; leave is applied for on the portal; day 25 is still to come
        ."EMP1,Asha again,P,,,,,,\n";
    $import = $this->imports->inspect(stageSheetCsv($csv));
    expect($import->mapping)->toMatchArray(['Employee code' => 'employee_code', '1 Thu' => 'day.1', '2 Fri' => 'day.2', '25 Sun' => 'day.25'])
        ->and($import->mapping)->not->toHaveKey('Employee name');

    $import = $this->imports->validate($this->imports->map($import, $import->mapping));
    $rows = $import->rows()->get()->keyBy('row_number');
    expect($import->create_count)->toBe(1)->and($import->error_count)->toBe(4)
        ->and($rows[1]->match['reason'])->toBe('marks 6 day(s)')
        ->and($rows[2]->errors)->toContain('Day 2 is before the joining date (2026-10-07); leave them blank.')
        ->and($rows[3]->errors[0])->toContain("'NOBODY' does not exist")
        ->and(implode(' ', $rows[4]->errors))->toContain("Not a day code: day 1 ('X')")->toContain('Day 25 is still to come')
        ->toContain("Leave cannot be marked by upload: day 2 ('CL'), day 6 ('Sick leave'). Employees apply for leave on the portal")
        ->and($rows[5]->errors)->toContain('Employee EMP1 also appears in row 1.');
    expect(AttendanceMark::query()->count())->toBe(0)->and(AttendanceRecord::query()->count())->toBe(0);

    $import = $this->imports->run($this->imports->approve($import, $this->hr, 'October attendance'), $this->hr);
    expect($import->status)->toBe('imported')->and($import->create_count)->toBe(1)->and($import->failure_count)->toBe(0)
        ->and(AttendanceMark::query()->where('employee_id', $this->asha->id)->count())->toBe(6)
        ->and(AuditEvent::query()->where('operation_id', $import->operation_id)->where('action', 'BULK_OPERATION')->exists())->toBeTrue();

    // The engine applied each mark; the weekend between came from the schedule.
    expect(collect(['2026-10-01', '2026-10-02', '2026-10-03', '2026-10-05', '2026-10-06', '2026-10-07', '2026-10-08'])->mapWithKeys(fn ($d) => [$d => dayStatus($this->asha, $d)])->all())
        ->toBe(['2026-10-01' => 'present', '2026-10-02' => 'absent', '2026-10-03' => 'weekly_off', '2026-10-05' => 'half_day', '2026-10-06' => 'present', '2026-10-07' => 'wfh', '2026-10-08' => 'on_duty']);
    $absent = AttendanceRecord::query()->where('employee_id', $this->asha->id)->whereDate('date', '2026-10-02')->first();
    expect($absent->exceptions)->toBeNull()->and($absent->calculation_basis['attendance_mark'])->toBe('absent')
        ->and(app(AttendanceOutput::class)->summary($this->asha, '2026-10-01', '2026-10-08')['absent_days'])->toBe(1);
});

it('marks each half of a day on its own, and on a half-day leave takes the other half from the sheet', function () {
    LeaveRequest::create(['employee_id' => $this->asha->id, 'leave_type_id' => LeaveType::query()->where('category', '!=', 'unpaid')->value('id'), 'from_date' => '2026-10-08', 'to_date' => '2026-10-08',
        'from_session' => 'second_half', 'to_session' => 'second_half', 'days' => 0.5, 'dates' => [['date' => '2026-10-08', 'days' => 0.5, 'session' => 'second_half']], 'reason' => 'Doctor', 'status' => 'approved']);

    $import = runSheet("Employee code,1,2,5,6,7,8\nEMP1,P|P,P|AB,a | wfh,A|A,WO|P,P|A\nEMP3,AB|HD,P|,P|A|P,,,\n");
    $rows = $import->rows()->get()->keyBy('row_number');
    expect($rows[1]->status)->toBe('imported')->and($rows[1]->result)->toBe('Marks 6 day(s)')
        ->and($rows[2]->errors)->toBe([
            "Not a day code: day 2 ('P|'), day 5 ('P|A|P'). Use P, A, HD, WO, H, WFH, OD for the whole day, or a code for each half such as P|A.",
            "HD is a whole day (a present and an absent half), not a half: day 1 ('AB|HD'). Give each half P, A, WO, H, WFH or OD, such as A|P.",
        ]);

    // One absent half is a half day (half a day's pay); two are an absent day.
    expect(collect(['2026-10-01', '2026-10-02', '2026-10-05', '2026-10-06', '2026-10-07', '2026-10-08'])->mapWithKeys(fn ($d) => [$d => dayStatus($this->asha, $d)])->all())
        ->toBe(['2026-10-01' => 'present', '2026-10-02' => 'half_day', '2026-10-05' => 'half_day', '2026-10-06' => 'absent', '2026-10-07' => 'present', '2026-10-08' => 'present']);
    $split = AttendanceMark::query()->where('employee_id', $this->asha->id)->whereDate('date', '2026-10-05')->sole();
    expect($split->first_half)->toBe('absent')->and($split->second_half)->toBe('wfh')->and($split->status)->toBe('half_day')
        ->and(AttendanceRecord::query()->where('employee_id', $this->asha->id)->whereDate('date', '2026-10-05')->value('calculation_basis')['attendance_mark'])->toBe('absent|wfh');
    // The afternoon of 8 October is leave, so the morning (P) decides the day.
    expect(AttendanceRecord::query()->where('employee_id', $this->asha->id)->whereDate('date', '2026-10-08')->value('is_half_day_leave'))->toBeTrue();

    // Correcting a split day replaces it; writing the same halves again changes nothing.
    $fix = runSheet("Employee code,2,5\nEMP1,P|P,a|WFH\n", ImportMode::Update);
    expect($fix->rows()->first()->result)->toBe('Marks 1 day(s) (1 replaced); 1 day(s) already marked the same')
        ->and(dayStatus($this->asha, '2026-10-02'))->toBe('present');
});

it('reads blank days as present on working days when asked, and lets the uploader fill new days or correct uploaded ones', function () {
    runSheet("Employee code,2\nEMP1,A\n", blank: 'present');
    expect(AttendanceMark::query()->where('employee_id', $this->asha->id)->count())->toBe(20) // 1–20 October, today included
        ->and(dayStatus($this->asha, '2026-10-01'))->toBe('present')->and(dayStatus($this->asha, '2026-10-02'))->toBe('absent')
        ->and(dayStatus($this->asha, '2026-10-04'))->toBe('weekly_off')->and(dayStatus($this->asha, '2026-10-20'))->toBe('present');

    // Fill days not uploaded yet: days uploaded before are kept.
    $new = runSheet("Employee code,2,5\nEMP1,P,A\n", ImportMode::New);
    expect($new->rows()->first()->action)->toBe('skip')->and($new->rows()->first()->match['reason'])->toBe('2 day(s) uploaded before are kept (Fill days not uploaded yet)')
        ->and(dayStatus($this->asha, '2026-10-02'))->toBe('absent');

    // Correct days already uploaded: replaces them, and skips days nobody uploaded.
    $fix = runSheet("Employee code,2\nEMP1,P\nEMP2,P\n", ImportMode::Update);
    $rows = $fix->rows()->get()->keyBy('row_number');
    expect($rows[1]->action)->toBe('update')->and($rows[1]->result)->toBe('Marks 1 day(s) (1 replaced)')
        ->and($rows[2]->errors)->toContain('Day 2 is before the joining date (2026-10-07); leave them blank.')
        ->and(dayStatus($this->asha, '2026-10-02'))->toBe('present');
    $skipped = runSheet("Employee code,8\nEMP2,P\n", ImportMode::Update);
    expect($skipped->rows()->first()->action)->toBe('skip')->and($skipped->rows()->first()->match['reason'])->toContain('1 day(s) not uploaded before are skipped');
});

it('leaves approved leave to the leave and refuses days locked by payroll', function () {
    $paid = LeaveType::query()->where('category', '!=', 'unpaid')->value('id');
    $leave = fn (string $date, string $session) => LeaveRequest::create(['employee_id' => $this->asha->id, 'leave_type_id' => $paid, 'from_date' => $date, 'to_date' => $date,
        'from_session' => $session, 'to_session' => $session, 'days' => $session === 'full' ? 1 : 0.5, 'dates' => [['date' => $date, 'days' => $session === 'full' ? 1 : 0.5, 'session' => $session]], 'reason' => 'Family', 'status' => 'approved']);
    $leave('2026-10-06', 'full');
    $leave('2026-10-07', 'first_half');

    // A full day of leave is left alone whatever the sheet says; on a half-day leave the code is for the other half.
    $sheet = runSheet("Employee code,5,6,7\nEMP1,P,A,A\n");
    $halfDay = AttendanceRecord::query()->where('employee_id', $this->asha->id)->whereDate('date', '2026-10-07')->sole();
    expect($sheet->rows()->first()->result)->toBe('Marks 2 day(s); 1 day(s) of approved leave stay as leave')
        ->and(AttendanceMark::query()->where('employee_id', $this->asha->id)->whereDate('date', '2026-10-06')->exists())->toBeFalse()
        ->and(dayStatus($this->asha, '2026-10-06'))->toBe('leave')
        ->and($halfDay->status)->toBe('leave')->and($halfDay->is_half_day_leave)->toBeTrue(); // paid as half a day by payroll-2.5

    app(AttendanceProcessor::class)->process($this->neel, '2026-10-01');
    AttendanceRecord::query()->where('employee_id', $this->neel->id)->update(['is_locked' => true]);
    $locked = runSheet("Employee code,1,2\nEMP3,A,A\n");
    expect($locked->rows()->first()->errors)->toBe(['Day 1 is locked by a finalised payroll run and cannot change.']);

    // A day beyond the month's end and a month still to come are refused too.
    $september = runSheet("Employee code,31\nEMP1,P\n", month: '2026-09');
    expect($september->rows()->first()->errors)->toBe(['Day 31 is not in September 2026; leave it blank.'])
        ->and(fn () => stageSheetCsv("Employee code,1\nEMP1,P\n", '2026-11'))->toThrow(RuntimeException::class, 'cannot be in the future');
});

it('downloads the month sheet with everyone employed in it, maps it from the page, and needs attendance.manage + employee.import', function () {
    Livewire::test(ListAttendanceSheetImports::class)->callAction('downloadTemplate', ['month' => '2026-10'])->assertFileDownloaded('attendance-2026-10.csv');

    $sheet = $this->imports->template('2026-09');
    $header = str_getcsv(strtok($sheet, "\n"), escape: '');
    expect($header[0])->toBe('Employee code')->and($header[2])->toBe('1 Tue')->and(count($header))->toBe(32)
        ->and($sheet)->toContain('EMP1,')->not->toContain('EMP2,'); // Vikram joined in October

    $import = $this->imports->inspect(stageSheetCsv(preg_replace('/^(EMP1,[^,]*,),/m', '$1P,', $sheet), '2026-09'));
    Livewire::test(ViewAttendanceSheetImport::class, ['record' => $import->getRouteKey()])
        ->callAction('map', ['month' => '2026-09', 'mode' => 'new', 'blank_days' => 'calculate'])->assertHasNoActionErrors()
        ->callAction('validate');
    expect($import->refresh()->status)->toBe('validated')->and($import->options['mode'])->toBe('new')->and($import->create_count)->toBe(1)->and($import->skip_count)->toBe(1);
    $this->get(AttendanceSheetImportResource::getUrl('view', ['record' => $import]))->assertOk()->assertSee('September 2026')->assertSee('Fill days not uploaded yet');

    $importer = tenantUser($this->tenant, ['employee.view', 'employee.import']);
    $this->actingAs($importer)->get(AttendanceSheetImportResource::getUrl('index'))->assertForbidden();
    expect(fn () => stageSheetCsv("Employee code,1\nEMP1,P\n"))->toThrow(RuntimeException::class, 'attendance.manage');
});

it('follows a 25th-to-24th pay cycle and keeps the dates an upload was made for when the cycle changes', function () {
    app(PayCycle::class)->change(25, 'Client runs attendance from the 25th to the 24th');

    // 20 October falls in October's 25 September – 24 October.
    expect(AttendanceSheetImports::currentMonth())->toBe('2026-10')
        ->and(AttendanceSheetImports::months()['2026-10'])->toBe('October 2026 (25 Sep – 24 Oct)')
        ->and($this->imports->format()['columns'][2][0])->toBe('25 … 24');
    $header = str_getcsv(strtok($this->imports->template('2026-10'), "\n"), escape: '');
    expect($header[2])->toBe('25 Fri')->and(end($header))->toBe('24 Sat')->and(count($header))->toBe(32);

    $import = $this->imports->inspect(stageSheetCsv("Employee code,28 Mon,2 Fri\nEMP1,A,A\n"));
    app(PayCycle::class)->change(1);
    $import = $this->imports->validate($this->imports->map($import, $import->mapping));
    $this->imports->run($this->imports->approve($import, auth()->user(), 'October attendance'), auth()->user());
    expect(AttendanceMark::query()->where('employee_id', $this->asha->id)->orderBy('date')->get()->map(fn ($m) => $m->date->toDateString())->all())
        ->toBe(['2026-09-28', '2026-10-02']);

    app(PayCycle::class)->change(25);
    $march = runSheet("Employee code,30\nEMP1,P\n", month: '2026-03');
    expect($march->rows()->first()->errors)->toBe(['Day 30 is not in March 2026 (25 Feb – 24 Mar); leave it blank.']);
});
