<?php

namespace App\Filament\Pages;

use App\Domain\Attendance\Models\AttendanceArrear;
use App\Domain\Leave\Models\LeaveEncashment;
use App\Filament\Resources\AttendanceArrears\AttendanceArrearResource;
use App\Filament\Resources\AttendanceDevices\AttendanceDeviceResource;
use App\Filament\Resources\AttendanceOverrides\AttendanceOverrideResource;
use App\Filament\Resources\AttendancePenalties\AttendancePenaltyResource;
use App\Filament\Resources\AttendancePunches\AttendancePunchResource;
use App\Filament\Resources\AttendanceRecords\AttendanceRecordResource;
use App\Filament\Resources\AttendanceRegularisations\AttendanceRegularisationResource;
use App\Filament\Resources\AttendanceSheetImports\AttendanceSheetImportResource;
use App\Filament\Resources\BulkRuns\BulkRunResource;
use App\Filament\Resources\EmployeeDevices\EmployeeDeviceResource;
use App\Filament\Resources\GeoFences\GeoFenceResource;
use App\Filament\Resources\HolidayCalendars\HolidayCalendarResource;
use App\Filament\Resources\LeaveBalances\LeaveBalanceResource;
use App\Filament\Resources\LeaveDonations\LeaveDonationResource;
use App\Filament\Resources\LeaveEncashments\LeaveEncashmentResource;
use App\Filament\Resources\LeaveRequests\LeaveRequestResource;
use App\Filament\Resources\LeaveTransactions\LeaveTransactionResource;
use App\Filament\Resources\LeaveTypes\LeaveTypeResource;
use App\Filament\Resources\LocationRequests\LocationRequestResource;
use App\Filament\Resources\Policies\PolicyResource;
use App\Filament\Resources\PunchImports\PunchImportResource;
use App\Filament\Resources\Reports\ReportResource;
use App\Filament\Resources\ShiftOverrides\ShiftOverrideResource;
use App\Filament\Resources\Shifts\ShiftResource;
use App\Filament\Resources\TimeOfficeImports\TimeOfficeImportResource;
use App\Filament\Resources\TimeOfficeRequests\TimeOfficeRequestResource;
use App\Filament\Resources\WorkSchedules\WorkScheduleResource;
use App\Filament\Support\Pages\OperationsDeskPage;
use BackedEnum;
use Closure;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

/**
 * HROne parity TX-1 (HR Desk → Time Office panel, matrix 24 §24.1 #1): the time-office and leave registers in HROne's
 * order — Shift · Checkins · Overtime & comp off · Attendance · Leave · Holiday · Reports and uploads · Setup. Every entry
 * is an existing register (most opened on a preset view: HROne's separate request and cancellation registers are views
 * of the one time-office request list); nothing here is a new register. Opens for people who run the time office
 * (attendance.view or leave.view plus an operations right) and lists only what each of them can open; counts come from
 * each register's own (scoped) query.
 */
class TimeOfficeDesk extends OperationsDeskPage
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleGroup;

    protected static string|UnitEnum|null $navigationGroup = 'Attendance';

    protected static ?string $navigationLabel = 'Time office desk';

    protected static ?string $title = 'Time office desk';

    protected static ?string $slug = 'time-office-desk';

    protected static ?int $navigationSort = 1;

    /** Any one of these, with attendance.view or leave.view, makes someone a time-office operator for the desk. */
    public const OPERATOR_PERMISSIONS = ['attendance.manage', 'attendance.roster', 'attendance.override', 'attendance.punch', 'attendance.process', 'attendance.arrears', 'leave.manage', 'leave.adjust', 'leave.carry_forward'];

    public static function canAccess(): bool
    {
        $user = auth()->user();
        if ($user === null || (! $user->hasPermission('attendance.view') && ! $user->hasPermission('leave.view'))) {
            return false;
        }
        foreach (self::OPERATOR_PERMISSIONS as $permission) {
            if ($user->hasPermission($permission)) {
                return static::anyEntryOpens();
            }
        }

        return false;
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);
    }

    public function getSubheading(): ?string
    {
        return 'Every time-office and leave register in one place, in the order HR works through them. Open a register on its view, filter by employee, organisation or dates, save the views you use and report on what you see.';
    }

    protected function registers(): array
    {
        return ['time_office_requests', 'regularisations', 'attendance_records', 'leave_requests', 'leave_balances', 'leave_encashments', 'location_requests', 'shift_roster', 'day_overrides', 'attendance_punches', 'attendance_penalties', 'attendance_arrears'];
    }

    protected function sections(): array
    {
        $requests = fn (array $types, array $statuses = ['pending']) => fn () => TimeOfficeRequestResource::getEloquentQuery()->whereIn('type', $types)->whereIn('status', $statuses)->count();
        $leave = fn (Closure $where) => fn () => $where(LeaveRequestResource::getEloquentQuery())->count();

        return [
            ['label' => 'Shift', 'items' => [
                ['label' => 'Shift rosters', 'target' => ShiftOverrideResource::class, 'description' => 'Days rostered on a shift or a weekly off'],
                ['label' => 'Shift requests', 'target' => TimeOfficeRequestResource::class, 'params' => ['view' => 'shift_requests'], 'description' => 'Shift and week-off changes', 'count' => $requests(['shift_change', 'week_off_change'])],
            ]],
            ['label' => 'Checkins', 'items' => [
                ['label' => 'Check-ins and punches', 'target' => AttendancePunchResource::class, 'description' => 'Every punch with its source and device'],
                ['label' => 'Attendance regularization requests', 'target' => AttendanceRegularisationResource::class, 'params' => ['view' => 'pending'], 'count' => fn () => AttendanceRegularisationResource::getEloquentQuery()->where('status', 'pending')->count()],
                ['label' => 'On duty requests', 'target' => TimeOfficeRequestResource::class, 'params' => ['view' => 'on_duty'], 'count' => $requests(['on_duty'])],
                ['label' => 'On duty cancellations', 'target' => TimeOfficeRequestResource::class, 'params' => ['view' => 'on_duty_cancellations'], 'count' => $requests(['on_duty'], ['cancel_requested'])],
                ['label' => 'Location requests', 'target' => LocationRequestResource::class, 'params' => ['view' => 'pending'], 'count' => fn () => LocationRequestResource::getEloquentQuery()->where('status', 'pending')->count()],
                ['label' => 'Work locations (geo based)', 'target' => GeoFenceResource::class],
                ['label' => 'Check-in devices', 'target' => EmployeeDeviceResource::class],
            ]],
            ['label' => 'Overtime & comp off', 'items' => [
                ['label' => 'Overtime requests', 'target' => TimeOfficeRequestResource::class, 'params' => ['view' => 'overtime'], 'description' => 'Overtime claimed by employees', 'count' => $requests(['overtime'])],
                ['label' => 'Overtime waiting for approval', 'target' => AttendanceRecordResource::class, 'params' => ['view' => 'overtime_pending'], 'description' => 'Overtime worked on attendance days',
                    'count' => fn () => AttendanceRecordResource::getEloquentQuery()->where('overtime_status', 'pending')->where('is_locked', false)->count()],
                ['label' => 'Comp off requests', 'target' => TimeOfficeRequestResource::class, 'params' => ['view' => 'comp_off'], 'count' => $requests(['comp_off'])],
            ]],
            ['label' => 'Attendance', 'items' => [
                ['label' => 'Attendance', 'target' => AttendanceRecordResource::class, 'description' => 'Each employee\'s day: shift, status, in, out, worked'],
                ['label' => 'Attendance calendar', 'target' => AttendanceCalendar::class],
                ['label' => 'Exception centre', 'target' => AttendanceExceptionCentre::class, 'description' => 'Missed punches, late marks, absences and overtime to look at'],
                ['label' => 'Day overrides', 'target' => AttendanceOverrideResource::class, 'description' => 'A day\'s status or hours changed by HR'],
                ['label' => 'Late-mark penalties', 'target' => AttendancePenaltyResource::class],
                ['label' => 'Attendance arrears', 'target' => AttendanceArrearResource::class, 'count' => fn () => AttendanceArrear::query()->where('status', 'pending')->count()],
            ]],
            ['label' => 'Leave', 'items' => [
                ['label' => 'Leave requests', 'target' => LeaveRequestResource::class, 'params' => ['view' => 'pending'], 'count' => $leave(fn ($q) => $q->where('status', 'pending'))],
                ['label' => 'Leave cancellations', 'target' => LeaveRequestResource::class, 'params' => ['view' => 'leave_cancellations'], 'count' => $leave(fn ($q) => $q->where('status', 'cancel_requested'))],
                ['label' => 'Leaves balance', 'target' => LeaveBalanceResource::class],
                ['label' => 'Leave transactions', 'target' => LeaveTransactionResource::class],
                ['label' => 'Leave encashment requests', 'target' => LeaveEncashmentResource::class, 'params' => ['view' => 'pending'], 'count' => fn () => LeaveEncashment::query()->where('status', 'pending')->count()],
                ['label' => 'Leave encashment cancellations', 'target' => LeaveEncashmentResource::class, 'params' => ['view' => 'cancellations'], 'description' => 'Cancelled by HR (the days went back to the balance)',
                    'count' => fn () => LeaveEncashment::query()->where('status', 'cancelled')->count()],
                ['label' => 'Short leave requests', 'target' => TimeOfficeRequestResource::class, 'params' => ['view' => 'short_leave'], 'count' => $requests(['short_leave'])],
                ['label' => 'Short leave cancellations', 'target' => TimeOfficeRequestResource::class, 'params' => ['view' => 'short_leave_cancellations'], 'count' => $requests(['short_leave'], ['cancel_requested'])],
                ['label' => 'Leave donations', 'target' => LeaveDonationResource::class],
                ['label' => 'Leave calendar', 'target' => LeaveCalendar::class],
            ]],
            ['label' => 'Holiday', 'items' => [
                ['label' => 'Holiday calendars', 'target' => HolidayCalendarResource::class],
                ['label' => 'Restricted holidays', 'target' => TimeOfficeRequestResource::class, 'params' => ['view' => 'restricted_holidays'], 'count' => $requests(['restricted_holiday'])],
                ['label' => 'Restricted holiday cancellations', 'target' => TimeOfficeRequestResource::class, 'params' => ['view' => 'restricted_holiday_cancellations'], 'count' => $requests(['restricted_holiday'], ['cancel_requested'])],
            ]],
            ['label' => 'Reports and uploads', 'items' => [
                ['label' => 'Report library', 'target' => ReportLibraryPage::class, 'description' => 'Ready-made attendance and leave reports'],
                ['label' => 'My reports', 'target' => ReportResource::class],
                ['label' => 'Time office uploads', 'target' => TimeOfficeImportResource::class, 'description' => 'Leave balances and history, shift roster, geo locations, overtime, day overrides'],
                ['label' => 'Monthly attendance uploads', 'target' => AttendanceSheetImportResource::class],
                ['label' => 'Punch uploads', 'target' => PunchImportResource::class],
                ['label' => 'Recent uploads', 'target' => RecentUploads::class],
                ['label' => 'Bulk runs', 'target' => BulkRunResource::class, 'description' => 'Each bulk action you ran and every record\'s outcome'],
            ]],
            ['label' => 'Time office setup', 'items' => [
                ['label' => 'Shifts', 'target' => ShiftResource::class],
                ['label' => 'Work schedules', 'target' => WorkScheduleResource::class],
                ['label' => 'Leave types', 'target' => LeaveTypeResource::class],
                ['label' => 'Policies (attendance, time office, check-in, leave)', 'target' => PolicyResource::class],
                ['label' => 'Attendance devices', 'target' => AttendanceDeviceResource::class],
            ]],
        ];
    }
}
