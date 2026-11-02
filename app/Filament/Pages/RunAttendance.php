<?php

namespace App\Filament\Pages;

use App\Domain\Attendance\Models\AttendanceArrear;
use App\Domain\Attendance\Models\AttendanceRecord;
use App\Domain\Attendance\Services\AttendanceArrears;
use App\Domain\Attendance\Services\AttendanceMonthCloses;
use App\Domain\Attendance\Services\AttendanceMonthDesk;
use App\Domain\Attendance\Services\AttendanceMonthPopulation;
use App\Domain\Attendance\Services\AttendanceMonthRuns;
use App\Domain\Attendance\Services\AttendanceOutput;
use App\Domain\Attendance\Services\AttendanceProcessor;
use App\Domain\Attendance\Services\PaidDaysRegister;
use App\Domain\Configuration\Models\Policy;
use App\Domain\Employment\Models\Employee;
use App\Domain\Experience\BulkOperations\ApplyLeaveOperation;
use App\Domain\Identity\Services\AccessScopes;
use App\Domain\Organisation\Models\Company;
use App\Domain\Organisation\Models\Department;
use App\Domain\Organisation\Models\Designation;
use App\Domain\Organisation\Models\EmploymentType;
use App\Domain\Organisation\Models\Location;
use App\Filament\Resources\AttendanceArrears\AttendanceArrearResource;
use App\Filament\Support\AttendancePolicyFilters;
use App\Filament\Support\BulkRecordActions;
use App\Filament\Support\EmployeeFilters;
use App\Filament\Support\TimeOfficeRegisters;
use BackedEnum;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Livewire\Attributes\Url;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;
use UnitEnum;

/**
 * HROne parity TX-3 (matrix 24 §24.5.3, HR Desk → Time Office → Run attendance): one company's attendance month (its
 * pay-cycle month) worked through in HROne's steps —
 *
 * 1. Pre-verification: absent and half days (count, CSV, a selectable list with bulk "Mark present" and, with TIM-5 on,
 *    "Apply leave" on WO-1's bulk framework).
 * 2. Arrears: corrections to earlier months this month would take, by the month they correct; hold / release.
 * 3. Processing: the month calculated in the background for everyone or the people chosen, with progress and a notice.
 *
 * plus readiness errors and reminders of requests still waiting, each with its list and CSV; a per-employee review; and,
 * with attendance.month_close.enabled (TIM-1, off by default), closing and reopening the month. Opens for attendance.view
 * with attendance.process (steps 1 and 3, close / reopen) or attendance.arrears (step 2).
 */
class RunAttendance extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedPlayCircle;

    protected static string|UnitEnum|null $navigationGroup = 'Attendance';

    protected static ?string $navigationLabel = 'Run attendance';

    protected static ?string $title = 'Run attendance';

    protected static ?string $slug = 'run-attendance';

    protected static ?int $navigationSort = 2;

    protected string $view = 'filament.pages.run-attendance';

    public const BULK_OPERATIONS = ['attendance_records.mark_present', 'attendance_records.apply_leave'];

    #[Url]
    public ?string $company = null;

    #[Url]
    public ?string $month = null;

    /** The month's figures load after the page (wire:init). */
    public bool $ready = false;

    public ?string $openList = null;

    public ?string $reviewEmployee = null;

    /** @var array<string, mixed>|null this request's overview */
    private ?array $overviewMemo = null;

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user !== null && $user->hasPermission('attendance.view') && ($user->hasPermission('attendance.process') || $user->hasPermission('attendance.arrears'));
    }

    public function getSubheading(): ?string
    {
        return 'Check the month, take or hold arrears, process attendance in the background'.(app(AttendanceMonthCloses::class)->enabled() ? ' and close the month before payroll.' : '.');
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);
        $companies = $this->companies();
        if (! array_key_exists((int) $this->company, $companies)) {
            $this->company = $companies === [] ? null : (string) array_key_first($companies);
        }
        $this->normaliseMonth();
    }

    /** @return array<int, string> */
    public function companies(): array
    {
        return Company::query()->orderBy('name')->pluck('name', 'id')->map(fn ($n) => (string) $n)->all();
    }

    /** @return array<string, string> */
    public function monthOptions(): array
    {
        return $this->companyId() === null ? [] : app(AttendanceMonthDesk::class)->months($this->companyId());
    }

    public function companyId(): ?int
    {
        return filled($this->company) ? (int) $this->company : null;
    }

    /** @return array{0: int, 1: int} */
    public function period(): array
    {
        return [(int) substr((string) $this->month, 0, 4), (int) substr((string) $this->month, 5, 2)];
    }

    public function load(): void
    {
        $this->ready = true;
    }

    public function updatedCompany(): void
    {
        if (! array_key_exists((int) $this->company, $this->companies())) {
            $this->company = (string) array_key_first($this->companies());
        }
        $this->normaliseMonth();
        $this->afterChange();
    }

    public function updatedMonth(): void
    {
        $this->normaliseMonth();
        $this->afterChange();
    }

    /** @return array<string, mixed>|null everything the steps show (null until loaded or without a company) */
    public function overview(): ?array
    {
        if (! $this->ready || $this->companyId() === null) {
            return null;
        }
        [$year, $month] = $this->period();
        try {
            return $this->overviewMemo ??= app(AttendanceMonthDesk::class)->overview($this->companyId(), $year, $month);
        } catch (RuntimeException $e) {
            return $this->overviewMemo = ['error' => $e->getMessage()];
        }
    }

    /** @return array{columns: array<string, string>, rows: list<array<string, mixed>>}|null */
    public function openedList(): ?array
    {
        if ($this->openList === null || ! $this->listAllowed($this->openList)) {
            return null;
        }
        [$year, $month] = $this->period();

        return app(AttendanceMonthDesk::class)->list($this->openList, (int) $this->companyId(), $year, $month);
    }

    public function toggleList(string $key): void
    {
        $this->openList = $this->listAllowed($key) && $this->openList !== $key ? $key : null;
    }

    public function downloadList(string $key): StreamedResponse
    {
        abort_unless(static::canAccess() && $this->listAllowed($key) && $this->companyId() !== null, 403);
        [$year, $month] = $this->period();
        $csv = app(AttendanceMonthDesk::class)->csv($key, auth()->user(), (int) $this->companyId(), $year, $month);
        $name = 'run-attendance-'.str_replace('_', '-', $key).'-'.$this->month.'.csv';

        return response()->streamDownload(fn () => print ($csv), $name, ['Content-Type' => 'text/csv']);
    }

    public function hasRunningRun(): bool
    {
        $overview = $this->overview();

        return $overview !== null && ! isset($overview['error']) && $overview['processing']['runs']->contains(fn ($run) => $run->status === 'running');
    }

    public function canProcess(): bool
    {
        return auth()->user()?->hasPermission('attendance.process') ?? false;
    }

    public function canWorkArrears(): bool
    {
        return auth()->user()?->hasPermission('attendance.arrears') ?? false;
    }

    // --- Step 1: absent and half days -------------------------------------------------------------------------------

    public function table(Table $table): Table
    {
        return $table
            ->query(fn (): Builder => $this->absenceQuery())
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['employee.person', 'shift']))
            ->heading('Absent and half days')
            ->description('Select days to mark present'.(ApplyLeaveOperation::enabled() ? ' or to apply leave for the employees' : '').'; each day is handled on its own and refusals are listed in Bulk runs.')
            ->columns([
                TextColumn::make('employee.employee_code')->label('Employee code'),
                TextColumn::make('employee.person.display_name')->label('Employee')->searchable(query: EmployeeFilters::search('employee')),
                TextColumn::make('status')->label('Attendance status')->badge()->formatStateUsing(fn (string $state) => $state === 'half_day' ? 'Half day' : 'Absent')
                    ->color(fn (string $state) => $state === 'half_day' ? 'warning' : 'danger'),
                TextColumn::make('date')->label('Attendance date')->date('D, d M Y')->sortable(),
                TextColumn::make('exceptions')->label('Type')->formatStateUsing(fn ($state) => implode(', ', array_map(fn ($e) => str_replace('_', ' ', (string) $e), (array) $state)))->placeholder('—'),
                TextColumn::make('shift.name')->label('Shift')->placeholder('—')->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('status')->label('Attendance status')->options(['absent' => 'Absent', 'half_day' => 'Half day']),
                ...TimeOfficeRegisters::filters('attendance.view'),
                AttendancePolicyFilters::policy('attendance.view'),
                AttendancePolicyFilters::punchMode('attendance.view'),
            ])
            ->recordActions([
                Action::make('calendar')->label('Calendar')->icon(Heroicon::OutlinedCalendarDays)->color('gray')
                    ->url(fn (AttendanceRecord $record) => AttendanceCalendar::getUrl(['employee' => $record->employee_id, 'month' => $record->date->format('Y-m')])),
            ])
            ->toolbarActions([BulkRecordActions::selected('run_attendance', self::bulkOperations())])
            ->defaultSort('date')
            ->paginated([25, 50, 100]);
    }

    /** @return list<string> */
    public static function bulkOperations(): array
    {
        return ApplyLeaveOperation::enabled() ? self::BULK_OPERATIONS : ['attendance_records.mark_present'];
    }

    private function absenceQuery(): Builder
    {
        if ($this->companyId() === null) {
            return AttendanceRecord::query()->whereRaw('1 = 0');
        }
        [$year, $month] = $this->period();
        try {
            return app(AttendanceMonthDesk::class)->absenceQuery($this->companyId(), $year, $month);
        } catch (RuntimeException) {
            return AttendanceRecord::query()->whereRaw('1 = 0');
        }
    }

    // --- Header ------------------------------------------------------------------------------------------------------

    protected function getHeaderActions(): array
    {
        return [
            // HROne's "IMPORTS": the uploads used before processing (each shown only to people allowed it).
            TimeOfficeRegisters::uploads(['attendance_sheet', 'day_overrides', 'punches', 'shift_roster', 'overtime']),
        ];
    }

    // --- Step 3: process the month ----------------------------------------------------------------------------------

    public function processMonthAction(): Action
    {
        return Action::make('processMonth')->label('Process')->icon(Heroicon::OutlinedArrowPath)
            ->authorize(fn () => $this->canProcess())
            ->modalHeading(fn () => 'Process attendance · '.$this->monthLabel())
            ->modalDescription('Calculates every day of the month so far, in the background, for the employees chosen (leave everything blank for everyone in the month). You are told in the app and by e-mail when it is done.')
            ->modalSubmitActionLabel('Process')
            ->schema([
                Select::make('employee_ids')->label('Employees')->multiple()->searchable()->options(fn () => $this->employeeOptions())->placeholder('Everyone in the month'),
                Select::make('department_ids')->label('Departments')->multiple()->options(fn () => Department::query()->orderBy('name')->pluck('name', 'id')->all()),
                Select::make('location_ids')->label('Locations')->multiple()->options(fn () => Location::query()->orderBy('name')->pluck('name', 'id')->all()),
                Select::make('designation_ids')->label('Designations')->multiple()->options(fn () => Designation::query()->orderBy('name')->pluck('name', 'id')->all()),
                Select::make('employment_type_ids')->label('Employee types')->multiple()->options(fn () => EmploymentType::query()->orderBy('name')->pluck('name', 'id')->all()),
                Select::make('attendance_policy_id')->label('Attendance policy')->options(fn () => Policy::query()->where('type', 'attendance')->orderBy('name')->pluck('name', 'id')->all() + ['none' => 'No attendance policy']),
                Select::make('punch_mode')->label('Punch type')->options(AttendancePolicyFilters::punchModes()),
                Textarea::make('codes')->label('Only these employee codes')->rows(2)->helperText('Paste codes separated by commas, spaces or new lines.'),
                Textarea::make('exclude_codes')->label('Leave out these employee codes')->rows(2),
            ])
            ->action(function (array $data) {
                try {
                    [$year, $month] = $this->period();
                    [$start, $end] = app(AttendanceMonthCloses::class)->window((int) $this->companyId(), $year, $month);
                    $employees = app(AttendanceMonthPopulation::class)->filtered((int) $this->companyId(), $start, $end, $data);
                    $run = app(AttendanceMonthRuns::class)->start((int) $this->companyId(), $year, $month, $employees, auth()->user(), $this->filterLabels($data));
                    Notification::make()->success()->title('Your request to process in background has been submitted')
                        ->body("{$run->total} employee(s) queued for {$run->label()}. Once processed, you will be notified in the app and by e-mail.")->persistent()->send();
                    $this->afterChange();
                } catch (RuntimeException $e) {
                    Notification::make()->danger()->title('Cannot process')->body($e->getMessage())->persistent()->send();
                }
            });
    }

    // --- Close and reopen (TIM-1) -----------------------------------------------------------------------------------

    public function closeMonthAction(): Action
    {
        return Action::make('closeMonth')->label('Close month')->icon(Heroicon::OutlinedLockClosed)->color('success')
            ->authorize(fn () => $this->canProcess())
            ->visible(fn () => app(AttendanceMonthCloses::class)->enabled())
            ->requiresConfirmation()
            ->modalHeading(fn () => 'Close attendance · '.$this->monthLabel())
            ->modalDescription('Requests, corrections, overrides and uploads on the month\'s days stop (with corrections after lock allowed they become arrears), and the days keep their values. Payroll then reads a closed month. You can reopen it until a finalized payroll covers it.')
            ->schema([Textarea::make('note')->label('Note (optional)')->maxLength(255)])
            ->action(function (array $data) {
                try {
                    [$year, $month] = $this->period();
                    $row = app(AttendanceMonthCloses::class)->close((int) $this->companyId(), $year, $month, auth()->user(), $data['note'] ?? null);
                    Notification::make()->success()->title("{$row->label()} closed")->send();
                    $this->afterChange();
                } catch (RuntimeException $e) {
                    Notification::make()->danger()->title('Cannot close the month')->body($e->getMessage())->persistent()->send();
                }
            });
    }

    public function reopenMonthAction(): Action
    {
        return Action::make('reopenMonth')->label('Reopen month')->icon(Heroicon::OutlinedLockOpen)->color('danger')
            ->authorize(fn () => $this->canProcess())
            ->visible(fn () => app(AttendanceMonthCloses::class)->enabled())
            ->requiresConfirmation()
            ->modalHeading(fn () => 'Reopen attendance · '.$this->monthLabel())
            ->modalDescription('Requests and corrections on the month\'s days are possible again; arrears recorded while it was closed are voided and those days recalculated. Not possible once a finalized payroll covers the month.')
            ->schema([Textarea::make('reason')->label('Reason')->required()->maxLength(255)])
            ->action(function (array $data) {
                try {
                    [$year, $month] = $this->period();
                    $row = app(AttendanceMonthCloses::class)->reopen((int) $this->companyId(), $year, $month, auth()->user(), (string) $data['reason']);
                    Notification::make()->success()->title("{$row->label()} reopened")->send();
                    $this->afterChange();
                } catch (RuntimeException $e) {
                    Notification::make()->danger()->title('Cannot reopen the month')->body($e->getMessage())->persistent()->send();
                }
            });
    }

    // --- Step 2: arrears --------------------------------------------------------------------------------------------

    public function holdArrearsAction(): Action
    {
        return $this->arrearAction('holdArrears', true);
    }

    public function releaseArrearsAction(): Action
    {
        return $this->arrearAction('releaseArrears', false);
    }

    private function arrearAction(string $name, bool $hold): Action
    {
        return Action::make($name)->label($hold ? 'Hold' : 'Release')->size('sm')->color($hold ? 'warning' : 'success')
            ->authorize(fn () => $this->canWorkArrears())
            ->modalHeading(fn (array $arguments) => ($hold ? 'Hold' : 'Release').' arrears for '.($this->arrearGroup($arguments)['label'] ?? 'the month'))
            ->modalDescription($hold ? 'Pending arrears of this month are left out of the next payroll until you release them.' : 'Held arrears of this month are pending again and taken by the next payroll.')
            ->schema([Textarea::make('reason')->label('Reason')->required()->maxLength(255)])
            ->action(function (array $data, array $arguments) use ($hold) {
                try {
                    $group = $this->arrearGroup($arguments);
                    if ($group === null) {
                        throw new RuntimeException('Nothing to move for that month.');
                    }
                    [$year, $month] = $this->period();
                    [$start, $end] = app(AttendanceMonthCloses::class)->window((int) $this->companyId(), $year, $month);
                    $ids = app(AttendanceMonthPopulation::class)->query((int) $this->companyId(), $start, $end)->pluck('id')->all();
                    $moved = app(AttendanceArrears::class)->moveWindow($ids, Carbon::parse($group['start']), Carbon::parse($group['end'])->min(Carbon::parse($start)->subDay()), $hold, auth()->user(), (string) $data['reason']);
                    Notification::make()->success()->title($moved.' arrear(s) '.($hold ? 'held' : 'released'))->send();
                    $this->afterChange();
                } catch (RuntimeException $e) {
                    Notification::make()->danger()->title('Cannot move the arrears')->body($e->getMessage())->persistent()->send();
                }
            });
    }

    /** @return array<string, mixed>|null */
    private function arrearGroup(array $arguments): ?array
    {
        $this->ready = true;
        $overview = $this->overview();

        return collect($overview['arrears'] ?? [])->firstWhere('key', (string) ($arguments['month'] ?? ''));
    }

    public function arrearsUrl(string $view): string
    {
        return AttendanceArrearResource::getUrl('index', ['view' => $view]);
    }

    // --- Review one employee ----------------------------------------------------------------------------------------

    /** @return array<int, string> the month's people (in the viewer's scope) */
    public function employeeOptions(): array
    {
        if ($this->companyId() === null) {
            return [];
        }
        try {
            [$year, $month] = $this->period();
            [$start, $end] = app(AttendanceMonthCloses::class)->window($this->companyId(), $year, $month);
        } catch (RuntimeException) {
            return [];
        }

        return app(AttendanceMonthPopulation::class)->query($this->companyId(), $start, $end)->with('person')->orderBy('employee_code')->limit(2000)->get()
            ->mapWithKeys(fn (Employee $e) => [(int) $e->id => trim($e->employee_code.' · '.($e->person?->display_name ?? ''))])->all();
    }

    /** @return array<string, mixed>|null one employee's month: totals, waiting requests and arrears */
    public function review(): ?array
    {
        $employee = $this->reviewed();
        if ($employee === null) {
            return null;
        }
        [$year, $month] = $this->period();
        [$start, $end] = app(AttendanceMonthCloses::class)->window((int) $this->companyId(), $year, $month);
        $until = $end->min(CarbonImmutable::today());
        $pending = $start->lte($until) ? app(AttendanceMonthPopulation::class)->pending([(int) $employee->id], $start, $end) : [];

        return [
            'employee' => $employee,
            'summary' => $start->lte($until) ? app(AttendanceOutput::class)->summary($employee, Carbon::parse($start->toDateString()), Carbon::parse($until->toDateString())) : null,
            'pending' => array_merge(...array_values($pending ?: [[]])),
            'arrears' => AttendanceArrear::query()->where('employee_id', $employee->id)->whereIn('status', ['pending', 'held'])->orderBy('date')->get(),
            'closed_days' => count(app(AttendanceMonthCloses::class)->closedDates($employee, $start, $end)),
            // HROne parity TX-4 (the review's "Summary"): days by category, hours by kind of day, arrear days and paid days.
            'month' => app(PaidDaysRegister::class)->figures([(int) $employee->id], (int) $this->companyId(), app(PaidDaysRegister::class)->windows((int) $this->companyId(), $year, $month))[(int) $employee->id]['months'][sprintf('%04d-%02d', $year, $month)] ?? null,
            'paid_days' => PaidDays::getUrl(['company' => $this->company, 'month' => $this->month, 'detail' => $employee->id]),
            'calendar' => AttendanceCalendar::getUrl(['employee' => $employee->id, 'month' => $end->format('Y-m')]),
        ];
    }

    public function processEmployee(): void
    {
        $employee = $this->reviewed();
        if ($employee === null || ! $this->canProcess() || ! app(AccessScopes::class)->allows(auth()->user(), $employee)) {
            Notification::make()->danger()->title('Choose an employee of the month first')->send();

            return;
        }
        [$year, $month] = $this->period();
        if (app(AttendanceMonthCloses::class)->isClosed((int) $this->companyId(), $year, $month)) {
            Notification::make()->warning()->title('The month is closed')->body('Its days keep their values. Reopen the month to process it again.')->send();

            return;
        }
        [$start, $end] = app(AttendanceMonthCloses::class)->window((int) $this->companyId(), $year, $month);
        $from = $employee->joining_date ? $start->max(CarbonImmutable::parse($employee->joining_date)->startOfDay()) : $start;
        $to = collect([$end, CarbonImmutable::today(), $employee->exit_date ? CarbonImmutable::parse($employee->exit_date)->startOfDay() : null])->filter()->min();
        if ($to->lt($from)) {
            Notification::make()->warning()->title('No days of the month to process yet')->send();

            return;
        }
        $days = app(AttendanceProcessor::class)->processRange($employee, $from->toDateString(), $to->toDateString())->count();
        Notification::make()->success()->title("{$days} day(s) processed for {$employee->employee_code}")->send();
        $this->afterChange();
    }

    private function reviewed(): ?Employee
    {
        if (! filled($this->reviewEmployee) || $this->companyId() === null) {
            return null;
        }

        return array_key_exists((int) $this->reviewEmployee, $this->employeeOptions()) ? Employee::query()->with('person')->find((int) $this->reviewEmployee) : null;
    }

    // --- Helpers ----------------------------------------------------------------------------------------------------

    public function monthLabel(): string
    {
        [$year, $month] = $this->period();

        return $year > 0 && $month > 0 ? Carbon::create($year, $month, 1)->format('M Y') : '';
    }

    private function listAllowed(string $key): bool
    {
        return array_key_exists($key, AttendanceMonthDesk::LISTS) && ($this->canProcess() || ! in_array($key, ['absences', 'unprocessed'], true));
    }

    private function normaliseMonth(): void
    {
        if ($this->companyId() === null) {
            $this->month = null;

            return;
        }
        if (! array_key_exists((string) $this->month, $this->monthOptions())) {
            [$year, $month] = app(AttendanceMonthDesk::class)->currentMonth($this->companyId());
            $this->month = sprintf('%04d-%02d', $year, $month);
        }
    }

    private function afterChange(): void
    {
        $this->overviewMemo = null;
        $this->openList = null;
        $this->resetTable();
    }

    /** @return array<string, string> what narrowed a run, as labels (kept on the run) */
    private function filterLabels(array $data): array
    {
        $labels = [];
        foreach (['employee_ids' => 'Employees', 'department_ids' => 'Departments', 'location_ids' => 'Locations', 'designation_ids' => 'Designations', 'employment_type_ids' => 'Employee types'] as $key => $label) {
            if (($count = count(array_filter((array) ($data[$key] ?? [])))) > 0) {
                $labels[$key] = "{$label}: {$count}";
            }
        }
        foreach (['attendance_policy_id' => 'Attendance policy', 'punch_mode' => 'Punch type'] as $key => $label) {
            if (filled($data[$key] ?? null)) {
                $labels[$key] = $label.': '.$data[$key];
            }
        }
        foreach (['codes' => 'Codes', 'exclude_codes' => 'Codes left out'] as $key => $label) {
            if (($codes = AttendanceMonthPopulation::codes($data[$key] ?? null)) !== []) {
                $labels[$key] = $label.': '.count($codes);
            }
        }

        return $labels;
    }
}
