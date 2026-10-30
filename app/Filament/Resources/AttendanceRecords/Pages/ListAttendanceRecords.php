<?php

namespace App\Filament\Resources\AttendanceRecords\Pages;

use App\Domain\Attendance\Jobs\ProcessAttendanceRange;
use App\Domain\Attendance\Models\Shift;
use App\Domain\Attendance\Services\AttendanceProcessor;
use App\Domain\Attendance\Services\PunchIngestion;
use App\Domain\Employment\Models\Employee;
use App\Filament\Resources\AttendanceRecords\AttendanceRecordResource;
use App\Filament\Resources\Employees\Pages\CreateEmployee;
use App\Filament\Support\Pages\Concerns\HasRegisterTools;
use App\Filament\Support\Pages\PeopleListRecords;
use App\Filament\Support\TimeOfficeRegisters;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Carbon;

class ListAttendanceRecords extends PeopleListRecords
{
    use HasRegisterTools;

    protected static string $resource = AttendanceRecordResource::class;

    public static function registerKey(): string
    {
        return 'attendance_records';
    }

    /** HROne parity TX-1: views of the attendance register (the day results HR works through most). */
    public static function presetViews(): array
    {
        return [
            'absent' => ['label' => 'Absent days', 'filters' => ['status' => ['values' => ['absent']]]],
            'half_day' => ['label' => 'Half days', 'filters' => ['status' => ['values' => ['half_day']]]],
            'incomplete' => ['label' => 'Missed punches (incomplete days)', 'filters' => ['status' => ['values' => ['incomplete']]]],
            'overtime_pending' => ['label' => 'Overtime waiting for approval', 'filters' => ['overtime_status' => ['value' => 'pending']]],
            'away' => ['label' => 'On duty, field duty and work from home', 'filters' => ['status' => ['values' => ['on_duty', 'field_duty', 'wfh']]]],
        ];
    }

    public static function registerReports(): array
    {
        return ['attendance_today', 'absentees_today', 'late_coming_this_month', 'early_leaving_this_month', 'missed_punches_this_month', 'overtime_this_month', 'wfh_on_duty_this_month', 'monthly_summary_this_month'];
    }

    /** @return list<array{field: string, operator: string, value: string|null}> the filters as conditions of the attendance dataset */
    public function registerReportFilters(): array
    {
        return TimeOfficeRegisters::reportFilters((array) ($this->tableFilters ?? []), [
            'status' => ['status', null],
            'shift_id' => ['shift', fn (string $id) => (string) (Shift::query()->whereKey((int) $id)->value('name') ?? '')],
            'overtime_status' => ['overtime_status', null],
        ], ['date' => 'date']);
    }

    protected function getHeaderActions(): array
    {
        return [
            // HROne parity TX-1: views, report and the uploads for the HR side; an employee's own list is unchanged.
            ...(TimeOfficeRegisters::hrSide('attendance.view') ? [...$this->registerToolActions(), TimeOfficeRegisters::uploads(['attendance_sheet', 'day_overrides', 'punches'])] : []),
            // HROne parity TX-2: attendance.punch / attendance.process (attendance.manage also grants both).
            Action::make('manualPunch')->label('Manual punch')->icon(Heroicon::OutlinedFingerPrint)
                ->authorize(fn () => auth()->user()->can('attendance.punch'))
                ->schema([
                    Select::make('employee_id')->label('Employee')->options(fn () => CreateEmployee::managerOptions())->searchable()->required(),
                    DateTimePicker::make('punched_at')->seconds(false)->native(false)->required()->default(now()),
                    Select::make('direction')->options(['auto' => 'Auto', 'in' => 'In', 'out' => 'Out'])->default('auto'),
                    Textarea::make('note')->required()->maxLength(255)->helperText('Why this punch is being recorded by hand.'),
                ])
                ->action(function (array $data) {
                    $employee = Employee::query()->findOrFail($data['employee_id']);
                    $punch = app(PunchIngestion::class)->record($employee, $data['punched_at'], $data['direction'], 'manual', null, null, [], $data['note']);
                    app(AttendanceProcessor::class)->process($employee, $data['punched_at']);
                    Notification::make()->success()->title($punch ? 'Punch recorded and day reprocessed' : 'Duplicate punch ignored')->send();
                }),
            Action::make('process')->label('Process day')->icon(Heroicon::OutlinedArrowPath)
                ->authorize(fn () => auth()->user()->can('attendance.process'))
                ->schema([DatePicker::make('date')->native(false)->required()->default(now()->subDay())])
                ->action(function (array $data) {
                    $count = app(AttendanceProcessor::class)->processAll($data['date']);
                    Notification::make()->success()->title("{$count} employee(s) processed for {$data['date']}")->send();
                }),
            // HROne parity TO-23: recalculate a range of days, for everyone or chosen employees, in the background.
            Action::make('processRange')->label('Process dates')->icon(Heroicon::OutlinedCalendarDays)->color('gray')
                ->authorize(fn () => auth()->user()->can('attendance.process'))
                ->schema([
                    DatePicker::make('from')->native(false)->required()->maxDate(today()),
                    DatePicker::make('to')->native(false)->required()->afterOrEqual('from')->maxDate(today()),
                    Select::make('employee_ids')->label('Employees')->multiple()->options(fn () => CreateEmployee::managerOptions())->searchable()->placeholder('Everyone employed'),
                ])
                ->action(function (array $data) {
                    if (Carbon::parse($data['from'])->diffInDays(Carbon::parse($data['to'])) > 62) {
                        Notification::make()->danger()->title('Process at most two months at a time')->send();

                        return;
                    }
                    $ids = $data['employee_ids'] ?: Employee::query()->employed()->pluck('id')->all();
                    foreach ($ids as $id) {
                        ProcessAttendanceRange::dispatch((int) $id, Carbon::parse($data['from'])->toDateString(), Carbon::parse($data['to'])->toDateString());
                    }
                    Notification::make()->success()->title(count($ids).' employee(s) queued for '.Carbon::parse($data['from'])->format('d M').' – '.Carbon::parse($data['to'])->format('d M Y'))->send();
                }),
        ];
    }
}
