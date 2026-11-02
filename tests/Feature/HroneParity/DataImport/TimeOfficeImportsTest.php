<?php

use App\Domain\Attendance\Imports\AttendanceArrearImports;
use App\Domain\Attendance\Imports\OvertimeArrearImports;
use App\Domain\Attendance\Imports\PaidDayAdjustmentImports;
use App\Domain\Attendance\Models\AttendanceArrear;
use App\Domain\Attendance\Models\AttendanceRecord;
use App\Domain\Attendance\Models\PaidDayAdjustment;
use App\Domain\Attendance\Models\TimeOfficeRequest;
use App\Domain\Attendance\Models\WorkSchedule;
use App\Domain\Attendance\Services\AttendanceOutput;
use App\Domain\Attendance\Services\AttendanceProcessor;
use App\Domain\Employment\Actions\HireEmployeeAction;
use App\Domain\Employment\Imports\ImportCatalogue;
use App\Domain\Employment\Imports\ImportTypes;
use App\Domain\Employment\Imports\RowImportTypes;
use App\Domain\Leave\Imports\CompOffImports;
use App\Domain\Leave\Models\LeaveType;
use App\Domain\Leave\Services\CompOffCredits;
use App\Domain\Leave\Services\LeaveBalances;
use App\Domain\Organisation\Models\Company;
use App\Domain\Organisation\Models\Location;
use App\Domain\Payroll\Inputs\AttendanceArrearInput;
use App\Domain\Payroll\Models\PayrollPeriod;
use App\Domain\Payroll\Models\PayrollRun;
use App\Domain\Payroll\Models\SalaryComponent;
use App\Domain\Payroll\Services\PayrollCalculator;
use App\Domain\Platform\Services\SettingsRepository;
use Illuminate\Support\Facades\Storage;
use League\Csv\Reader;

require_once __DIR__.'/Di35Helpers.php';
require_once __DIR__.'/../../Attendance/AttendanceTestHelpers.php';
require_once __DIR__.'/../../Leave/LeaveTestHelpers.php';
require_once __DIR__.'/../../Workflow/WorkflowTestHelpers.php';
require_once __DIR__.'/../../Payroll/PayrollTestHelpers.php';

/*
 | Data import DI-35, Time Office head: comp-off credits, override arrear days, override paid days and override previous
 | month overtime, each through its domain service; and the presets on day overrides and leave balances.
 */

beforeEach(function () {
    $this->travelTo('2026-11-03 10:00:00'); // October 2026 has ended
    Storage::fake('local');
    config(['peopleos.documents.disk' => 'local']);
    $this->tenant = provisionTenant();
    actAsTenant($this->tenant);
    $this->hr = tenantUser($this->tenant, ['*']);
    $this->actingAs($this->hr);
    $this->company = Company::factory()->create(['code' => 'ACME']);
    $location = Location::factory()->create(['company_id' => $this->company->id, 'code' => 'DEL']);
    $hire = fn (string $first, string $code) => app(HireEmployeeAction::class)->handle(['first_name' => $first, 'last_name' => 'Rao'],
        ['joining_date' => '2025-01-01', 'employee_code' => $code], ['company_id' => $this->company->id, 'location_id' => $location->id]);
    $this->asha = $hire('Asha', 'EMP1');
    $this->ravi = $hire('Ravi', 'EMP2');
    $schedule = weeklySchedule(generalShift());
    assignSchedule($this->asha, $schedule);
    assignSchedule($this->ravi, $schedule);
});

function di35Setting(string $key, mixed $value): void
{
    app(SettingsRepository::class)->set($key, $value, 'test');
}

it('registers the four time-office uploads under Time Office with their module rights', function () {
    foreach ([CompOffImports::TYPE => 'leave.adjust', AttendanceArrearImports::TYPE => 'attendance.arrears', PaidDayAdjustmentImports::TYPE => 'attendance.override', OvertimeArrearImports::TYPE => 'attendance.manage'] as $type => $permission) {
        expect(RowImportTypes::TYPES[$type][2])->toBe($permission)->and(RowImportTypes::TYPES[$type][3])->toBe('time_office');
    }
    expect(ImportTypes::modes(CompOffImports::TYPE))->toBeNull() // credits only add
        ->and(ImportTypes::modes(AttendanceArrearImports::TYPE))->not->toBeNull()
        ->and(ImportTypes::allowed(tenantUser($this->tenant, ['employee.import', 'attendance.view']), AttendanceArrearImports::TYPE))->toBeFalse()
        ->and(ImportTypes::allowed(tenantUser($this->tenant, ['employee.import', 'attendance.arrears']), AttendanceArrearImports::TYPE))->toBeTrue();
});

it('describes every DI-35 upload column by column, in field order, with two example rows', function () {
    $types = ['comp_off_credits', 'attendance_arrears', 'paid_day_adjustments', 'overtime_arrears', 'goal_bank', 'goals', 'initiatives', 'improvement_plans', 'review_ratings',
        'rating_normalisation', 'review_normalisation', 'okr_final', 'okr_total', 'badge_awards', 'reward_points', 'asset_types', 'asset_subtypes', 'asset_products', 'assets'];
    foreach ($types as $type) {
        $service = ImportTypes::service($type);
        $format = $service->format();
        $template = iterator_to_array(Reader::fromString($service->template())->getRecords(), false);
        expect(array_column($format['columns'], 0))->toBe(array_column($service::fields(), 0), $type)
            ->and(collect($format['columns'])->every(fn (array $c) => count($c) === 4 && in_array($c[1], ['Yes', 'No'], true)))->toBeTrue($type)
            ->and(collect($format['columns'])->filter(fn (array $c) => $c[1] === 'Yes')->map(fn (array $c) => $c[0])->values()->all())
            ->toBe(collect($service::fields())->filter(fn (array $f) => $f[1])->map(fn (array $f) => $f[0])->values()->all(), $type)
            ->and(count($template))->toBeGreaterThanOrEqual(3, $type)
            ->and(collect(array_slice($template, 1))->every(fn (array $row) => count($row) === count($service::fields())))->toBeTrue($type)
            ->and(strlen($type))->toBeLessThanOrEqual(32);
    }
});

it('credits comp-off with its expiry through an approved claim, skips a day already claimed and refuses bad rows', function () {
    leavePolicy(['CO' => ['days' => 0]]);
    $csv = "Employee code,Date worked,Days (1 or 0.5),Expiry date,Reason\nEMP1,2026-10-31,1,2026-12-31,Release weekend\nEMP2,2026-10-25,0.5,,Half Sunday\n"
        ."EMP1,2026-10-24,2,,Too many\nNOPE,2026-10-24,1,,Unknown\nEMP2,2026-11-08,1,,Future\nEMP2,2026-10-18,1,2026-10-01,Expired before worked\n";
    $import = di35Stage(CompOffImports::TYPE, $csv);
    expect($import->create_count)->toBe(2)->and($import->error_count)->toBe(4)
        ->and(di35Errors($import)[3])->toContain('Days must be 1 (a full day) or 0.5 (a half day).')
        ->and(di35Errors($import)[4][0])->toContain("Employee code 'NOPE' does not exist.")
        ->and(di35Errors($import)[6])->toContain('Expiry date must come after the date worked.');

    di35Run($import);
    $co = LeaveType::query()->where('code', 'CO')->first();
    $claim = TimeOfficeRequest::query()->where('employee_id', $this->asha->id)->sole();
    expect($claim->type)->toBe('comp_off')->and($claim->status)->toBe('approved')->and($claim->expires_on->toDateString())->toBe('2026-12-31')
        ->and($claim->effects['ledger_entry_id'] ?? null)->not->toBeNull()
        ->and(app(LeaveBalances::class)->balance($this->asha, $co, 2026)->available())->toBe(1.0)
        ->and(app(LeaveBalances::class)->balance($this->ravi, $co, 2026)->available())->toBe(0.5)
        // Blank expiry: the time-office policy's validity (90 days without a policy).
        ->and(TimeOfficeRequest::query()->where('employee_id', $this->ravi->id)->value('expires_on')->toDateString())->toBe('2027-01-23');

    // Lapses on its expiry like any approved claim.
    $this->travelTo('2027-01-01 09:00:00');
    expect(app(CompOffCredits::class)->expire())->toBe(1)->and(app(LeaveBalances::class)->balance($this->asha, $co, 2026)->available())->toBe(0.0);
    $this->travelTo('2026-11-03 10:00:00');

    // Uploaded again: the day already has a claim.
    $again = di35Stage(CompOffImports::TYPE, "Employee code,Date worked\nEMP1,2026-10-31\n");
    expect($again->skip_count)->toBe(1)->and($again->rows()->first()->match['reason'])->toBe('A comp-off claim for this day is already on file.');
});

it('refuses comp-off on a day whose overtime was approved', function () {
    leavePolicy(['CO' => ['days' => 0]]);
    di35Run(di35Stage('overtime_hours', "Employee code,Date,Overtime minutes\nEMP1,2026-11-01,120\n"));

    $import = di35Stage(CompOffImports::TYPE, "Employee code,Date worked\nEMP1,2026-11-01\n");
    expect($import->error_count)->toBe(1)->and(di35Errors($import)[1])->toBe(['Overtime was already approved for this day; record one or the other.']);
});

it('sets HR arrear days of an earlier month, taking the month over from the calculation (decision D35-1)', function () {
    // A correction to a locked September day: the calculation records an arrear of +1 day.
    di35Setting('attendance.allow_corrections_after_lock', true);
    AttendanceRecord::create(['employee_id' => $this->asha->id, 'date' => '2026-09-15', 'status' => 'absent', 'worked_minutes' => 0, 'is_locked' => true, 'processed_at' => now()]);
    di35Run(di35Stage('day_overrides', "Employee code,Date,Status,Reason\nEMP1,2026-09-15,present,Card reader fault\n"));
    $calculated = AttendanceArrear::query()->where('employee_id', $this->asha->id)->where('source', 'calculation')->sole();
    expect((float) $calculated->delta_days)->toBe(1.0)->and($calculated->status)->toBe('pending');

    $csv = "Employee code,Month (YYYY-MM),Arrear days,Reason\nEMP1,2026-09,2,Absence regularised late\nEMP2,2026-11,1,Not over\nEMP2,2026-10,40,Too many\nEMP1,2026-09,1,Twice\n";
    $import = di35Stage(AttendanceArrearImports::TYPE, $csv);
    expect($import->update_count)->toBe(1)->and($import->error_count)->toBe(3)
        ->and(di35Errors($import)[2])->toBe(['Arrear days are for an earlier month; Nov 2026 is not over yet.'])
        ->and(di35Errors($import)[3])->toBe(['Arrear days must be a number of days from -31 to 31.'])
        ->and(di35Errors($import)[4])->toBe(['The same employee and month are in row 1.']);
    di35Run($import);

    // HR's figure replaces the calculated arrear and covers the month.
    $hr = AttendanceArrear::query()->where('employee_id', $this->asha->id)->where('source', 'upload')->sole();
    expect($calculated->refresh()->status)->toBe('void')
        ->and($hr->covers_from->toDateString())->toBe('2026-09-01')->and($hr->date->toDateString())->toBe('2026-09-30')
        ->and((float) $hr->delta_days)->toBe(2.0)->and($hr->status)->toBe('pending')->and($hr->note)->toBe('Set by HR: 2 day(s) for Sep 2026: Absence regularised late');

    // The calculation records no more arrears for the month's days.
    AttendanceRecord::create(['employee_id' => $this->asha->id, 'date' => '2026-09-16', 'status' => 'absent', 'worked_minutes' => 0, 'is_locked' => true, 'processed_at' => now()]);
    di35Run(di35Stage('day_overrides', "Employee code,Date,Status,Reason\nEMP1,2026-09-16,present,Card reader fault\n"));
    expect(AttendanceArrear::query()->where('employee_id', $this->asha->id)->whereDate('date', '2026-09-16')->exists())->toBeFalse();

    // The same figure again changes nothing; "new only" leaves the month on file, "update" replaces the pending figure.
    expect(di35Stage(AttendanceArrearImports::TYPE, "Employee code,Month (YYYY-MM),Arrear days,Reason\nEMP1,2026-09,2,Again\n")->skip_count)->toBe(1);
    $new = di35Stage(AttendanceArrearImports::TYPE, "Employee code,Month (YYYY-MM),Arrear days,Reason\nEMP1,2026-09,3,Recount\nEMP2,2026-09,1,New one\n", 'new');
    expect($new->skip_count)->toBe(1)->and($new->create_count)->toBe(1);
    $update = di35Stage(AttendanceArrearImports::TYPE, "Employee code,Month (YYYY-MM),Arrear days,Reason\nEMP1,2026-09,3,Recount\nEMP2,2026-09,1,New one\n", 'update');
    expect($update->update_count)->toBe(1)->and($update->skip_count)->toBe(1);
    di35Run($update);
    expect((float) $hr->refresh()->delta_days)->toBe(3.0)->and(AttendanceArrear::query()->where('employee_id', $this->ravi->id)->exists())->toBeFalse();

    // Settled by a run: a new figure takes only what is left, the same figure never twice.
    $hr->update(['status' => 'settled', 'settled_at' => now()]);
    $more = di35Stage(AttendanceArrearImports::TYPE, "Employee code,Month (YYYY-MM),Arrear days,Reason\nEMP1,Sep 2026,5,Two more days found\n");
    expect($more->create_count)->toBe(1);
    di35Run($more);
    $left = AttendanceArrear::query()->where('employee_id', $this->asha->id)->where('source', 'upload')->where('status', 'pending')->sole();
    expect((float) $left->delta_days)->toBe(2.0)->and((float) $left->paid_before)->toBe(3.0)->and((float) $left->paid_after)->toBe(5.0);
    expect(di35Stage(AttendanceArrearImports::TYPE, "Employee code,Month (YYYY-MM),Arrear days,Reason\nEMP1,2026-09,5,Again\n")->skip_count)->toBe(1);
    $back = di35Run(di35Stage(AttendanceArrearImports::TYPE, "Employee code,Month (YYYY-MM),Arrear days,Reason\nEMP1,2026-09,3,As paid after all\n"));
    expect(di35Results($back)[1])->toBe('Sep 2026 set to 3 arrear day(s): nothing left to take')->and($left->refresh()->status)->toBe('void');
});

it('refuses arrear days to people without the right and for one\'s own record', function () {
    $this->actingAs(tenantUser($this->tenant, ['employee.import', 'attendance.view']));
    expect(fn () => di35Stage(AttendanceArrearImports::TYPE, "Employee code,Month (YYYY-MM),Arrear days,Reason\nEMP1,2026-09,1,x\n"))->toThrow(RuntimeException::class, 'You may not run this upload.');

    $this->asha->update(['user_id' => $this->hr->id]);
    $this->actingAs($this->hr);
    $import = di35Stage(AttendanceArrearImports::TYPE, "Employee code,Month (YYYY-MM),Arrear days,Reason\nEMP1,2026-09,1,Mine\n");
    expect(di35Errors($import)[1])->toBe(['You cannot set your own arrear days.']);
});

it('refuses every paid-days row while paid-day adjustments are off, naming the switch', function () {
    $import = di35Stage(PaidDayAdjustmentImports::TYPE, "Employee code,Month (YYYY-MM),Paid days,Reason\nEMP1,2026-10,20,Agreed\n");

    expect($import->error_count)->toBe(1)->and(di35Errors($import)[1][0])->toContain('attendance.paid_day_adjustments.enabled')
        ->and(PaidDayAdjustment::query()->count())->toBe(0);
});

it('adjusts paid days through the paid-day adjustments, replacing the month\'s adjustment on update', function () {
    di35Setting('attendance.paid_day_adjustments.enabled', true);
    $csv = "Employee code,Month (YYYY-MM),Mode (set/reduce),Paid days,Present days,Weekly offs,Reason\nEMP1,2026-10,set,20,,,Agreed with the head\n"
        ."EMP2,2026-10,reduce,,,50%,Weekly offs between absences\nEMP1,2026-09,set,20,15,,Both\nEMP2,2026-09,reduce,,,half,Bad share\n";
    $import = di35Stage(PaidDayAdjustmentImports::TYPE, $csv);
    expect($import->create_count)->toBe(2)->and($import->error_count)->toBe(2)
        ->and(di35Errors($import)[3])->toBe(['Give the paid days, or the count of chosen days — not both.'])
        ->and(di35Errors($import)[4])->toBe(['Weekly offs must be full, none or a percentage such as 50%.']);
    di35Run($import);

    $asha = PaidDayAdjustment::query()->where('employee_id', $this->asha->id)->sole();
    $ravi = PaidDayAdjustment::query()->where('employee_id', $this->ravi->id)->sole();
    expect($asha->mode)->toBe('set')->and((float) $asha->value)->toBe(20.0)->and($asha->status)->toBe('active')->and($asha->reason)->toBe('Agreed with the head')
        ->and($asha->start_date->toDateString())->toBe('2026-10-01')->and($asha->end_date->toDateString())->toBe('2026-10-31')
        ->and($ravi->mode)->toBe('reduce')->and($ravi->categories)->toEqual(['weekly_off' => ['how' => 'partial', 'percent' => 50]]);

    // The same adjustment again is skipped; a new figure is an update that replaces it ("new only" leaves it).
    $same = di35Stage(PaidDayAdjustmentImports::TYPE, "Employee code,Month (YYYY-MM),Paid days,Reason\nEMP1,2026-10,20,Again\n");
    expect($same->skip_count)->toBe(1);
    expect(di35Stage(PaidDayAdjustmentImports::TYPE, "Employee code,Month (YYYY-MM),Paid days,Reason\nEMP1,2026-10,22,Recount\n", 'new')->skip_count)->toBe(1);
    $update = di35Stage(PaidDayAdjustmentImports::TYPE, "Employee code,Month (YYYY-MM),Paid days,Reason\nEMP1,2026-10,22,Recount\n", 'update');
    expect($update->update_count)->toBe(1);
    di35Run($update);
    expect($asha->refresh()->status)->toBe('reversed')
        ->and((float) PaidDayAdjustment::query()->where('employee_id', $this->asha->id)->where('status', 'active')->value('value'))->toBe(22.0);
});

it('records previous month overtime on the overtime path, and names the switch for a closed day', function () {
    $csv = "Employee code,Date,Overtime minutes,Reason\nEMP1,2026-10-14,90,Month-end close\nEMP1,2026-11-02,60,This month\nEMP2,2026-10-15,45,Locked day\n";
    // Ravi's 15 October: worked (punches) and locked downstream.
    punch($this->ravi, '2026-10-15 09:00:00', 'in');
    punch($this->ravi, '2026-10-15 18:00:00', 'out');
    app(AttendanceProcessor::class)->process($this->ravi, '2026-10-15')->forceFill(['is_locked' => true])->saveQuietly();
    $import = di35Stage(OvertimeArrearImports::TYPE, $csv);
    expect($import->create_count)->toBe(1)->and($import->error_count)->toBe(2)
        ->and(di35Errors($import)[2])->toBe(['The day is in the current attendance month: use the Override overtime upload.'])
        ->and(di35Errors($import)[3][0])->toContain('attendance.allow_corrections_after_lock');
    di35Run($import);
    expect(TimeOfficeRequest::query()->where('employee_id', $this->asha->id)->where('type', 'overtime')->value('status'))->toBe('approved')
        ->and(AttendanceRecord::query()->where('employee_id', $this->asha->id)->whereDate('date', '2026-10-14')->value('overtime_approved_minutes'))->toBe(90);

    // With corrections after lock on, the closed day is not rewritten: the minutes wait as previous month overtime (D35-2).
    di35Setting('attendance.allow_corrections_after_lock', true);
    $locked = di35Stage(OvertimeArrearImports::TYPE, "Employee code,Date,Overtime hours\nEMP2,2026-10-15,0:45\n");
    expect($locked->create_count)->toBe(1);
    expect(di35Results(di35Run($locked))[1])->toContain('45 minute(s) wait as previous month overtime');
    expect(TimeOfficeRequest::query()->where('employee_id', $this->ravi->id)->where('type', 'overtime')->value('minutes'))->toBe(45)
        ->and(AttendanceRecord::query()->where('employee_id', $this->ravi->id)->whereDate('date', '2026-10-15')->value('overtime_approved_minutes'))->toBe(0)
        ->and(AttendanceArrear::query()->where('employee_id', $this->ravi->id)->sole()->only(['overtime_minutes', 'delta_days', 'status']))->toBe(['overtime_minutes' => 45, 'delta_days' => '0.00', 'status' => 'pending'])
        ->and(app(AttendanceOutput::class)->overtimeArrears($this->ravi, '2026-11-01')['minutes'])->toBe(45)
        ->and(app(AttendanceOutput::class)->overtimeArrears($this->ravi, '2026-10-01')['minutes'])->toBe(0);

    // "Update only": a day not claimed yet is skipped.
    expect(di35Stage(OvertimeArrearImports::TYPE, "Employee code,Date,Overtime minutes\nEMP2,2026-10-16,30\n", 'update')->skip_count)->toBe(1);
});

it('pays previous month overtime of a closed day with the next month\'s overtime, and settles it with that run (decision D35-2)', function () {
    syncComplianceRules();
    $company = payrollCompany();
    $employee = salariedEmployee(600000);
    assignSchedule($employee, WorkSchedule::query()->firstOrFail());
    $ot = SalaryComponent::create(['name' => 'Overtime', 'code' => 'OT', 'type' => 'earning', 'classification' => 'other', 'calculation_method' => 'formula', 'formula' => 'overtime_hours * 250',
        'taxable' => true, 'include_in_gross' => true, 'is_recurring' => true, 'is_proratable' => false, 'sort_order' => 90, 'status' => 'active']);
    approveStructureVersion('STANDARD', '2026-10-01', [$ot->id => 90]);
    punch($employee, '2026-10-15 09:00:00', 'in');
    punch($employee, '2026-10-15 18:00:00', 'out');
    app(AttendanceProcessor::class)->process($employee, '2026-10-15')->forceFill(['is_locked' => true])->saveQuietly();
    di35Setting('attendance.allow_corrections_after_lock', true);

    di35Run(di35Stage(OvertimeArrearImports::TYPE, "Employee code,Date,Overtime minutes,Reason\n{$employee->employee_code},2026-10-15,90,Audit night\n"));
    $arrear = AttendanceArrear::query()->where('employee_id', $employee->id)->sole();

    $period = PayrollPeriod::for($company, 2026, 11);
    $c = app(PayrollCalculator::class)->calculate($employee->fresh(), $period);
    $line = collect($c->lines)->firstWhere('code', 'OT');
    expect($c->amount('OT'))->toBe(375.0)->and($c->inputs['attendance']['overtime_arrear_minutes'])->toBe(90)
        ->and($line['basis']['source'])->toBe(['key' => 'attendance_arrear', 'ids' => [$arrear->id]]);

    // Finalizing the run settles what its overtime line paid.
    app(AttendanceArrearInput::class)->settle((new PayrollRun)->forceFill(['payroll_period_id' => $period->id]), [$employee->id => $line['basis']['source']['ids']]);
    expect($arrear->refresh()->status)->toBe('settled')->and(app(AttendanceOutput::class)->overtimeArrears($employee, '2026-12-01')['minutes'])->toBe(0);

    // Without previous-month overtime the month pays only its own.
    expect(app(PayrollCalculator::class)->calculate($employee->fresh(), $period)->amount('OT'))->toBe(0.0);
});

it('validates the sample file of every time-office process it builds and of the presets', function () {
    leavePolicy(['CO' => ['days' => 0], 'EL' => ['days' => 0], 'CL' => ['days' => 0]]);
    di35Setting('attendance.paid_day_adjustments.enabled', true);
    foreach (['time_office.comp_off', 'time_office.arrear_days', 'time_office.paid_days', 'time_office.prev_month_overtime', 'time_office.overtime',
        'time_office.attendance_status', 'time_office.working_hours', 'time_office.opening_balance', 'time_office.rh_balance', 'time_office.leave_balance'] as $process) {
        $import = di35StageSample($process);
        expect($import->error_count)->toBe(0, $process.': '.json_encode(di35Errors($import)))
            ->and($import->row_count)->toBeGreaterThanOrEqual(2, $process);
    }
});

it('loads restricted holiday balances only into the restricted-holiday leave type (decision D35-7)', function () {
    leavePolicy(['RH' => ['days' => 0], 'EL' => ['days' => 0]]);
    $rh = ImportCatalogue::find('time_office.rh_balance');
    $options = ['process' => $rh['key'], 'defaults' => $rh['defaults']];
    $import = di35Stage('leave_balances', "Employee code,Days,Leave year\nEMP1,2,2026\n", 'both', $options);
    expect($import->create_count)->toBe(1);
    di35Run($import);
    expect(app(LeaveBalances::class)->balance($this->asha, LeaveType::query()->where('code', 'RH')->first(), 2026)->available())->toBe(2.0);

    $wrong = di35Stage('leave_balances', "Employee code,Leave code,Days,Leave year\nEMP2,EL,3,2026\n", 'both', $options);
    expect(di35Errors($wrong)[1])->toBe(["Leave code 'EL' is not a restricted / optional holiday leave type."])
        // The general leave balance process takes any leave type, as before.
        ->and(di35Stage('leave_balances', "Employee code,Leave code,Days,Leave year\nEMP2,EL,3,2026\n")->create_count)->toBe(1);
});

it('accepts a working-hours row without a status and an attendance-status row without hours', function () {
    $hours = ImportCatalogue::find('time_office.working_hours');
    $import = di35Stage('day_overrides', "Employee code,Date,Worked minutes,Reason\nEMP1,2026-11-02,420,Client visit\n", 'both', ['process' => $hours['key']]);
    expect($import->create_count)->toBe(1);
    di35Run($import);
    expect(AttendanceRecord::query()->where('employee_id', $this->asha->id)->whereDate('date', '2026-11-02')->value('worked_minutes'))->toBe(420);

    $status = di35Stage('day_overrides', "Employee code,Date,Status,Reason\nEMP2,2026-11-02,on duty,Client site all day\n");
    expect($status->create_count)->toBe(1);
});
