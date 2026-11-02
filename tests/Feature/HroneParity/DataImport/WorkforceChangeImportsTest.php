<?php

use App\Domain\Attendance\Models\WorkScheduleAssignment;
use App\Domain\Audit\Models\AuditEvent;
use App\Domain\Employment\Actions\ChangeEmployeeCodeAction;
use App\Domain\Employment\Exceptions\EmployeeCodeTakenException;
use App\Domain\Employment\Exceptions\ProfileChangeRefused;
use App\Domain\Employment\Imports\EmployeeCodeImports;
use App\Domain\Employment\Imports\EmployeeSettingImports;
use App\Domain\Employment\Imports\ImportTypes;
use App\Domain\Employment\Imports\PositionChangeImports;
use App\Domain\Employment\Models\EmployeePosition;
use App\Domain\Employment\Models\ReportingRelationship;
use App\Domain\Experience\Imports\ShortcutImports;
use App\Domain\Experience\Services\ExperiencePreferences;
use App\Domain\Lifecycle\Models\EmployeeTimelineEntry;
use App\Domain\Organisation\Models\Company;
use App\Domain\Organisation\Models\Department;
use App\Domain\Organisation\Models\Designation;
use App\Domain\Payroll\Models\EmployeePaymentMode;
use App\Domain\Platform\Services\SettingsRepository;
use App\Filament\Resources\Companies\CompanyResource;
use App\Filament\Resources\Employees\Pages\ViewEmployee;
use App\Filament\Resources\PayrollRuns\PayrollRunResource;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

require_once __DIR__.'/Di2Helpers.php';
require_once __DIR__.'/../../Attendance/AttendanceTestHelpers.php';

/*
| Data import DI-2 (Workforce): dated job changes (Employee (advance)), employee codes, employee settings and page
| shortcuts — changes of employees on file, each through the action or service of its own screen.
*/

beforeEach(function () {
    $this->travelTo('2026-10-15 10:00:00');
    Storage::fake('local');
    config(['peopleos.documents.disk' => 'local']);
    $this->tenant = provisionTenant();
    actAsTenant($this->tenant);
    $this->hr = tenantUser($this->tenant, ['*']);
    $this->actingAs($this->hr);
    Company::factory()->create(['code' => 'ACME']);
    $this->ops = Department::factory()->create(['code' => 'OPS', 'name' => 'Operations']);
    $this->asha = di2Hire('EMP1', 'Asha', ['department_id' => $this->ops->id], tenantUser($this->tenant, ['company.view'])->id);
    $this->ravi = di2Hire('EMP2', 'Ravi', ['department_id' => $this->ops->id]);
    $this->boss = di2Hire('EMP3', 'Bina');
});

it('opens dated position and manager changes through the Employee 360 actions', function () {
    $fin = Department::factory()->create(['code' => 'FIN', 'name' => 'Finance']);
    $lead = Designation::factory()->create(['code' => 'LEAD', 'name' => 'Team lead']);
    $csv = "Employee code,Effective date,Change type,Department code,Designation code,Manager employee code,Reason\n"
        ."EMP1,2026-11-01,transfer,fin,,EMP3,Reorganisation\n"
        ."EMP2,2026-11-01,Promotion,,LEAD,,\n"
        ."EMP1,2026-10-20,,OPS,,,\n"
        ."EMP3,2024-06-01,,FIN,,,\n"
        ."EMP3,2026-11-01,,NOPE,,,\n"
        ."EMP3,2026-11-02,hire,FIN,,,\n"
        ."EMP3,2026-11-03,,,,,\n"
        ."EMP3,2026-11-04,,,,EMP3,\n";
    $import = di2Stage(PositionChangeImports::TYPE, $csv);
    expect(di2Actions($import))->toBe(['update', 'update', 'error', 'error', 'error', 'error', 'error', 'error'])
        ->and(di2Errors($import, 3))->toContain('put the rows of one employee in date order')
        ->and(di2Errors($import, 4))->toContain('A position already starts on 2025-01-01')
        ->and(di2Errors($import, 5))->toContain("Department code 'NOPE' does not exist")
        ->and(di2Errors($import, 6))->toContain('Change type must be one of')
        ->and(di2Errors($import, 7))->toContain('Give at least one new value')
        ->and(di2Errors($import, 8))->toContain('cannot report to themselves');

    di2Run($import);
    $after = $this->asha->positions()->effectiveOn('2026-11-02')->first();
    expect($after->department_id)->toBe($fin->id)->and($after->change_type)->toBe('transfer')->and($after->reason)->toBe('Reorganisation')
        ->and($this->asha->positions()->effectiveOn('2026-10-31')->value('department_id'))->toBe($this->ops->id)
        ->and(ReportingRelationship::query()->where('employee_id', $this->asha->id)->where('type', 'line')->effectiveOn('2026-11-02')->value('manager_id'))->toBe($this->boss->id)
        ->and($this->ravi->positions()->effectiveOn('2026-11-02')->first()->only(['designation_id', 'change_type', 'department_id']))
        ->toBe(['designation_id' => $lead->id, 'change_type' => 'promotion', 'department_id' => $this->ops->id])
        ->and(di2Timeline($this->asha))->toContain('Transfer')->toContain('Line manager assigned')
        ->and(AuditEvent::query()->where('entity_type', $this->ravi->getMorphClass())->where('entity_id', (string) $this->ravi->id)->where('action', 'PROMOTED')->exists())->toBeTrue();

    // Already so on that date: skipped. Without employee.position nobody may stage the upload.
    expect(di2Actions(di2Stage(PositionChangeImports::TYPE, "Employee code,Effective date,Department code,Manager employee code\nEMP1,2026-12-01,FIN,EMP3\n")))->toBe(['skip'])
        ->and(fn () => di2Stage(PositionChangeImports::TYPE, $csv, 'both', [], tenantUser($this->tenant, ['employee.import', 'employee.update'])))
        ->toThrow(RuntimeException::class, 'You may not run this upload.')
        ->and(EmployeePosition::query()->where('employee_id', $this->asha->id)->count())->toBe(2);
});

it('changes employee codes through the code action: free in the whole tenant, audited and on the timeline', function () {
    di2Hire('EMP4', 'Dev');
    di2Hire('EMP5', 'Esha');
    $csv = "Employee code,New employee code,Reason\n"
        ."EMP1,MKE-001,New series\n"
        ."EMP2,emp3,\n"
        ."EMP3,MKE 3,\n"
        ."NOPE,MKE-009,\n"
        ."EMP4,mke-001,\n"
        ."emp5,EMP5,\n"
        ."EMP3,R-3,\n";
    $import = di2Stage(EmployeeCodeImports::TYPE, $csv);
    expect(di2Actions($import))->toBe(['update', 'error', 'error', 'error', 'error', 'skip', 'error'])
        ->and(di2Errors($import, 2))->toContain('Employee code emp3 is already in use by another employee')
        ->and(di2Errors($import, 3))->toContain('new employee code')
        ->and(di2Errors($import, 4))->toContain("Employee code 'NOPE' does not exist")
        ->and(di2Errors($import, 5))->toContain('The new code mke-001 is also in row 1')
        ->and(di2Errors($import, 6))->toBe('The employee already has this code.')
        ->and(di2Errors($import, 7))->toContain('This employee is also in row 3');

    di2Run($import);
    expect($this->asha->fresh()->employee_code)->toBe('MKE-001')
        ->and(di2Timeline($this->asha))->toContain('Employee code changed')
        ->and(AuditEvent::query()->where('entity_type', $this->asha->getMorphClass())->where('entity_id', (string) $this->asha->id)->where('action', 'UPDATE')->latest('id')->value('reason'))->toBe('New series');

    // A case-only change is allowed; the action itself refuses a held code and a person without the right.
    di2Run(di2Stage(EmployeeCodeImports::TYPE, "Employee code,New employee code\nEMP3,emp3\n"));
    expect($this->boss->fresh()->employee_code)->toBe('emp3')
        ->and(fn () => app(ChangeEmployeeCodeAction::class)->handle($this->ravi, 'MKE-001', $this->hr))->toThrow(EmployeeCodeTakenException::class)
        ->and(fn () => app(ChangeEmployeeCodeAction::class)->handle($this->ravi, 'R-2', tenantUser($this->tenant, ['employee.view'])))->toThrow(ProfileChangeRefused::class, 'This needs employee.update.')
        ->and(ImportTypes::modes(EmployeeCodeImports::TYPE))->toBeNull();
});

it('keeps the old code as the device user id so attendance devices still find the employee', function () {
    $this->ravi->update(['device_user_id' => '7001']);
    $action = app(ChangeEmployeeCodeAction::class);

    $action->handle($this->asha, 'MKE-001', $this->hr, 'New series');
    $action->handle($this->ravi, 'MKE-002', $this->hr, 'New series');

    expect($this->asha->fresh()->device_user_id)->toBe('EMP1')
        ->and(EmployeeTimelineEntry::query()->where('employee_id', $this->asha->id)->where('category', 'employee_code')->value('description'))->toContain('EMP1 is kept as the device user id')
        ->and($this->ravi->fresh()->device_user_id)->toBe('7001');

    // An id another employee already uses is never taken over.
    $this->boss->update(['device_user_id' => 'EMP3-X']);
    $esha = di2Hire('EMP3-X', 'Esha');
    $action->handle($esha, 'MKE-003', $this->hr);
    expect($esha->fresh()->device_user_id)->toBeNull();
});

it('sets per-employee settings through the screens\' own write paths, each with its own right', function () {
    app(SettingsRepository::class)->set('payroll.pay_modes', ['online_transfer', 'cash'], 'test');
    $schedule = weeklySchedule(generalShift());
    $csv = "Employee code,Device user id,Show birthday to colleagues,Work schedule,Schedule from,Salary pay mode,Pay mode from,Time zone\n"
        ."EMP1,1045,no,mf,2026-11-01,Cash,2026-11-01,europe/london\n"
        ."EMP2,1045,,,,,,\n"
        ."EMP3,,,,,cheque,,Asia/Kolkata\n"
        ."EMP3,,,,,,,\n";
    $import = di2Stage(EmployeeSettingImports::TYPE, $csv);
    expect(di2Actions($import))->toBe(['update', 'error', 'error', 'error'])
        ->and(di2Errors($import, 2))->toContain("Device user id '1045' is also in row 1")
        ->and(di2Errors($import, 3))->toContain('Salary pay mode must be one of the pay modes in use')->toContain('has no sign-in')
        ->and(di2Errors($import, 4))->toContain('Give at least one setting');

    di2Run($import);
    $asha = $this->asha->fresh(['person', 'user']);
    expect($asha->device_user_id)->toBe('1045')->and($asha->person->birthday_shared)->toBeFalse()->and($asha->user->timezone)->toBe('Europe/London')
        ->and(WorkScheduleAssignment::query()->where('employee_id', $asha->id)->sole()->only(['work_schedule_id']))->toBe(['work_schedule_id' => $schedule->id])
        ->and(EmployeePaymentMode::query()->where('employee_id', $asha->id)->sole()->salary_mode)->toBe('cash');

    // The same settings again are skipped; pay modes need payroll.manage, schedules attendance.manage.
    expect(di2Actions(di2Stage(EmployeeSettingImports::TYPE, $csv)))->toBe(['skip', 'error', 'error', 'error']);
    $clerk = tenantUser($this->tenant, ['employee.import', 'employee.update', 'employee.view']);
    $this->actingAs($clerk);
    $refused = di2Stage(EmployeeSettingImports::TYPE, "Employee code,Device user id,Salary pay mode,Work schedule\nEMP2,2001,,\nEMP3,,cash,\nEMP3,,,MF\n", 'both', [], $clerk);
    expect(di2Actions($refused))->toBe(['update', 'error', 'error'])->and(di2Errors($refused, 2))->toContain('This needs payroll.manage')
        ->and(di2Errors($refused, 3))->toContain('This employee is also in row 2');
});

it('keeps the Employee 360 schedule assignment on the shared write path', function () {
    $first = weeklySchedule(generalShift());
    $second = weeklySchedule(generalShift(['code' => 'G2']), ['code' => 'MF2', 'name' => 'Rotation']);
    Livewire::test(ViewEmployee::class, ['record' => $this->asha->getRouteKey()])
        ->callAction('assignSchedule', data: ['work_schedule_id' => $first->id, 'effective_from' => '2026-11-01', 'audit_reason' => 'Roster'])->assertNotified('Schedule assigned')
        ->callAction('assignSchedule', data: ['work_schedule_id' => $second->id, 'effective_from' => '2026-12-01', 'audit_reason' => 'Rotation'])->assertNotified('Schedule assigned');

    expect(WorkScheduleAssignment::query()->where('employee_id', $this->asha->id)->orderBy('effective_from')->get()
        ->map(fn ($a) => [$a->work_schedule_id, $a->effective_to?->toDateString(), $a->reason])->all())
        ->toBe([[$first->id, '2026-11-30', 'Roster'], [$second->id, null, 'Rotation']]);
});

it('pins and unpins page shortcuts for an employee\'s sign-in, only pages that person may open', function () {
    $companies = CompanyResource::getNavigationLabel();
    $csv = "Employee code,Page,Remove\n"
        .'EMP1,'.mb_strtolower($companies).",\n"
        .'EMP1,'.PayrollRunResource::getNavigationLabel().",\n"
        ."EMP2,{$companies},\n"
        ."EMP1,{$companies},maybe\n";
    $import = di2Stage(ShortcutImports::TYPE, $csv);
    expect(di2Actions($import))->toBe(['create', 'error', 'error', 'error'])
        ->and(di2Errors($import, 2))->toContain('is not a page EMP1 may open')
        ->and(di2Errors($import, 3))->toContain('EMP2 has no sign-in')
        ->and(di2Errors($import, 4))->toContain('Remove must be yes or no');

    di2Run($import);
    $user = $this->asha->user;
    expect(app(ExperiencePreferences::class)->for($user)['favourite_modules'])->toBe([CompanyResource::class])
        ->and(di2Actions(di2Stage(ShortcutImports::TYPE, "Employee code,Page\nEMP1,{$companies}\n")))->toBe(['skip']);

    di2Run(di2Stage(ShortcutImports::TYPE, "Employee code,Page,Remove\nEMP1,CompanyResource,yes\n"));
    expect(app(ExperiencePreferences::class)->for($user)['favourite_modules'])->toBe([])
        ->and(ImportTypes::modes(ShortcutImports::TYPE))->toBeNull()
        ->and(auth()->id())->toBe($this->hr->id);
});
