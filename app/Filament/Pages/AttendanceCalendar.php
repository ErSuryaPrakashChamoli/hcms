<?php

namespace App\Filament\Pages;

use App\Domain\Attendance\Models\AttendanceRecord;
use App\Domain\Attendance\Models\AttendanceRegularisation;
use App\Domain\Attendance\Models\EmployeeHolidayChange;
use App\Domain\Attendance\Models\Holiday;
use App\Domain\Attendance\Models\HolidayCalendar;
use App\Domain\Attendance\Models\HolidayCalendarRule;
use App\Domain\Attendance\Models\Shift;
use App\Domain\Attendance\Models\TimeOfficeRequest;
use App\Domain\Attendance\Services\AttendanceOutput;
use App\Domain\Attendance\Services\EmployeeHolidayChanges;
use App\Domain\Attendance\Services\HolidayResolver;
use App\Domain\Attendance\Services\Regularisations;
use App\Domain\Attendance\Services\ShiftResolver;
use App\Domain\Compliance\Services\FinancialYear;
use App\Domain\Employment\Models\Employee;
use App\Domain\Expense\Services\TravelCalendar;
use App\Domain\Experience\Services\TimeAndLeaveSummary;
use App\Domain\Leave\Models\LeaveRequest;
use App\Filament\Support\AttendanceActions;
use App\Filament\Support\LeaveActions;
use App\Filament\Support\RequestChrome;
use App\Livewire\Attendance\DayDrawer;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TimePicker;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Text;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Livewire\Attributes\On;
use Livewire\Attributes\Url;
use RuntimeException;
use UnitEnum;

/**
 * HROne parity TO-18 / TO-09 / TO-19 / TO-20: an employee's month at a glance — a day grid with the status and
 * times, the month's totals (paid days and penalties from the attendance output), the holidays of their calendar
 * (optional ones and those they chose) and a printable attendance slip. Employees see their own month; people
 * with attendance.view may pick anyone in their access scope. HROne setup parity EX-F: with
 * expense.travel.on_attendance_calendar on, the days of approved trips carry a read-only travel mark.
 * HROne parity TX-7 (§24.5.2 #3): for the HR side each day opens the day drawer (DayDrawer); the month is redrawn after a
 * change made there.
 * A day not processed yet still shows what the roster and the holiday calendar make it (a weekly off, a holiday), marked as
 * planned; each status has its own tone (colours live in the theme, .pos-cal).
 * The employee picker is one search box ("Search employee"): typing an employee ID or a name lists the matches right below
 * it (searched on the server, within the viewer's access scope); picking one opens their month and empties the box.
 * My attendance: on one's own month each day opens the day panel (the day, its leave and regularisations) with "Regularise"
 * on a past day missing something (within the policy's regularisation window, Regularisations::window) and "Apply leave"
 * on a working day while paid leave is left; both act only for the viewer's own employee record and go through
 * Regularisations::request / Leaves::request. Days carry their pending requests. Under the month, for whoever is shown:
 * the leave balance and the leave requests and regularisations of the month, its financial year and till date
 * (TimeAndLeaveSummary; a past month shows them as on its last day). Employees find the page as "My attendance", the landing
 * of Time & leave.
 * HR (attendance.view) search anyone in their scope and get the same page for them: each day opens the same day panel, with
 * Regularise on their behalf for attendance.manage (as "Raise on behalf": approve now or send for approval) and Apply leave
 * on their behalf for leave.manage (as "Apply on behalf"), the same rules deciding which days offer them; the panel's
 * "Punches & corrections" opens the HR day drawer (DayDrawer) for that day. Before anyone is picked the month still shows,
 * with plain days, the holidays of the calendar everyone follows (an active rule without conditions) and empty totals.
 * The month is picked with a month and a year list (or the arrows), from the employee's joining month to the last month of
 * the payroll year (the financial year, peopleos.compliance.financial_year_start_month); the current month by default.
 */
class AttendanceCalendar extends Page
{
    /** How many matches the employee search lists at a time. */
    private const PICKER_LIMIT = 50;

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

    /** The month and year lists (1–12 and e.g. 2026); kept in step with $month. */
    public int $calMonth = 1;

    public int $calYear = 2000;

    /** What is typed in the "Search employee" box (never a name by default; emptied after a pick). */
    public string $employeeSearch = '';

    /** Per request only (private: never sent to the browser): the viewer's own employee (false = not looked up yet) and day panels. */
    private Employee|false|null $own = false;

    /** Per request only: the employee on screen (false = not looked up yet). */
    private Employee|false|null $shown = false;

    /** @var array<string, array<string, mixed>|null> */
    private array $panels = [];

    public const CODES = [
        'present' => 'P', 'half_day' => 'HD', 'absent' => 'A', 'incomplete' => 'MP', 'weekly_off' => 'WO', 'holiday' => 'HO', 'leave' => 'L',
        'unpaid_leave' => 'LWP', 'wfh' => 'WFH', 'on_duty' => 'OD', 'field_duty' => 'FD', 'not_processed' => '–',
    ];

    public function getSubheading(): ?string
    {
        return 'A month at a glance: each day\'s status and times, the paid days, and requests for the days that need one.';
    }

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user !== null && ($user->can('attendance.view') || ($user->can('attendance.regularise') && Employee::query()->where('user_id', $user->id)->exists()));
    }

    /** Employees find their month in Time & leave (it is that area's landing for them), as "My attendance". */
    public static function getNavigationGroup(): ?string
    {
        return 'Attendance';
    }

    public static function getNavigationLabel(): string
    {
        return auth()->user()?->can('attendance.view') ? 'Attendance calendar' : 'My attendance';
    }

    public function getTitle(): string
    {
        return static::getNavigationLabel();
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);
        $this->month = preg_match('/^\d{4}-\d{2}$/', $this->month) ? $this->month : now()->format('Y-m');
        if ($this->employee === null || ! $this->canSee($this->employee)) {
            $this->employee = Employee::query()->where('user_id', auth()->id())->value('id');
        }
        $this->goToMonth(Carbon::parse($this->month.'-01'));
    }

    /** @return array<int, string> the employees matching what is typed in the search box (nothing until something is typed) */
    public function employeeMatches(): array
    {
        return trim($this->employeeSearch) === '' ? [] : $this->employeeOptions($this->employeeSearch);
    }

    /** Opens the month of the employee picked from the search box (within the viewer's scope) and empties the box. */
    public function pickEmployee(int $employeeId): void
    {
        if ($this->canPick()) {
            $this->employee = $employeeId;
            $this->updatedEmployee();
        }
        $this->employeeSearch = '';
    }

    /** Enter in the search box picks the first match. */
    public function pickFirstMatch(): void
    {
        $first = array_key_first($this->employeeMatches());
        if ($first !== null) {
            $this->pickEmployee((int) $first);
        }
    }

    public function canPick(): bool
    {
        return auth()->user()->can('attendance.view');
    }

    /** HROne parity TX-7: each day opens the HR day drawer (attendance.view; an employee's own calendar is unchanged). */
    public function canOpenDays(): bool
    {
        return $this->canPick() && DayDrawer::available();
    }

    /** HROne parity TX-7: a change in the day drawer redraws the month. */
    #[On('pos-attendance-day-changed')]
    public function dayChanged(): void {}

    /**
     * Employees for the picker as "ID · name": the first ones by employee ID, or those whose ID or name contains every
     * word of $search ("anita rao", "EMP0001"). The employee query is access-scoped, so only people in the viewer's scope.
     *
     * @return array<int, string>
     */
    public function employeeOptions(?string $search = null): array
    {
        if (! $this->canPick()) {
            return [];
        }
        $words = array_values(array_filter(preg_split('/\s+/', trim((string) $search)) ?: [], fn ($w) => $w !== ''));

        return Employee::query()->with('person')->employed()
            ->when($words !== [], function (Builder $query) use ($words) {
                foreach ($words as $word) {
                    $term = '%'.addcslashes($word, '%_\\').'%';
                    $query->where(fn (Builder $q) => $q->where('employee_code', 'like', $term)
                        ->orWhereHas('person', fn (Builder $p) => $p->where('first_name', 'like', $term)->orWhere('last_name', 'like', $term)
                            ->orWhere('middle_name', 'like', $term)->orWhere('preferred_name', 'like', $term)));
                }
            })
            ->orderBy('employee_code')->limit(self::PICKER_LIMIT)->get()
            ->mapWithKeys(fn (Employee $e) => [$e->id => $e->auditLabel()])->all();
    }

    public function shiftMonth(int $by): void
    {
        $this->goToMonth(Carbon::parse($this->month.'-01')->addMonths($by));
    }

    public function thisMonth(): void
    {
        $this->goToMonth(today());
    }

    public function updatedCalMonth(): void
    {
        $this->goToMonth(Carbon::create($this->calYear, max(1, min(12, $this->calMonth)), 1));
    }

    public function updatedCalYear(): void
    {
        $this->goToMonth(Carbon::create($this->calYear, max(1, min(12, $this->calMonth)), 1));
    }

    /**
     * The months that can be picked: from the employee's joining month (five years back when it is not known) to the last
     * month of the payroll year today is in.
     *
     * @return array{0: Carbon, 1: Carbon}
     */
    public function monthBounds(): array
    {
        $last = FinancialYear::make()->end(today())->startOfMonth();
        $joined = $this->employee ? Employee::query()->whereKey($this->employee)->value('joining_date') : null;
        $first = ($joined ? Carbon::parse($joined) : today()->subYears(5))->startOfMonth()->min($last);

        return [$first, $last];
    }

    /** Opens a month, kept within monthBounds(). */
    private function goToMonth(Carbon $month): void
    {
        [$first, $last] = $this->monthBounds();
        $month = $month->copy()->startOfMonth()->max($first)->min($last);
        $this->month = $month->format('Y-m');
        $this->calMonth = $month->month;
        $this->calYear = $month->year;
    }

    public function updatedEmployee(): void
    {
        if ($this->employee !== null && ! $this->canSee($this->employee)) {
            $this->employee = Employee::query()->where('user_id', auth()->id())->value('id');
        }
        $this->goToMonth(Carbon::parse($this->month.'-01'));
    }

    public function person(): ?Employee
    {
        if ($this->employee === null) {
            return null;
        }
        if ($this->shown === false || $this->shown?->id !== $this->employee) {
            $this->shown = Employee::query()->with('person')->find($this->employee);
        }

        return $this->shown;
    }

    /** @return array{start: Carbon, end: Carbon, lead: int, trail: int, days: list<array<string, mixed>>, summary: list<array{label: string, value: int|float|string, tone: string}>, holidays: list<array<string, mixed>>, unprocessed: int} */
    public function calendar(): array
    {
        $start = Carbon::parse($this->month.'-01')->startOfDay();
        $end = $start->copy()->endOfMonth();
        $employee = $this->person();
        $trips = $employee ? app(TravelCalendar::class)->days($employee, $start, $end) : [];
        $records = $employee ? AttendanceRecord::query()->where('employee_id', $employee->id)->whereDate('date', '>=', $start->toDateString())->whereDate('date', '<=', $end->toDateString())->with('shift')->get()->keyBy(fn ($r) => $r->date->toDateString()) : collect();
        $roster = $employee ? (app(ShiftResolver::class)->resolveBetween([$employee], $start, $end)[$employee->id] ?? []) : [];
        $holidays = $this->holidays($employee, $start, $end);
        $offs = collect($holidays)->where('off', true)->mapWithKeys(fn ($h) => [$h['date']->toDateString() => $h['name']])->all();
        $requests = $employee ? $this->openRequests($employee, $start, $end) : [];

        $days = [];
        for ($d = $start->copy(); $d->lte($end); $d->addDay()) {
            $date = $d->toDateString();
            $r = $records->get($date);
            $status = $r?->status ?? ($employee === null || $d->gt(today()) ? null : 'not_processed');
            // What the day shows: its processed status, else what the holiday calendar and the roster make it. With no
            // employee picked, a plain day ("blank") or a holiday of the calendar everyone follows.
            $tone = $r?->status ?? (isset($offs[$date]) ? 'holiday' : (($roster[$date]['weekly_off'] ?? false) ? 'weekly_off' : ($status ?? ($employee === null ? 'blank' : 'upcoming'))));
            $planned = $employee !== null && $r === null && in_array($tone, ['holiday', 'weekly_off'], true);
            $label = match ($tone) {
                'upcoming' => 'Upcoming',
                'blank' => 'Search an employee to see their attendance',
                default => config("peopleos.attendance.statuses.{$tone}", $tone),
            };
            // Something to fix: a past working day absent, with a missed punch, a half day not taken as leave, late, left
            // early, or with nothing processed.
            $issue = ! $d->gt(today()) && (in_array($tone, ['absent', 'incomplete', 'not_processed'], true)
                || ($tone === 'half_day' && ! $r?->is_half_day_leave) || ($tone === 'present' && ((int) $r?->late_minutes > 0 || (int) $r?->early_leave_minutes > 0)));
            // The day's shift: the one it was processed with, else the roster's (assignment, rule or shift override).
            $shift = $r ? $r->shift : ($roster[$date]['shift'] ?? null);
            $days[] = [
                'date' => $d->copy(), 'status' => $status, 'tone' => $tone, 'planned' => $planned,
                'code' => in_array($tone, ['upcoming', 'blank'], true) ? '' : (self::CODES[$tone] ?? strtoupper(substr($tone, 0, 2))),
                'label' => $planned ? $label.' (planned, not processed yet)' : $label,
                'in' => $r?->first_in?->setTimezone($r->timezone ?: config('app.timezone'))->format('H:i'), 'out' => $r?->last_out?->setTimezone($r->timezone ?: config('app.timezone'))->format('H:i'),
                'late' => (int) ($r?->late_minutes ?? 0), 'early' => (int) ($r?->early_leave_minutes ?? 0), 'holiday' => $r ? $r->holiday_name : ($offs[$date] ?? null),
                'shift' => $shift?->code, 'shift_title' => $shift ? self::shiftLabel($shift) : null,
                // An employee's day that is neither a weekly off nor a holiday and has no shift: none is assigned.
                'no_shift' => $employee !== null && $shift === null && ! in_array($tone, ['weekly_off', 'holiday'], true),
                'trip' => $trips[$date] ?? null,
                'issue' => $issue,
                // Pending leave and regularisations, and approved leave the day does not show yet (not processed).
                'requests' => array_values(array_filter($requests[$date] ?? [], fn (array $q) => $q['state'] === 'pending' || ! in_array($tone, ['leave', 'unpaid_leave'], true))),
            ];
        }

        $summary = $employee ? app(AttendanceOutput::class)->summary($employee, $start, $end->copy()->min(today())) : [];
        $counts = collect($days)->countBy('tone');
        $count = fn (string ...$tones) => array_sum(array_map(fn ($t) => (int) ($counts[$t] ?? 0), $tones));

        return [
            'start' => $start, 'end' => $end, 'lead' => $start->dayOfWeekIso - 1, 'trail' => 7 - $end->dayOfWeekIso, 'days' => $days,
            // With no employee picked the totals keep their places, empty ("–"), until someone is picked.
            'summary' => array_map(fn (array $stat) => $employee ? $stat : [...$stat, 'value' => '–'], [
                ['label' => 'Present', 'value' => $count('present'), 'tone' => 'present'],
                ['label' => 'Half days', 'value' => $count('half_day'), 'tone' => 'half_day'],
                ['label' => 'Absent', 'value' => $count('absent'), 'tone' => 'absent'],
                ['label' => 'Leave', 'value' => $count('leave', 'unpaid_leave'), 'tone' => 'leave'],
                ['label' => 'On duty / WFH', 'value' => $count('on_duty', 'wfh', 'field_duty'), 'tone' => 'on_duty'],
                ['label' => 'Weekly offs', 'value' => $count('weekly_off'), 'tone' => 'weekly_off'],
                ['label' => 'Holidays', 'value' => $count('holiday'), 'tone' => 'holiday'],
                ['label' => 'Paid days', 'value' => rtrim(rtrim(number_format(max(0, (float) ($summary['paid_days'] ?? 0) - (float) ($summary['penalty_days'] ?? 0)), 1), '0'), '.'), 'tone' => 'paid'],
                ['label' => 'Late minutes', 'value' => (int) ($summary['late_minutes'] ?? 0), 'tone' => 'late'],
                ['label' => 'Penalty days', 'value' => (float) ($summary['penalty_days'] ?? 0), 'tone' => 'penalty'],
            ]),
            'holidays' => $holidays,
            'unprocessed' => $count('not_processed'),
            // The shifts of the month (each once) and how many of the employee's days have none assigned.
            'shifts' => [
                'assigned' => collect($days)->whereNotNull('shift')->uniqueStrict('shift')->map(fn (array $d) => ['code' => $d['shift'], 'label' => $d['shift_title']])->values()->all(),
                'unassigned' => collect($days)->where('no_shift', true)->count(),
            ],
        ];
    }

    /** "General (09:00–18:00)": the shift's name, with its times added when the name does not carry them already. */
    public static function shiftLabel(Shift $shift): string
    {
        $from = $shift->start_time ? substr((string) $shift->start_time, 0, 5) : null;
        $to = $shift->end_time ? substr((string) $shift->end_time, 0, 5) : null;

        return $shift->name.($from && $to && ! str_contains((string) $shift->name, $from) ? " · {$from}–{$to}" : '');
    }

    /**
     * Leave (pending or approved) and pending regularisations on each day of the month, as short chips.
     *
     * @return array<string, list<array{label: string, title: string, state: string}>>
     */
    private function openRequests(Employee $employee, Carbon $start, Carbon $end): array
    {
        $out = [];
        LeaveRequest::query()->with('leaveType')->where('employee_id', $employee->id)->whereIn('status', LeaveRequest::ACTIVE)
            ->whereDate('from_date', '<=', $end->toDateString())->whereDate('to_date', '>=', $start->toDateString())->get()
            ->each(function (LeaveRequest $leave) use (&$out) {
                $state = $leave->status === 'pending' ? 'pending' : 'approved';
                $code = (string) ($leave->leaveType?->code ?? 'Leave');
                $dates = is_array($leave->dates) && $leave->dates !== [] ? array_column($leave->dates, 'date')
                    : array_map(fn (Carbon $d) => $d->toDateString(), $leave->from_date->toPeriod($leave->to_date)->toArray());
                foreach ($dates as $date) {
                    $out[Carbon::parse($date)->toDateString()][] = ['label' => $code.($state === 'pending' ? ' pending' : ''),
                        'title' => ($leave->leaveType?->name ?? 'Leave').' · '.($state === 'pending' ? 'waiting for approval' : 'approved'), 'state' => $state];
                }
            });
        AttendanceRegularisation::query()->where('employee_id', $employee->id)->where('status', 'pending')
            ->whereDate('date', '>=', $start->toDateString())->whereDate('date', '<=', $end->toDateString())->get(['date', 'type'])
            ->each(function (AttendanceRegularisation $ar) use (&$out) {
                $out[$ar->date->toDateString()][] = ['label' => 'AR pending', 'state' => 'pending',
                    'title' => 'Regularisation ('.config("peopleos.attendance.regularisation_types.{$ar->type}", $ar->type).') · waiting for approval'];
            });

        return $out;
    }

    // --- My attendance: the day panel, regularise and apply leave ---------------------------------------------------------

    /** The viewer's own employee record (never the one in the URL: these actions act only for oneself). */
    public function ownEmployee(): ?Employee
    {
        if ($this->own === false) {
            $this->own = Employee::query()->with('person')->where('user_id', auth()->id())->first();
        }

        return $this->own;
    }

    /** The month on screen is the viewer's own. */
    public function isOwn(): bool
    {
        return $this->employee !== null && $this->employee === $this->ownEmployee()?->id;
    }

    /**
     * Whom the day panel and its forms are for: the viewer's own record on their own month; on someone else's month, for a
     * viewer who may pick anyone (attendance.view), the employee on screen (the employee query is access-scoped).
     */
    public function subject(): ?Employee
    {
        if ($this->isOwn()) {
            return $this->ownEmployee();
        }

        return $this->canPick() ? $this->person() : null;
    }

    /** Regularise: one's own day with attendance.regularise; someone else's with attendance.manage, as "Raise on behalf". */
    public function mayRegularise(): bool
    {
        return $this->isOwn() ? auth()->user()->can('attendance.regularise') : ($this->subject() !== null && auth()->user()->can('attendance.manage'));
    }

    /** Apply leave: one's own with leave.apply; someone else's with leave.manage, as "Apply on behalf". */
    public function mayApplyLeave(): bool
    {
        return $this->isOwn() ? auth()->user()->can('leave.apply') : ($this->subject() !== null && auth()->user()->can('leave.manage'));
    }

    /** What a click on a day offers, for the hint over the month. */
    public function dayHint(): ?string
    {
        if ($this->subject() === null) {
            return $this->person() === null && $this->canPick() ? 'Search an employee above to see their attendance' : null;
        }
        $can = implode(' or ', array_filter([$this->mayRegularise() ? 'regularise it' : null, $this->mayApplyLeave() ? 'apply for leave' : null]));
        if ($this->isOwn()) {
            return $can === '' ? 'Click a day to see its details' : 'Click a day to '.$can;
        }

        return 'Click a day to see its details'.($can === '' ? '' : ', '.$can.' for '.($this->subject()->person?->first_name ?: $this->subject()->employee_code));
    }

    /**
     * Leave balance, and the leave requests and regularisations of the month, its financial year and till date; a past
     * month as on its last day (balances_on), otherwise as on today (balances_on null).
     */
    public function overview(): ?array
    {
        $employee = $this->person();
        if ($employee === null) {
            return null;
        }
        $summary = app(TimeAndLeaveSummary::class);
        $month = Carbon::parse($this->month.'-01');
        $end = $month->copy()->endOfMonth()->startOfDay();
        $on = $end->lt(today()) ? $end : null;

        return ['balances' => $summary->balances($employee, $on), 'balances_on' => $on, ...$summary->requests($employee, $month)];
    }

    /**
     * One day of the month on screen for the day panel (one's own, or for HR anyone's): the day as the calendar shows it, its
     * leave and regularisations (any status), and what may be asked for it.
     *
     * @return array<string, mixed>|null
     */
    public function dayPanel(?string $date): ?array
    {
        return $this->panels[(string) $date] ??= $this->buildDayPanel($date);
    }

    /** @return array<string, mixed>|null */
    private function buildDayPanel(?string $date): ?array
    {
        $employee = $this->subject();
        $day = $employee !== null && $date !== null && preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) === 1 && Carbon::hasFormat($date, 'Y-m-d')
            ? collect($this->calendar()['days'])->first(fn (array $d) => $d['date']->toDateString() === $date) : null;
        if ($day === null) {
            return null;
        }
        $own = $this->isOwn();
        $fix = ! $own && $this->canOpenDays() ? ' Change the day itself with Punches & corrections.' : '';
        $leave = LeaveRequest::query()->with('leaveType')->where('employee_id', $employee->id)
            ->whereDate('from_date', '<=', $date)->whereDate('to_date', '>=', $date)->latest('id')->get();
        $regularisations = AttendanceRegularisation::query()->where('employee_id', $employee->id)->whereDate('date', $date)->latest('id')->get();
        $available = array_sum(array_column(app(TimeAndLeaveSummary::class)->balances($employee), 'available'));
        $notes = $day['no_shift'] ? [$own ? 'No shift is assigned to you for this day. Ask HR to assign your shift.' : 'No shift is assigned for this day: assign one with a work schedule or the roster.'] : [];

        $regularise = false;
        if ($day['issue'] && $this->mayRegularise()) {
            // The viewer's window: an employee's own, or the one the policy gives HR / managers raising it.
            $window = app(Regularisations::class)->window($employee, $date);
            if ($regularisations->contains(fn (AttendanceRegularisation $r) => in_array($r->status, ['pending', 'approved'], true))) {
                $notes[] = 'A regularisation for this day is already '.($regularisations->contains('status', 'pending') ? 'waiting for approval.' : 'approved.');
            } elseif ($window === null) {
                $notes[] = $own ? 'Your attendance policy does not allow regularisation. Ask HR to correct this day.' : 'The attendance policy does not allow regularisation for this day.'.$fix;
            } elseif ($day['date']->lt(today()->subDays($window))) {
                $notes[] = "Regularisation is open for the last {$window} days only.".($own ? ' Ask HR to correct this day.' : $fix);
            } else {
                $regularise = true;
            }
        }

        $applyLeave = false;
        $onLeave = $leave->contains(fn (LeaveRequest $l) => in_array($l->status, LeaveRequest::ACTIVE, true));
        if (! in_array($day['tone'], ['holiday', 'weekly_off', 'leave', 'unpaid_leave'], true) && ! $onLeave && $this->mayApplyLeave()) {
            $applyLeave = $available > 0;
            if (! $applyLeave) {
                $notes[] = $own ? 'No paid leave left to apply for.' : 'No paid leave left for this employee.';
            }
        }

        return [...$day, 'own' => $own, 'name' => $employee->person?->first_name ?: $employee->employee_code, 'leave' => $leave, 'regularisations' => $regularisations,
            'available' => $available, 'regularise' => $regularise, 'apply_leave' => $applyLeave, 'notes' => $notes];
    }

    public function dayAction(): Action
    {
        return Action::make('day')
            ->modalHeading(fn (array $arguments) => ($d = $this->argumentDate($arguments)) ? $d->format('l, j F Y') : 'Day')
            ->modalWidth(Width::Medium)->slideOver()
            ->modalContent(fn (array $arguments) => view('filament.pages.attendance-calendar-day', ['day' => $this->dayPanel($arguments['date'] ?? null)]))
            ->modalSubmitAction(false)->modalCancelActionLabel('Close')
            ->visible(fn () => $this->subject() !== null)
            ->extraModalFooterActions(fn (array $arguments) => [
                Action::make('dayRegularise')->label('Regularise')->icon(Heroicon::OutlinedClock)
                    ->visible(fn () => (bool) ($this->dayPanel($arguments['date'] ?? null)['regularise'] ?? false))
                    ->action(fn () => $this->replaceMountedAction('regularise', ['date' => $arguments['date'] ?? null])),
                Action::make('dayApplyLeave')->label('Apply leave')->icon(Heroicon::OutlinedCalendarDays)->color('gray')
                    ->visible(fn () => (bool) ($this->dayPanel($arguments['date'] ?? null)['apply_leave'] ?? false))
                    ->action(fn () => $this->replaceMountedAction('applyLeave', ['date' => $arguments['date'] ?? null])),
                // HR: the day's punches, status override and reprocessing (HROne parity TX-7's day drawer).
                Action::make('dayCorrections')->label('Punches & corrections')->icon(Heroicon::OutlinedWrenchScrewdriver)->color('gray')
                    ->visible(fn () => ! $this->isOwn() && $this->canOpenDays() && $this->dayPanel($arguments['date'] ?? null) !== null)
                    ->cancelParentActions()
                    ->action(fn () => $this->dispatch('pos-day-drawer-open', employee: (int) $this->employee, date: (string) $this->argumentDate($arguments)?->toDateString())),
            ]);
    }

    /**
     * "Regularise" a past day: what was missing, the actual times and why. One's own goes to the approver; HR raising it for
     * someone else (attendance.manage) approves it at once or sends it for approval, as "Raise on behalf".
     */
    public function regulariseAction(): Action
    {
        return Action::make('regularise')->label('Regularise')->icon(Heroicon::OutlinedClock)
            ->modalHeading(fn (array $arguments) => 'Regularise '.($this->argumentDate($arguments)?->format('D j M Y') ?? '').($this->isOwn() ? '' : ' for '.$this->subject()?->display_name))
            ->modalDescription(fn () => $this->isOwn() ? 'Tell your manager what happened; the day is reprocessed once approved.'
                : 'Raised on their behalf: approve it now (the day is reprocessed at once), or send it to their approver like their own request.')
            ->modalSubmitActionLabel(fn () => $this->isOwn() ? 'Send request' : 'Raise')
            ->visible(fn () => $this->subject() !== null && $this->mayRegularise())
            ->fillForm(function (array $arguments) {
                $day = $this->dayPanel($arguments['date'] ?? null);

                return [
                    'type' => match ($day['tone'] ?? null) {
                        'absent' => 'absent',
                        'present' => ($day['late'] ?? 0) > 0 ? 'late' : 'early_leave',
                        default => 'missed_punch',
                    },
                    'in' => $day['in'] ?? null, 'out' => $day['out'] ?? null, 'reason' => null, 'attachments' => [], 'decision' => 'approve',
                ];
            })
            ->schema(fn (array $arguments) => [
                RequestChrome::header(fn () => $this->subject()),
                Text::make(fn () => ($d = $this->dayPanel($arguments['date'] ?? null)) ? 'The day shows: '.$d['label'].($d['in'] || $d['out'] ? ' · in '.($d['in'] ?? '–').', out '.($d['out'] ?? '–') : '') : ''),
                Select::make('type')->label('What needs correcting')->options(config('peopleos.attendance.regularisation_types'))->required()->native(false),
                TimePicker::make('in')->label('Actual in')->seconds(false),
                TimePicker::make('out')->label('Actual out')->seconds(false),
                Textarea::make('reason')->required()->maxLength(255),
                AttendanceActions::attachmentsField(),
                Radio::make('decision')->label('After raising it')->required()->visible(fn () => ! $this->isOwn())
                    ->options(['approve' => 'Approve now', 'send' => 'Send for approval'])
                    ->descriptions([
                        'approve' => 'Applied and approved by you; the day is reprocessed at once.',
                        'send' => 'Waits for the approver, like the employee\'s own request.',
                    ]),
                RequestChrome::route('attendance_regularisation', fn () => $this->subject()),
            ])
            ->action(function (array $data, array $arguments) {
                $date = $this->argumentDate($arguments);
                $employee = $this->subject();
                if ($date === null || $employee === null || ! $this->mayRegularise()) {
                    return;
                }
                try {
                    $day = $date->toDateString();
                    $request = app(Regularisations::class)->request($employee, $day, $data['type'], $data['reason'], $data['in'] ? $day.' '.$data['in'] : null, $data['out'] ? $day.' '.$data['out'] : null,
                        auth()->user(), array_values((array) ($data['attachments'] ?? [])));
                    if ($this->isOwn()) {
                        RequestChrome::confirmed('attendance_regularisation', $request, 'Regularisation sent to your manager');
                    } elseif (($data['decision'] ?? 'approve') === 'approve') {
                        app(Regularisations::class)->approve($request, 'Applied by HR');
                        Notification::make()->success()->title('Regularised and reprocessed')->body($employee->display_name.' · '.$date->format('d M Y'))->send();
                    } else {
                        Notification::make()->success()->title('Regularisation sent for approval')->body($employee->display_name.' · waiting for a decision on '.$date->format('d M Y').'.')->send();
                    }
                } catch (RuntimeException $e) {
                    Notification::make()->danger()->title($this->isOwn() ? 'Cannot request' : 'Not raised')->body($e->getMessage())->persistent()->send();
                }
            });
    }

    /** "Apply leave" from a day of the month on screen (one's own, or for HR anyone's, as "Apply on behalf"), starting on that day. */
    public function applyLeaveAction(): Action
    {
        return Action::make('applyLeave')->label('Apply leave')->icon(Heroicon::OutlinedCalendarDays)
            ->modalHeading(fn () => $this->isOwn() ? 'Apply leave' : 'Apply leave for '.$this->subject()?->display_name)
            ->modalDescription(fn () => $this->isOwn() ? 'Your balance updates as you pick dates. Your manager is notified when you submit.'
                : 'Applied on their behalf: their balance updates as you pick dates, and the request goes to their approver.')
            ->modalSubmitActionLabel('Submit request')
            ->visible(fn () => $this->subject() !== null && $this->mayApplyLeave())
            // fillForm() replaces the form's defaults, so the leave form's own defaults are given here too.
            ->fillForm(function (array $arguments) {
                $date = ($this->argumentDate($arguments) ?? today())->toDateString();

                return ['from_date' => $date, 'to_date' => $date, 'from_session' => 'full', 'to_session' => 'full', 'visible_to_colleagues' => 'yes'];
            })
            ->schema(LeaveActions::applyForm(fn () => $this->subject() ?? new Employee))
            ->action(function (array $data) {
                if (($employee = $this->subject()) !== null && $this->mayApplyLeave()) {
                    LeaveActions::apply($employee, $data);
                }
            });
    }

    private function argumentDate(array $arguments): ?Carbon
    {
        $date = $arguments['date'] ?? null;

        return is_string($date) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) === 1 && Carbon::hasFormat($date, 'Y-m-d') ? Carbon::parse($date)->startOfDay() : null;
    }

    /**
     * The employee's holidays (off: a day off for them, as HolidayResolver::holidayOn() decides); with no employee picked,
     * those of the calendar everyone follows (an optional holiday that must be chosen is then not a day off).
     *
     * @return list<array{date: Carbon, name: string, kind: string, off: bool}>
     */
    private function holidays(?Employee $employee, Carbon $start, Carbon $end): array
    {
        $calendar = $employee ? app(HolidayResolver::class)->calendarFor($employee, $start) : $this->everyonesCalendar();
        // HROne parity TX-12: HR's holiday changes for the employee (none by default) — a holiday taken away stays listed as
        // not a day off, a holiday given is a day off.
        $changes = $employee ? app(EmployeeHolidayChanges::class)->liveBetween($employee, $start, $end) : collect();
        if ($calendar === null && $changes->isEmpty()) {
            return [];
        }
        $chosen = $employee ? TimeOfficeRequest::query()->where('employee_id', $employee->id)->where('type', 'restricted_holiday')->effective()->pluck('holiday_id')->all() : [];

        $holidays = $calendar === null ? [] : Holiday::query()->where('holiday_calendar_id', $calendar->id)->whereDate('date', '>=', $start->toDateString())->whereDate('date', '<=', $end->toDateString())->orderBy('date')->get()
            ->map(fn (Holiday $h) => ['date' => $h->date, 'name' => $h->name, 'kind' => $h->type === 'optional'
                ? ($calendar->optional_requires_selection && $employee ? (in_array($h->id, $chosen, true) ? 'Optional · chosen' : 'Optional · not chosen') : 'Optional')
                : config("peopleos.attendance.holiday_types.{$h->type}", ucfirst((string) $h->type)),
                'off' => $h->type !== 'optional' || ! $calendar->optional_requires_selection || in_array($h->id, $chosen, true)])
            ->all();
        if ($changes->isEmpty()) {
            return $holidays;
        }

        $holidays = array_map(fn (array $h) => isset($changes[$h['date']->toDateString()]) && ! $changes[$h['date']->toDateString()]->adds()
            ? ['kind' => 'Taken away for this employee', 'off' => false] + $h : $h, $holidays);
        foreach ($changes->filter(fn (EmployeeHolidayChange $c) => $c->adds()) as $change) {
            $holidays[] = ['date' => $change->date->copy(), 'name' => (string) $change->name, 'kind' => $change->typeLabel().' · given by HR', 'off' => true];
        }
        usort($holidays, fn (array $a, array $b) => $a['date'] <=> $b['date']);

        return $holidays;
    }

    /** The holiday calendar of an active rule without conditions (the one everyone follows), if there is one. */
    private function everyonesCalendar(): ?HolidayCalendar
    {
        $calendar = HolidayCalendarRule::query()->with('calendar')->where('status', 'active')->orderBy('priority')->orderBy('id')->get()
            ->first(fn (HolidayCalendarRule $rule) => ($rule->conditions ?? []) === [])?->calendar;

        return $calendar !== null && $calendar->status->value === 'active' ? $calendar : null;
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
