<?php

namespace App\Filament\Pages;

use App\Domain\Attendance\Models\Shift;
use App\Domain\Attendance\Services\AttendanceMonthPopulation;
use App\Domain\Attendance\Services\RosterGrid;
use App\Domain\Attendance\Services\ShiftRoster;
use App\Domain\Employment\Models\Employee;
use App\Domain\Experience\BulkOperations\AssignShiftOperation;
use App\Domain\Experience\BulkOperations\RemoveShiftOperation;
use App\Domain\Experience\Services\BulkOperations;
use App\Domain\Experience\Services\BulkRuns;
use App\Filament\Resources\BulkRuns\BulkRunResource;
use App\Filament\Resources\ShiftOverrides\ShiftOverrideResource;
use App\Filament\Support\EmployeeFilters;
use App\Filament\Support\Pages\Concerns\HasRegisterTools;
use App\Filament\Support\Pages\RegisterTablePage;
use App\Filament\Support\TimeOfficeRegisters;
use App\Livewire\Attendance\DayDrawer;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Wizard\Step;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Contracts\Pagination\Paginator;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\HtmlString;
use Livewire\Attributes\On;
use Livewire\Attributes\Url;
use RuntimeException;
use Throwable;
use UnitEnum;

/**
 * HROne parity TX-5 (matrix 24 §24.2.1 #1, #2, #5, #7, #9; §24.8 #8; the HR part of §24.13 #2 — Shift rosters and the
 * "Change shift" global action): a board of employees × days for a week or a month showing the shift ShiftResolver gives
 * each day (rostered day first, then the work schedule), with weekly-off, holiday and leave marks (approved and waiting,
 * read through the attendance contracts), rostered days (source, automatic shift correction), locked days and days of a
 * closed attendance month.
 *
 * Employees are chosen with WO-2's filters (pasted codes, organisation, status / type / joining, pending resignation),
 * inside the viewer's access scope (the Employee query's access scope). attendance.view opens the board; attendance.roster
 * (TX-2) edits a day ("Edit day" on a cell: a shift, a weekly off or back to the schedule) and runs "Assign shift" /
 * "Remove rostered days" on the selected employees — a shift for a date range with weekly-off weekdays and automatic shift
 * correction, through ShiftRoster::assignPattern / removePattern, with a result summary that lists each refused employee
 * and why. Selections above `peopleos.bulk.confirm_over` run as a WO-1 bulk run (bulk.run, typed count).
 */
class RosterBoard extends RegisterTablePage
{
    use HasRegisterTools;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedSquares2x2;

    protected static string|UnitEnum|null $navigationGroup = 'Attendance';

    protected static ?string $navigationLabel = 'Roster board';

    protected static ?string $title = 'Roster board';

    protected static ?string $slug = 'roster-board';

    protected static ?int $navigationSort = 26;

    protected string $view = 'filament.pages.roster-board';

    public const REGISTER = 'roster_board';

    /** week | month */
    #[Url]
    public string $period = 'week';

    /** The first day shown (the week's Monday, or the month's first day), Y-m-d. */
    #[Url]
    public ?string $start = null;

    /**
     * The last direct roster change's result summary, shown above the board.
     *
     * @var array{title: string, days: int, done: list<array<string, mixed>>, refused: list<array<string, mixed>>}|null
     */
    public ?array $result = null;

    /** @var array<int, array<string, array<string, mixed>>>|null this request's cells, per employee and day */
    private ?array $cellMemo = null;

    public static function canAccess(): bool
    {
        return auth()->user()?->hasPermission('attendance.view') ?? false;
    }

    public static function registerKey(): string
    {
        return self::REGISTER;
    }

    public static function registerReports(): array
    {
        return ['day_roster_this_month'];
    }

    public function getSubheading(): ?string
    {
        return 'Who works which shift, day by day: the rostered day first, then the work schedule.'.($this->canRoster() ? ' Click a day to change it, or select employees to assign a shift for a range.' : '');
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);
        $this->normalise();
    }

    public function canRoster(): bool
    {
        return auth()->user()?->hasPermission('attendance.roster') ?? false;
    }

    // --- The window ---------------------------------------------------------------------------------------------------

    /** @return array{0: Carbon, 1: Carbon} the first and the last day shown */
    public function window(): array
    {
        $from = Carbon::parse((string) $this->start)->startOfDay();

        return [$from, $this->period === 'month' ? $from->copy()->endOfMonth()->startOfDay() : $from->copy()->addDays(6)];
    }

    /** @return list<Carbon> */
    public function days(): array
    {
        [$from, $to] = $this->window();
        $days = [];
        for ($day = $from->copy(); $day->lte($to); $day->addDay()) {
            $days[] = $day->copy();
        }

        return $days;
    }

    public function periodLabel(): string
    {
        [$from, $to] = $this->window();

        return $this->period === 'month' ? $from->format('F Y') : 'Week of '.$from->format('j M Y').' · '.$from->format('j M').' – '.$to->format('j M');
    }

    public function previous(): void
    {
        $this->start = Carbon::parse((string) $this->start)->{$this->period === 'month' ? 'subMonthNoOverflow' : 'subWeek'}()->toDateString();
        $this->afterWindowChange();
    }

    public function next(): void
    {
        $this->start = Carbon::parse((string) $this->start)->{$this->period === 'month' ? 'addMonthNoOverflow' : 'addWeek'}()->toDateString();
        $this->afterWindowChange();
    }

    public function today(): void
    {
        $this->start = now()->toDateString();
        $this->afterWindowChange();
    }

    public function updatedPeriod(): void
    {
        $this->afterWindowChange();
    }

    public function updatedStart(): void
    {
        $this->afterWindowChange();
    }

    private function normalise(): void
    {
        $this->period = $this->period === 'month' ? 'month' : 'week';
        try {
            $start = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $this->start) === 1 ? Carbon::parse((string) $this->start) : now();
        } catch (Throwable) {
            $start = now();
        }
        $this->start = ($this->period === 'month' ? $start->copy()->startOfMonth() : $start->copy()->startOfWeek(Carbon::MONDAY))->toDateString();
    }

    private function afterWindowChange(): void
    {
        $this->normalise();
        $this->cellMemo = null;
        $this->rebuildRegisterTable();
        $this->resetPage();
    }

    // --- The board ----------------------------------------------------------------------------------------------------

    public function table(Table $table): Table
    {
        $columns = [
            TextColumn::make('employee_code')->label('Code')->sortable(),
            TextColumn::make('person.display_name')->label('Employee')->searchable(query: EmployeeFilters::search())->wrap(),
        ];
        foreach ($this->days() as $day) {
            $date = $day->toDateString();
            $columns[] = TextColumn::make('d'.$day->format('Ymd'))->label($day->format('D j'))->alignCenter()
                ->state(fn (Employee $record): Htmlable => $this->cellHtml($record, $date));
        }

        return $table
            ->query(fn (): Builder => $this->population())
            ->modifyQueryUsing(fn (Builder $query) => $query->with('person'))
            ->heading(fn () => $this->periodLabel())
            ->description('WO weekly off · H holiday · L leave (L? waiting for a decision) · A automatic shift correction · a highlighted day is rostered (it overrides the schedule) · struck-through days are locked or in a closed attendance month.')
            ->columns($columns)
            ->filters([
                EmployeeFilters::codes(),
                EmployeeFilters::organisation(),
                EmployeeFilters::workforce(),
                EmployeeFilters::excludePendingResignation(),
            ])
            ->recordActions([
                Action::make('calendar')->label('Calendar')->icon(Heroicon::OutlinedCalendar)->color('gray')
                    ->visible(fn () => AttendanceCalendar::canAccess())
                    ->url(fn (Employee $record) => AttendanceCalendar::getUrl(['employee' => $record->id, 'month' => $this->window()[0]->format('Y-m')])),
            ])
            ->toolbarActions([$this->assignShiftAction(), $this->removeShiftAction()])
            ->defaultSort('employee_code')
            ->paginated([10, 25, 50]);
    }

    /** The employees employed on some day of the window, inside the viewer's access scope. */
    private function population(): Builder
    {
        [$from, $to] = $this->window();

        return Employee::query()
            ->whereIn('lifecycle_state', AttendanceMonthPopulation::states())
            ->where(fn (Builder $q) => $q->whereNull('joining_date')->orWhere('joining_date', '<=', $to->toDateString().' 23:59:59'))
            ->where(fn (Builder $q) => $q->whereNull('exit_date')->orWhere('exit_date', '>=', $from->toDateString()));
    }

    /** @return array<string, mixed> the day's cell for an employee on the page (worked out for the whole page at once) */
    public function cell(Employee $employee, string $date): array
    {
        $id = (int) $employee->id;
        if ($this->cellMemo === null || ! array_key_exists($id, $this->cellMemo)) {
            $records = $this->getTableRecords();
            $employees = collect($records instanceof Paginator ? $records->items() : $records)->push($employee)->keyBy(fn (Employee $e) => (int) $e->id)->values();
            [$from, $to] = $this->window();
            $this->cellMemo = ($this->cellMemo ?? []) + app(RosterGrid::class)->cells($employees, $from, $to);
        }

        return $this->cellMemo[$id][$date] ?? [];
    }

    /** The cell: the shift (or WO), its marks and — for attendance.roster — a button opening "Edit day". */
    public function cellHtml(Employee $employee, string $date): Htmlable
    {
        $cell = $this->cell($employee, $date);
        if ($cell === []) {
            return new HtmlString('');
        }
        $label = ! $cell['active'] ? '·' : ($cell['weekly_off'] ? 'WO' : ($cell['code'] ?? '—'));
        $marks = array_filter([
            $cell['holiday'] !== null ? ['H', 'text-success-600', 'Holiday: '.$cell['holiday']] : null,
            $cell['leave'] !== null ? ['L', 'text-warning-600', 'Leave: '.$cell['leave'].($cell['leave_paid'] === false ? ' (unpaid)' : '')] : null,
            $cell['leave'] === null && $cell['leave_pending'] !== null ? ['L?', 'text-gray-500', 'Leave waiting for a decision: '.$cell['leave_pending']] : null,
            $cell['auto_shift'] || $cell['schedule_auto'] ? ['A', 'text-primary-600', 'Automatic shift correction'.($cell['auto_shift'] ? ' (rostered)' : ' (work schedule)')] : null,
        ]);
        $title = array_values(array_filter([
            Carbon::parse($date)->format('D j M Y'),
            ! $cell['active'] ? 'Not employed on this day' : null,
            $cell['weekly_off'] ? 'Weekly off' : ($cell['name'] !== null ? $cell['name'].' ('.$cell['code'].($cell['times'] ? ', '.$cell['times'] : '').')' : 'No shift: no work schedule'),
            $cell['rostered'] ? 'Rostered ('.$cell['source'].($cell['roster_reason'] ? ': '.$cell['roster_reason'] : '').')' : ($cell['schedule'] ? 'Work schedule: '.$cell['schedule'] : null),
            $cell['processed_shift'] !== null ? 'Processed on '.$cell['processed_shift'] : null,
            $cell['status'] !== null ? 'Attendance: '.str_replace('_', ' ', (string) $cell['status']) : null,
            $cell['locked'] ? 'Locked (finalized)' : null,
            $cell['closed'] ? 'In a closed attendance month' : null,
            ...array_map(fn (array $m) => $m[2], $marks),
        ]));
        $classes = 'inline-flex flex-col items-center gap-1 rounded px-2 py-1 text-xs whitespace-nowrap tabular-nums';
        $classes .= ! $cell['active'] ? ' text-gray-400' : ($cell['rostered'] ? ' bg-primary-50 text-primary-700 ring-1 ring-primary-500 font-semibold' : ($cell['weekly_off'] ? ' bg-gray-100 text-gray-500' : ''));
        $classes .= $cell['locked'] || $cell['closed'] ? ' line-through' : '';
        $inner = '<span>'.e($label).'</span>'.($marks === [] ? '' : '<span class="inline-flex gap-1">'.implode('', array_map(fn (array $m) => '<span class="'.$m[1].'">'.e($m[0]).'</span>', $marks)).'</span>');
        $attributes = 'data-cell="'.e($employee->id.'|'.$date).'" data-code="'.e($label).'" title="'.e(implode(' · ', $title)).'"';
        if ($this->canRoster() && $cell['active']) {
            $call = e("mountAction('editCell', ".json_encode(['employee' => (int) $employee->id, 'date' => $date]).')');

            return new HtmlString('<button type="button" class="'.$classes.' cursor-pointer" wire:click="'.$call.'" '.$attributes.'>'.$inner.'</button>'.$this->dayLink($employee, $date, $cell));
        }

        return new HtmlString('<span class="'.$classes.'" '.$attributes.'>'.$inner.'</span>'.$this->dayLink($employee, $date, $cell));
    }

    /**
     * HROne parity TX-7 (§24.5.2 #3): a small link under an employed day opening the HR day drawer (punches, leave, overrides,
     * reprocess) for viewers of attendance (attendance.view).
     *
     * @param  array<string, mixed>  $cell
     */
    private function dayLink(Employee $employee, string $date, array $cell): string
    {
        if (! $cell['active'] || ! DayDrawer::available()) {
            return '';
        }
        $open = e("\$dispatch('pos-day-drawer-open', ".json_encode(['employee' => (int) $employee->id, 'date' => $date]).')');

        return '<button type="button" class="block text-xs text-primary-600 hover:underline" wire:click="'.$open.'" data-open-day="'.e($employee->id.'|'.$date).'" aria-label="'.e('Day details · '.Carbon::parse($date)->format('D j M Y')).'" title="Day details">Day</button>';
    }

    /** HROne parity TX-7: a change in the day drawer redraws the board. */
    #[On('pos-attendance-day-changed')]
    public function dayChanged(): void
    {
        $this->cellMemo = null;
    }

    protected function getHeaderActions(): array
    {
        return [
            ...$this->registerToolActions(),
            TimeOfficeRegisters::uploads(['shift_roster']),
            Action::make('shiftRoster')->label('Rostered days list')->icon(Heroicon::OutlinedTableCells)->color('gray')
                ->visible(fn () => ShiftOverrideResource::canViewAny())
                ->url(fn () => ShiftOverrideResource::getUrl('index')),
        ];
    }

    // --- Edit a day ---------------------------------------------------------------------------------------------------

    public function editCellAction(): Action
    {
        return Action::make('editCell')->label('Edit day')->icon(Heroicon::OutlinedPencilSquare)
            ->authorize(fn () => $this->canRoster())
            ->modalHeading(fn (array $arguments) => $this->editHeading($arguments))
            ->modalDescription(fn (array $arguments) => $this->editDescription($arguments))
            ->modalSubmitActionLabel('Save')
            ->fillForm(fn (array $arguments) => $this->editDefaults($arguments))
            ->schema([
                Radio::make('what')->label('This day')->required()->live()->options([
                    'shift' => 'Work a shift',
                    'weekly_off' => 'Weekly off',
                    'schedule' => 'Back to the work schedule (remove the rostered day)',
                ]),
                Select::make('shift_id')->label('Shift')->native(false)->searchable()
                    ->options(fn () => Shift::query()->orderBy('name')->get()->mapWithKeys(fn (Shift $s) => [$s->id => $s->name.' ('.$s->code.')'])->all())
                    ->visible(fn (Get $get) => $get('what') === 'shift')->required(fn (Get $get) => $get('what') === 'shift'),
                Toggle::make('auto_shift')->label('Allow automatic shift correction on this day')
                    ->helperText('The first check-in picks the nearest shift among the rostered one and those the work schedule lists for automatic correction.')
                    ->visible(fn (Get $get) => $get('what') !== 'schedule'),
                Textarea::make('reason')->label('Reason')->required()->maxLength(255),
            ])
            ->action(function (array $data, array $arguments) {
                $employee = $this->employeeFor($arguments);
                $date = $this->dateFor($arguments);
                if ($employee === null || $date === null) {
                    Notification::make()->danger()->title('Not changed')->body('Outside your access scope.')->send();

                    return;
                }
                $roster = app(ShiftRoster::class);
                try {
                    $result = ($data['what'] ?? null) === 'schedule'
                        ? $roster->removePattern([$employee], $date, $date, (string) ($data['reason'] ?? ''), auth()->user())
                        : $roster->assignPattern([$employee], $date, $date, ($data['what'] ?? null) === 'shift' ? Shift::query()->find((int) ($data['shift_id'] ?? 0)) : null, [],
                            (bool) ($data['auto_shift'] ?? false), (string) ($data['reason'] ?? ''), auth()->user());
                } catch (RuntimeException $e) {
                    Notification::make()->danger()->title('Not changed')->body($e->getMessage())->send();

                    return;
                }
                $this->showResult('Edit '.$employee->employee_code.' · '.Carbon::parse($date)->format('j M Y'), $result);
            });
    }

    /** @param  array<string, mixed>  $arguments */
    private function employeeFor(array $arguments): ?Employee
    {
        // The Employee query's access scope applies: an employee outside it is not found.
        return Employee::query()->with('person')->find((int) ($arguments['employee'] ?? 0));
    }

    /** @param  array<string, mixed>  $arguments */
    private function dateFor(array $arguments): ?string
    {
        $date = (string) ($arguments['date'] ?? '');

        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) === 1 && Carbon::hasFormat($date, 'Y-m-d') ? $date : null;
    }

    /** @param  array<string, mixed>  $arguments */
    private function editHeading(array $arguments): string
    {
        $employee = $this->employeeFor($arguments);
        $date = $this->dateFor($arguments);

        return $employee === null || $date === null ? 'Edit day' : 'Edit day · '.$employee->auditLabel().' · '.Carbon::parse($date)->format('D j M Y');
    }

    /** @param  array<string, mixed>  $arguments */
    private function editDescription(array $arguments): ?string
    {
        $employee = $this->employeeFor($arguments);
        $date = $this->dateFor($arguments);
        if ($employee === null || $date === null) {
            return 'Outside your access scope.';
        }
        $cell = app(RosterGrid::class)->cells([$employee], Carbon::parse($date), Carbon::parse($date))[(int) $employee->id][$date] ?? [];
        if ($cell === []) {
            return null;
        }
        $now = $cell['weekly_off'] ? 'a weekly off' : ($cell['name'] !== null ? $cell['name'].' ('.$cell['code'].')' : 'no shift');

        return 'Now: '.$now.($cell['rostered'] ? ', rostered ('.$cell['source'].')' : ($cell['schedule'] ? ', from the work schedule '.$cell['schedule'] : ''))
            .($cell['holiday'] ? ' · holiday '.$cell['holiday'] : '').($cell['leave'] ? ' · leave '.$cell['leave'] : '')
            .($cell['locked'] ? ' · locked' : '').($cell['closed'] ? ' · in a closed attendance month' : '').'. Past days are recalculated when saved.';
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    private function editDefaults(array $arguments): array
    {
        $employee = $this->employeeFor($arguments);
        $date = $this->dateFor($arguments);
        if ($employee === null || $date === null) {
            return ['what' => 'shift'];
        }
        $cell = app(RosterGrid::class)->cells([$employee], Carbon::parse($date), Carbon::parse($date))[(int) $employee->id][$date] ?? [];

        return ['what' => ($cell['weekly_off'] ?? false) ? 'weekly_off' : 'shift', 'shift_id' => $cell['shift_id'] ?? null, 'auto_shift' => (bool) ($cell['auto_shift'] ?? false)];
    }

    // --- Assign shift / remove rostered days for the selected employees -------------------------------------------------

    private function assignShiftAction(): BulkAction
    {
        return BulkAction::make('assignShift')->label('Assign shift')->icon(Heroicon::OutlinedCalendarDays)->color('primary')
            ->visible(fn () => $this->canRoster())
            ->deselectRecordsAfterCompletion()
            ->modalHeading(fn (BulkAction $action) => 'Assign shift · '.count($action->getSelectedRecords()).' employee(s)')
            ->modalSubmitActionLabel('Assign')
            ->steps(function (BulkAction $action) {
                [$from, $to] = $this->window();
                $count = count($action->getSelectedRecords());

                return [
                    Step::make('Shift')->description('The days, the shift and the weekly offs')->schema(AssignShiftOperation::fields($from->toDateString(), $to->toDateString())),
                    Step::make('Review')->description('What happens, and why')->schema([
                        Text::make(fn (Get $get) => $this->assignSummary($count, $get)),
                        ...$this->confirmFields($count),
                    ]),
                ];
            })
            ->action(fn (BulkAction $action, array $data) => $this->runOnSelected('assign', collect($action->getSelectedRecords()), $data));
    }

    private function removeShiftAction(): BulkAction
    {
        return BulkAction::make('removeShift')->label('Remove rostered days')->icon(Heroicon::OutlinedArrowUturnLeft)->color('danger')
            ->visible(fn () => $this->canRoster())
            ->deselectRecordsAfterCompletion()
            ->modalHeading(fn (BulkAction $action) => 'Remove rostered days · '.count($action->getSelectedRecords()).' employee(s)')
            ->modalSubmitActionLabel('Remove')
            ->steps(function (BulkAction $action) {
                [$from, $to] = $this->window();
                $count = count($action->getSelectedRecords());

                return [
                    Step::make('Days')->description('The rostered days to remove')->schema([
                        DatePicker::make('from')->label('From')->native(false)->required()->default($from->toDateString()),
                        DatePicker::make('to')->label('To')->native(false)->required()->afterOrEqual('from')->default($to->toDateString()),
                    ]),
                    Step::make('Review')->description('What happens, and why')->schema([
                        Text::make(fn (Get $get) => 'Removes the rostered days of '.$count.' employee(s) from '.$this->dateLabel($get('from')).' to '.$this->dateLabel($get('to'))
                            .': those days follow the work schedule again and past days are recalculated. '.$this->runNote($count)),
                        ...$this->confirmFields($count),
                    ]),
                ];
            })
            ->action(fn (BulkAction $action, array $data) => $this->runOnSelected('remove', collect($action->getSelectedRecords()), $data));
    }

    /** @return list<mixed> the reason, and the typed count when the selection runs as a bulk run */
    private function confirmFields(int $count): array
    {
        $typed = $count > BulkRuns::confirmOver();

        return [
            Textarea::make('reason')->label('Reason')->required()->maxLength(255),
            TextInput::make('confirm_count')->label('Type '.$count.' to confirm')->visible($typed)->required($typed)
                ->in([(string) $count])->validationMessages(['in' => 'Type exactly '.$count.'.']),
        ];
    }

    private function assignSummary(int $count, Get $get): string
    {
        $shift = filled($get('shift_id')) ? Shift::query()->find((int) $get('shift_id')) : null;
        $off = array_values(array_intersect(array_keys(ShiftRoster::WEEKDAYS), (array) ($get('weekly_off_days') ?? [])));
        $times = RosterGrid::times($shift);
        $pattern = $shift === null
            ? 'every day a weekly off'
            : $shift->name.' ('.$shift->code.($times !== null ? ', '.$times : '').')'.($off === [] ? ' on every day' : ', weekly off on '.implode(', ', array_map(fn ($d) => ShiftRoster::WEEKDAYS[$d], $off)));

        return 'Rosters '.$count.' employee(s) from '.$this->dateLabel($get('from')).' to '.$this->dateLabel($get('to')).': '.$pattern
            .((bool) $get('auto_shift') ? '; the first check-in may correct the shift' : '').'. Past days are recalculated; employees outside your access, not employed then, or with locked days or days of a closed attendance month are refused and listed. '
            .$this->runNote($count);
    }

    private function runNote(int $count): string
    {
        if ($count <= BulkRuns::confirmOver()) {
            return 'It runs now and the result is shown above the board.';
        }

        return app(BulkOperations::class)->allows(auth()->user(), AssignShiftOperation::KEY)
            ? 'More than '.BulkRuns::confirmOver().' employees: it runs as a bulk run in the background (People → Bulk runs lists each employee).'
            : 'More than '.BulkRuns::confirmOver().' employees need the bulk run right (bulk.run): select fewer employees.';
    }

    private function dateLabel(mixed $date): string
    {
        try {
            return filled($date) ? Carbon::parse((string) $date)->format('j M Y') : '…';
        } catch (Throwable) {
            return '…';
        }
    }

    /**
     * Small selections run now through ShiftRoster (result summary above the board); larger ones start a WO-1 bulk run.
     *
     * @param  Collection<int, Employee>  $employees
     * @param  array<string, mixed>  $data
     */
    public function runOnSelected(string $kind, Collection $employees, array $data): void
    {
        abort_unless($this->canRoster(), 403);
        $operation = $kind === 'remove' ? RemoveShiftOperation::KEY : AssignShiftOperation::KEY;
        $confirmed = isset($data['confirm_count']) && is_numeric($data['confirm_count']) ? (int) $data['confirm_count'] : null;
        unset($data['confirm_count']);

        if ($employees->count() > BulkRuns::confirmOver()) {
            try {
                $run = app(BulkRuns::class)->start(auth()->user(), self::REGISTER, $operation, $employees->map(fn (Employee $e) => $e->getKey())->all(), $data, 'selected', $confirmed);
            } catch (RuntimeException $e) {
                Notification::make()->danger()->title('Not started')->body($e->getMessage())->send();

                return;
            }
            $run->refresh();
            Notification::make()->status($run->status === 'failed' ? 'danger' : ($run->refused + $run->failed > 0 ? 'warning' : 'success'))
                ->title($run->operation_label.': '.($run->isFinished() ? $run->summary() : 'queued for '.$run->total.' employee(s)'))
                ->body($run->isFinished() && $run->refused + $run->failed > 0 ? 'Some employees were not changed; the run lists each one with the reason.' : null)
                ->actions([Action::make('open')->label('See each employee')->url(BulkRunResource::getUrl('view', ['record' => $run]))])->send();
            $this->cellMemo = null;

            return;
        }

        try {
            $input = app(BulkOperations::class)->get($operation)->prepare($data, auth()->user());
            $roster = app(ShiftRoster::class);
            $result = $kind === 'remove'
                ? $roster->removePattern($employees, $input['from'], $input['to'], $input['reason'], auth()->user())
                : $roster->assignPattern($employees, $input['from'], $input['to'], $input['shift_id'] !== null ? Shift::query()->find($input['shift_id']) : null,
                    $input['weekly_off_days'], $input['auto_shift'], $input['reason'], auth()->user());
        } catch (RuntimeException $e) {
            Notification::make()->danger()->title('Not changed')->body($e->getMessage())->send();

            return;
        }
        $this->showResult(($kind === 'remove' ? 'Remove rostered days ' : 'Assign shift ').$this->dateLabel($input['from']).' – '.$this->dateLabel($input['to']), $result);
    }

    /** @param  array{days: int, done: list<array<string, mixed>>, refused: list<array<string, mixed>>}  $result */
    private function showResult(string $title, array $result): void
    {
        $this->result = ['title' => $title] + $result;
        $this->cellMemo = null;
        $refused = count($result['refused']);
        Notification::make()->status($refused === 0 ? 'success' : (count($result['done']) === 0 ? 'danger' : 'warning'))
            ->title($title)
            ->body(count($result['done']).' employee(s) done, '.$refused.' refused'.($refused > 0 ? ': '.$result['refused'][0]['label'].' — '.$result['refused'][0]['reason'] : '.'))
            ->send();
    }

    public function clearResult(): void
    {
        $this->result = null;
    }
}
