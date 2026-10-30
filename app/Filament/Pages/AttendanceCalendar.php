<?php

namespace App\Filament\Pages;

use App\Domain\Attendance\Models\AttendanceRecord;
use App\Domain\Attendance\Models\Holiday;
use App\Domain\Attendance\Models\TimeOfficeRequest;
use App\Domain\Attendance\Services\AttendanceOutput;
use App\Domain\Attendance\Services\HolidayResolver;
use App\Domain\Employment\Models\Employee;
use App\Domain\Expense\Services\TravelCalendar;
use App\Filament\Resources\Employees\Pages\CreateEmployee;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Carbon;
use Livewire\Attributes\Url;
use UnitEnum;

/**
 * HROne parity TO-18 / TO-09 / TO-19 / TO-20: an employee's month at a glance — a day grid with the status and
 * times, the month's totals (paid days and penalties from the attendance output), the holidays of their calendar
 * (optional ones and those they chose) and a printable attendance slip. Employees see their own month; people
 * with attendance.view may pick anyone in their access scope. HROne setup parity EX-F: with
 * expense.travel.on_attendance_calendar on, the days of approved trips carry a read-only travel mark.
 */
class AttendanceCalendar extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCalendar;

    protected static string|UnitEnum|null $navigationGroup = 'Attendance';

    protected static ?string $navigationLabel = 'Attendance calendar';

    protected static ?string $title = 'Attendance calendar';

    protected static ?string $slug = 'attendance-calendar';

    protected static ?int $navigationSort = 5;

    protected string $view = 'filament.pages.attendance-calendar';

    #[Url]
    public string $month = '';

    #[Url]
    public ?int $employee = null;

    public const COLOURS = [
        'present' => '#dcfce7', 'half_day' => '#fef9c3', 'absent' => '#fee2e2', 'incomplete' => '#ffedd5', 'weekly_off' => '#f1f5f9', 'holiday' => '#e0e7ff',
        'leave' => '#dbeafe', 'unpaid_leave' => '#fecaca', 'wfh' => '#ccfbf1', 'on_duty' => '#cffafe', 'field_duty' => '#cffafe',
    ];

    public const CODES = [
        'present' => 'P', 'half_day' => 'HD', 'absent' => 'A', 'incomplete' => 'MP', 'weekly_off' => 'WO', 'holiday' => 'HO', 'leave' => 'L',
        'unpaid_leave' => 'LWP', 'wfh' => 'WFH', 'on_duty' => 'OD', 'field_duty' => 'FD', 'not_processed' => '–',
    ];

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user !== null && ($user->can('attendance.view') || ($user->can('attendance.regularise') && Employee::query()->where('user_id', $user->id)->exists()));
    }

    public static function getNavigationGroup(): ?string
    {
        return auth()->user()?->can('attendance.view') ? 'Attendance' : 'Me';
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);
        $this->month = preg_match('/^\d{4}-\d{2}$/', $this->month) ? $this->month : now()->format('Y-m');
        if ($this->employee === null || ! $this->canSee($this->employee)) {
            $this->employee = Employee::query()->where('user_id', auth()->id())->value('id');
        }
    }

    public function canPick(): bool
    {
        return auth()->user()->can('attendance.view');
    }

    /** @return array<int, string> */
    public function employeeOptions(): array
    {
        return $this->canPick() ? CreateEmployee::managerOptions() : [];
    }

    public function shiftMonth(int $by): void
    {
        $this->month = Carbon::parse($this->month.'-01')->addMonths($by)->format('Y-m');
    }

    public function updatedEmployee(): void
    {
        if ($this->employee !== null && ! $this->canSee($this->employee)) {
            $this->employee = Employee::query()->where('user_id', auth()->id())->value('id');
        }
    }

    public function person(): ?Employee
    {
        return $this->employee ? Employee::query()->with('person')->find($this->employee) : null;
    }

    /** @return array{start: Carbon, end: Carbon, lead: int, days: list<array<string, mixed>>, summary: array<string, mixed>, holidays: list<array<string, mixed>>} */
    public function calendar(): array
    {
        $start = Carbon::parse($this->month.'-01')->startOfDay();
        $end = $start->copy()->endOfMonth();
        $employee = $this->person();
        $trips = $employee ? app(TravelCalendar::class)->days($employee, $start, $end) : [];
        $records = $employee ? AttendanceRecord::query()->where('employee_id', $employee->id)->whereDate('date', '>=', $start->toDateString())->whereDate('date', '<=', $end->toDateString())->with('shift')->get()->keyBy(fn ($r) => $r->date->toDateString()) : collect();

        $days = [];
        for ($d = $start->copy(); $d->lte($end); $d->addDay()) {
            $r = $records->get($d->toDateString());
            $status = $r?->status ?? ($d->gt(today()) ? null : 'not_processed');
            $days[] = [
                'date' => $d->copy(), 'status' => $status, 'code' => $status ? (self::CODES[$status] ?? strtoupper(substr($status, 0, 2))) : '',
                'label' => $status ? config("peopleos.attendance.statuses.{$status}", $status) : 'Upcoming', 'colour' => self::COLOURS[$status] ?? '#ffffff',
                'in' => $r?->first_in?->setTimezone($r->timezone ?: config('app.timezone'))->format('H:i'), 'out' => $r?->last_out?->setTimezone($r->timezone ?: config('app.timezone'))->format('H:i'),
                'late' => (int) ($r?->late_minutes ?? 0), 'holiday' => $r?->holiday_name, 'shift' => $r?->shift?->code,
                'trip' => $trips[$d->toDateString()] ?? null,
            ];
        }

        $summary = $employee ? app(AttendanceOutput::class)->summary($employee, $start, $end->copy()->min(today())) : [];
        $counts = collect($days)->countBy('status');

        return [
            'start' => $start, 'end' => $end, 'lead' => $start->dayOfWeekIso - 1, 'days' => $days,
            'summary' => $employee ? [
                'Present' => (int) ($counts['present'] ?? 0), 'Half days' => (int) ($counts['half_day'] ?? 0), 'Absent' => (int) ($counts['absent'] ?? 0),
                'Leave' => (int) ($counts['leave'] ?? 0) + (int) ($counts['unpaid_leave'] ?? 0), 'On duty / WFH' => (int) ($counts['on_duty'] ?? 0) + (int) ($counts['wfh'] ?? 0) + (int) ($counts['field_duty'] ?? 0),
                'Paid days' => rtrim(rtrim(number_format(max(0, (float) ($summary['paid_days'] ?? 0) - (float) ($summary['penalty_days'] ?? 0)), 1), '0'), '.'),
                'Late minutes' => (int) ($summary['late_minutes'] ?? 0), 'Penalty days' => (float) ($summary['penalty_days'] ?? 0),
            ] : [],
            'holidays' => $employee ? $this->holidays($employee, $start, $end) : [],
        ];
    }

    /** @return list<array{date: Carbon, name: string, kind: string}> */
    private function holidays(Employee $employee, Carbon $start, Carbon $end): array
    {
        $calendar = app(HolidayResolver::class)->calendarFor($employee, $start);
        if ($calendar === null) {
            return [];
        }
        $chosen = TimeOfficeRequest::query()->where('employee_id', $employee->id)->where('type', 'restricted_holiday')->effective()->pluck('holiday_id')->all();

        return Holiday::query()->where('holiday_calendar_id', $calendar->id)->whereDate('date', '>=', $start->toDateString())->whereDate('date', '<=', $end->toDateString())->orderBy('date')->get()
            ->map(fn (Holiday $h) => ['date' => $h->date, 'name' => $h->name, 'kind' => $h->type === 'optional'
                ? ($calendar->optional_requires_selection ? (in_array($h->id, $chosen, true) ? 'Optional · chosen' : 'Optional · not chosen') : 'Optional')
                : config("peopleos.attendance.holiday_types.{$h->type}", ucfirst((string) $h->type))])
            ->all();
    }

    private function canSee(int $employeeId): bool
    {
        $mine = Employee::query()->where('user_id', auth()->id())->value('id');
        if ($employeeId === $mine) {
            return true;
        }

        // The employee query is access-scoped: it finds only employees inside the viewer's scope.
        return auth()->user()->can('attendance.view') && Employee::query()->whereKey($employeeId)->exists();
    }
}
