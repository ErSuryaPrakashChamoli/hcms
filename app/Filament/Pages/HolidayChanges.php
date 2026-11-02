<?php

namespace App\Filament\Pages;

use App\Domain\Attendance\Models\EmployeeHolidayChange;
use App\Domain\Attendance\Models\Holiday;
use App\Domain\Attendance\Models\HolidayLibraryEntry;
use App\Domain\Attendance\Models\HolidayLibraryList;
use App\Domain\Attendance\Services\AttendanceMonthPopulation;
use App\Domain\Attendance\Services\EmployeeHolidayChanges;
use App\Domain\Attendance\Services\HolidayLibrary;
use App\Domain\Employment\Models\Employee;
use App\Domain\Experience\BulkOperations\ChangeHolidayOperation;
use App\Domain\Experience\Services\BulkOperations;
use App\Domain\Experience\Services\BulkRuns;
use App\Domain\Identity\Models\User;
use App\Domain\Lifecycle\Enums\LifecycleState;
use App\Domain\Organisation\Models\Company;
use App\Domain\Organisation\Models\EmploymentType;
use App\Filament\Resources\BulkRuns\BulkRunResource;
use App\Filament\Resources\HolidayCalendars\HolidayCalendarResource;
use App\Filament\Support\EmployeeFilters;
use App\Filament\Support\Pages\Concerns\HasRegisterTools;
use App\Filament\Support\Pages\RegisterTablePage;
use App\Support\Geography\Countries;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Components\Wizard\Step;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\HtmlString;
use RuntimeException;
use UnitEnum;

/**
 * HROne parity TX-12 (matrix 24 §24.7.2 — global action "Change holiday" / HR Desk Action → Time office → "Manage holiday",
 * `assignholiday_1160`; §24.8 #10; the team version §24.7.2 #7 / §24.13 #5 behind `team.holiday_assign`): the holiday
 * changes made for employees on top of their holiday calendars, with filters and "Undo", and "Change holiday" — a step
 * wizard in HROne's order: Preference (assign, remove or replace holiday, and the holiday to remove or replace) →
 * Employees (pasted codes, search, organisation, status, type) → Holiday (restricted or not, name and date — typed, or
 * picked from a holiday calendar or the holiday library; the back-dating note) → Review (reason, typed count). Up to
 * `peopleos.bulk.confirm_over` employees change at once through EmployeeHolidayChanges::apply and the page lists each one
 * done or refused; larger selections run as a WO-1 bulk run (bulk.run). `?action=changeHoliday` opens the wizard.
 * attendance.view with attendance.holiday_change (attendance.manage also grants it) — or, with the team option on, a
 * manager for the people who report to them.
 */
class HolidayChanges extends RegisterTablePage
{
    use HasRegisterTools;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCalendarDateRange;

    protected static string|UnitEnum|null $navigationGroup = 'Attendance';

    protected static ?string $navigationLabel = 'Holiday changes';

    protected static ?string $title = 'Holiday changes';

    protected static ?string $slug = 'holiday-changes';

    protected static ?int $navigationSort = 62;

    protected string $view = 'filament.pages.holiday-changes';

    public const REGISTER = 'holiday_changes';

    /** Employees step: the organisation filters (WO-2's dimensions on the current position). */
    private const FORM_KEYS = ['action', 'remove_holiday_id', 'source', 'holiday_id', 'library_entry_id', 'restricted', 'name', 'date', 'reason'];

    /**
     * The last direct change's result, shown above the list.
     *
     * @var array{title: string, done: list<array<string, mixed>>, refused: list<array<string, mixed>>}|null
     */
    public ?array $result = null;

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user !== null && $user->hasPermission('attendance.view') && app(EmployeeHolidayChanges::class)->mayUse($user);
    }

    public static function registerKey(): string
    {
        return self::REGISTER;
    }

    public function getSubheading(): ?string
    {
        return 'Assign, remove or replace a holiday for chosen employees, on top of their holiday calendar. Each change is listed here and can be undone.';
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);
    }

    private static function service(): EmployeeHolidayChanges
    {
        return app(EmployeeHolidayChanges::class);
    }

    // --- the changes made ---------------------------------------------------------------------------------------------

    public function table(Table $table): Table
    {
        return $table
            ->query(fn (): Builder => EmployeeHolidayChange::query()
                ->when(! self::service()->isHr(auth()->user()), fn (Builder $q) => $q->whereIn('employee_id', self::service()->teamQuery(auth()->user())->select('employees.id'))))
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['employee.person', 'creator', 'undoer']))
            ->columns([
                TextColumn::make('date')->label('Day')->date('D, d M Y')->sortable(),
                TextColumn::make('employee.employee_code')->label('Code')->toggleable(),
                TextColumn::make('employee.person.display_name')->label('Employee')->searchable(query: EmployeeFilters::search('employee'))->wrap(),
                TextColumn::make('effect')->label('Change')->badge()->color(fn (string $state) => $state === 'add' ? 'success' : 'warning')
                    ->formatStateUsing(fn (string $state) => EmployeeHolidayChange::EFFECTS[$state] ?? $state),
                TextColumn::make('name')->label('Holiday')->wrap()->description(fn (EmployeeHolidayChange $record) => $record->typeLabel()),
                TextColumn::make('action')->label('Preference')->formatStateUsing(fn (string $state) => EmployeeHolidayChange::ACTIONS[$state] ?? $state)->toggleable(),
                TextColumn::make('source')->label('From')->formatStateUsing(fn (string $state) => EmployeeHolidayChange::SOURCES[$state] ?? $state)->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('reason')->label('Reason')->limit(60)->tooltip(fn (EmployeeHolidayChange $record) => $record->reason)->toggleable(),
                TextColumn::make('creator.name')->label('Changed by')->placeholder('—')->toggleable(),
                TextColumn::make('created_at')->label('Changed on')->dateTime('d M Y, H:i')->sortable()->toggleable(),
                TextColumn::make('status')->label('Status')->badge()->color(fn (string $state) => $state === 'active' ? 'success' : 'gray')
                    ->formatStateUsing(fn (string $state) => EmployeeHolidayChange::STATUSES[$state] ?? $state)
                    ->description(fn (EmployeeHolidayChange $record) => $record->isActive() ? null
                        : 'Undone '.$record->undone_at?->format('d M Y').($record->undoer ? ' by '.$record->undoer->name : '').': '.$record->undo_reason),
            ])
            ->filters([
                EmployeeFilters::codes('employee'),
                EmployeeFilters::organisation('employee'),
                EmployeeFilters::dateRange('days', 'Day', 'date'),
                SelectFilter::make('action')->label('Preference')->options(EmployeeHolidayChange::ACTIONS),
                SelectFilter::make('effect')->label('Change')->options(EmployeeHolidayChange::EFFECTS),
                SelectFilter::make('status')->label('Status')->options(EmployeeHolidayChange::STATUSES),
                SelectFilter::make('source')->label('From')->options(EmployeeHolidayChange::SOURCES),
            ])
            ->recordActions([
                Action::make('undo')->label('Undo')->icon(Heroicon::OutlinedArrowUturnLeft)->color('danger')
                    ->visible(fn (EmployeeHolidayChange $record) => $record->isActive() && $record->employee !== null && self::service()->refusal(auth()->user(), $record->employee) === null)
                    ->modalHeading('Undo the holiday change')
                    ->modalDescription(fn (EmployeeHolidayChange $record) => $record->employee?->auditLabel().' — '.$record->describe()
                        .($record->action === 'replace' ? '. Both days of the replace are undone together' : '').'. The holiday calendar decides the day again; days already processed are recalculated.')
                    ->modalSubmitActionLabel('Undo')
                    ->schema([Textarea::make('reason')->label('Reason')->required()->maxLength(500)])
                    ->action(function (EmployeeHolidayChange $record, array $data) {
                        try {
                            self::service()->undo($record, (string) $data['reason'], auth()->user());
                        } catch (RuntimeException $e) {
                            Notification::make()->danger()->title('Not undone')->body($e->getMessage())->send();

                            return;
                        }
                        Notification::make()->success()->title('Holiday change undone')->body($record->employee?->auditLabel().' — '.$record->describe())->send();
                    }),
                Action::make('calendar')->label('Calendar')->icon(Heroicon::OutlinedCalendar)->color('gray')
                    ->visible(fn () => AttendanceCalendar::canAccess())
                    ->url(fn (EmployeeHolidayChange $record) => AttendanceCalendar::getUrl(['employee' => $record->employee_id, 'month' => $record->date->format('Y-m')])),
            ])
            ->emptyStateHeading('No holiday changes yet')
            ->emptyStateDescription('Use "Change holiday" to assign, remove or replace a holiday for chosen employees.')
            ->defaultSort('id', 'desc')
            ->paginated([10, 25, 50, 100]);
    }

    protected function getHeaderActions(): array
    {
        return [
            $this->changeHolidayAction(),
            ...$this->registerToolActions(),
            Action::make('calendars')->label('Holiday calendars')->icon(Heroicon::OutlinedCalendarDays)->color('gray')
                ->visible(fn () => HolidayCalendarResource::canAccess())
                ->url(fn () => HolidayCalendarResource::getUrl('index')),
        ];
    }

    // --- Change holiday -----------------------------------------------------------------------------------------------

    public function changeHolidayAction(): Action
    {
        return Action::make('changeHoliday')->label('Change holiday')->icon(Heroicon::OutlinedCalendarDateRange)->color('primary')
            ->visible(fn () => static::canAccess())
            ->modalHeading('Change holiday')
            ->modalDescription('Give, take away or swap a holiday for chosen employees. Their holiday calendar stays as it is; the change applies to them only.')
            ->modalSubmitActionLabel('Change')
            ->fillForm(fn () => ['action' => 'assign', 'source' => 'typed', 'restricted' => false, 'everyone' => false])
            ->steps(fn () => [
                Step::make('Preference')->description('Assign, remove or replace holiday')->schema(self::preferenceFields()),
                Step::make('Employees')->description('Codes, search or filters')->schema(self::employeeFields()),
                Step::make('Holiday')->description('The holiday to give')->schema(self::holidayFields()),
                Step::make('Review')->description('What happens, and why')->schema([
                    Text::make(fn (Get $get) => $this->summary($get))->columnSpanFull(),
                    Textarea::make('reason')->label('Reason')->required()->maxLength(500),
                    TextInput::make('confirm_count')
                        ->label(fn (Get $get) => 'Type '.($this->countFor($get) ?? 0).' to confirm')
                        ->visible(fn (Get $get) => BulkRuns::needsTypedCount((int) $this->countFor($get)))
                        ->required(fn (Get $get) => BulkRuns::needsTypedCount((int) $this->countFor($get)))
                        ->in(fn (Get $get) => [(string) $this->countFor($get)])->validationMessages(['in' => 'Type exactly the number of employees.']),
                ]),
            ])
            ->action(fn (array $data) => $this->runChange($data));
    }

    /** @return list<mixed> */
    private static function preferenceFields(): array
    {
        $removes = fn (Get $get) => in_array($get('action'), ['remove', 'replace'], true);

        return [
            Radio::make('action')->label('Choose your preference')->options(EmployeeHolidayChange::ACTIONS)->default('assign')->required()->live()->inline(),
            Select::make('remove_holiday_id')->label(fn (Get $get) => $get('action') === 'replace' ? 'Holiday to replace' : 'Holiday to remove')
                ->options(fn () => self::calendarHolidayOptions())->searchable()->native(false)
                ->visible($removes)->required($removes)
                ->helperText(fn () => 'Holidays of your holiday calendars from '.self::service()->earliestDate()->format('d M Y').'. Each employee loses the holiday their own calendar gives on that day.'),
        ];
    }

    /** @return list<mixed> */
    private static function employeeFields(): array
    {
        $organisation = [];
        foreach (EmployeeFilters::ORGANISATION as $field => [$label, $model]) {
            $organisation[] = Select::make($field)->label($label)->multiple()->searchable()->preload()
                ->options(fn () => $model::query()->orderBy('name')->limit(1000)->pluck('name', 'id')->all());
        }

        return [
            Textarea::make('codes')->label('Add bulk employees (codes)')->rows(2)->placeholder('e.g. EMP001, EMP002')
                ->helperText('Paste up to '.EmployeeFilters::codesMax().' codes, separated by commas, spaces or new lines.')->live(onBlur: true),
            Select::make('employee_ids')->label('Search employees')->multiple()->searchable()
                ->getSearchResultsUsing(fn (string $search) => self::employeeOptions(self::population(auth()->user())->where(fn (Builder $q) => EmployeeFilters::search()($q, $search))))
                ->getOptionLabelsUsing(fn (array $values) => self::employeeOptions(self::population(auth()->user())->whereKey(array_map('intval', $values))))
                ->helperText('Search by name, code, work e-mail or designation.')->live(),
            Grid::make(2)->schema([
                ...$organisation,
                Select::make('lifecycle_state')->label('Status')->multiple()
                    ->options(collect(LifecycleState::cases())->filter(fn (LifecycleState $s) => in_array($s->value, AttendanceMonthPopulation::states(), true))
                        ->mapWithKeys(fn (LifecycleState $s) => [$s->value => $s->getLabel()])->all()),
                Select::make('employment_type_id')->label('Type')->multiple()->preload()
                    ->options(fn () => EmploymentType::query()->orderBy('name')->limit(1000)->pluck('name', 'id')->all()),
            ]),
            Toggle::make('everyone')->label('Everyone you can change (no other choice needed)')->live(),
            Text::make(fn (Get $get, $livewire) => $livewire->chosenLabel($get))->columnSpanFull(),
        ];
    }

    /** @return list<mixed> */
    private static function holidayFields(): array
    {
        $adds = fn (Get $get) => in_array($get('action'), ['assign', 'replace'], true);
        $from = fn (string $source) => fn (Get $get) => $adds($get) && $get('source') === $source;
        $service = self::service();

        return [
            Text::make(fn () => 'Note: holidays before '.$service->earliestDate()->format('d M Y').' ('.$service->backdateMonths().' month(s) before the current month) cannot be changed here. If you need to, please contact your administrator.'),
            Text::make('Removing only takes away the chosen holiday: there is nothing to give.')->visible(fn (Get $get) => $get('action') === 'remove'),
            Radio::make('source')->label('Holiday')->required($adds)->visible($adds)->live()
                ->options(fn () => array_filter([
                    'typed' => 'Type a holiday (name and date)',
                    'calendar' => 'Pick from a holiday calendar',
                    'library' => HolidayLibraryList::query()->published()->exists() ? 'Pick from the holiday library' : null,
                ])),
            Select::make('holiday_id')->label('Holiday from a holiday calendar')->options(fn () => self::calendarHolidayOptions())->searchable()->native(false)
                ->visible($from('calendar'))->required($from('calendar'))->live()
                ->afterStateUpdated(fn (Set $set, $state) => $set('restricted', Holiday::query()->find((int) $state)?->type === 'optional')),
            Select::make('library_entry_id')->label('Holiday from the holiday library')->options(fn () => self::libraryOptions())->searchable()->native(false)
                ->visible($from('library'))->required($from('library'))->live()
                ->afterStateUpdated(fn (Set $set, $state) => $set('restricted', HolidayLibraryEntry::query()->find((int) $state)?->type === 'optional')),
            Toggle::make('restricted')->label('Is this a restricted holiday?')->visible($adds)
                ->helperText('A restricted holiday given here is a day off for these employees, shown with their restricted holidays.'),
            Grid::make(2)->visible($from('typed'))->schema([
                TextInput::make('name')->label('Holiday name')->maxLength(150)->required($from('typed')),
                DatePicker::make('date')->label('Holiday date')->native(false)->required($from('typed'))
                    ->minDate(fn () => $service->earliestDate())->maxDate(fn () => $service->latestDate()),
            ]),
        ];
    }

    // --- choosing employees ---------------------------------------------------------------------------------------------

    /** Employees who can have holiday changes: employed, or left on or after the first day a change may touch. */
    public static function population(?User $user): Builder
    {
        $service = self::service();

        return Employee::query()
            ->whereIn('employees.lifecycle_state', AttendanceMonthPopulation::states())
            ->where(fn (Builder $q) => $q->whereNull('employees.exit_date')->orWhere('employees.exit_date', '>=', $service->earliestDate()->toDateString()))
            ->when($user !== null && ! $service->isHr($user), fn (Builder $q) => $q->whereIn('employees.id', $service->teamQuery($user)->select('employees.id')));
    }

    /**
     * The employees the wizard's Employees step chooses (inside the viewer's access scope; a manager's team only without the
     * HR right): pasted codes and searched employees, narrowed by the organisation, status and type filters — or everyone
     * matching the filters, or everyone on purpose.
     *
     * @param  array<string, mixed>  $data
     */
    public static function chosenQuery(array $data, ?User $user): Builder
    {
        $codes = EmployeeFilters::parseCodes($data['codes'] ?? null);
        $ids = array_values(array_filter(array_map('intval', (array) ($data['employee_ids'] ?? []))));
        $position = [];
        foreach (array_keys(EmployeeFilters::ORGANISATION) as $column) {
            $chosen = array_values(array_filter(array_map('intval', (array) ($data[$column] ?? []))));
            if ($chosen !== []) {
                $position[$column] = $column === 'department_id' ? EmployeeFilters::withSubDepartments($chosen) : $chosen;
            }
        }
        $types = array_values(array_filter(array_map('intval', (array) ($data['employment_type_id'] ?? []))));
        $states = array_values(array_intersect(AttendanceMonthPopulation::states(), (array) ($data['lifecycle_state'] ?? [])));
        if ($codes === [] && $ids === [] && $position === [] && $types === [] && $states === [] && ! ($data['everyone'] ?? false)) {
            throw new RuntimeException('Choose the employees: paste codes, search for them, use a filter, or tick "Everyone you can change".');
        }

        return self::population($user)
            ->when($codes !== [] || $ids !== [], fn (Builder $q) => $q->where(fn (Builder $w) => $w->whereIn('employees.employee_code', $codes)->orWhereIn('employees.id', $ids)))
            ->when($position !== [] || $types !== [], fn (Builder $q) => $q->whereHas('currentPosition', function (Builder $p) use ($position, $types) {
                foreach ($position as $column => $chosen) {
                    $p->whereIn($p->getModel()->qualifyColumn($column), $chosen);
                }
                if ($types !== []) {
                    $p->whereIn($p->getModel()->qualifyColumn('employment_type_id'), $types);
                }
            }))
            ->when($states !== [], fn (Builder $q) => $q->whereIn('employees.lifecycle_state', $states));
    }

    /**
     * @param  array<string, mixed>  $data
     * @return list<int> at most `bulk.max_records` + 1 ids, so an oversize choice is refused
     */
    public static function chosenIds(array $data, ?User $user): array
    {
        return self::chosenQuery($data, $user)->orderBy('employees.employee_code')->limit(BulkRuns::maxRecords() + 1)
            ->pluck('employees.id')->map(fn ($id) => (int) $id)->all();
    }

    /** The Employees step's live line: how many are chosen, and the first few. */
    public function chosenLabel(Get $get): HtmlString|string
    {
        try {
            $ids = self::chosenIds($this->formState($get), auth()->user());
        } catch (RuntimeException $e) {
            return $e->getMessage();
        }
        if ($ids === []) {
            return 'Nobody matches: change the codes, the search or the filters.';
        }
        $names = Employee::query()->with('person')->whereKey(array_slice($ids, 0, 8))->orderBy('employee_code')->get()->map(fn (Employee $e) => e($e->auditLabel()))->all();

        return new HtmlString('<strong>'.count($ids).' employee(s) selected</strong>: '.implode(', ', $names).(count($ids) > count($names) ? ' and '.(count($ids) - count($names)).' more' : '').'.');
    }

    private function countFor(Get $get): ?int
    {
        return rescue(fn () => count(self::chosenIds($this->formState($get), auth()->user())), null, false);
    }

    /** @return array<string, mixed> the wizard's answers as chosenQuery() and prepare() read them */
    private function formState(Get $get): array
    {
        $state = [];
        foreach ([...self::FORM_KEYS, 'codes', 'employee_ids', 'lifecycle_state', 'employment_type_id', 'everyone', ...array_keys(EmployeeFilters::ORGANISATION)] as $key) {
            $state[$key] = $get($key);
        }

        return $state;
    }

    private function summary(Get $get): string
    {
        $data = $this->formState($get);
        try {
            $what = EmployeeHolidayChanges::describe(self::service()->prepare($data));
        } catch (RuntimeException $e) {
            $what = '… ('.$e->getMessage().')';
        }
        $count = $this->countFor($get);

        return $what.' — for '.($count ?? 0).' employee(s), with the reason below. Their holiday calendar stays as it is; days already processed are recalculated. '
            .'Employees outside your access and your own holidays are refused; so are days not employed, locked days and days of a closed attendance month, days already changed (undo that change first), '
            .'a removal where the employee\'s calendar has no holiday that day and a new holiday on a day their calendar already gives one. '
            .$this->runNote((int) $count);
    }

    private function runNote(int $count): string
    {
        if ($count <= BulkRuns::confirmOver()) {
            return 'It runs now and the result is shown above the list.';
        }

        return app(BulkOperations::class)->allows(auth()->user(), ChangeHolidayOperation::KEY)
            ? 'More than '.BulkRuns::confirmOver().' employees: it runs as a bulk run in the background (People → Bulk runs lists each employee).'
            : 'More than '.BulkRuns::confirmOver().' employees need the bulk run right (bulk.run): choose fewer employees.';
    }

    /**
     * Small selections run now through EmployeeHolidayChanges::apply (the result shows above the list); larger ones start a
     * WO-1 bulk run.
     *
     * @param  array<string, mixed>  $data
     */
    public function runChange(array $data): void
    {
        abort_unless(static::canAccess(), 403);
        $confirmed = isset($data['confirm_count']) && is_numeric($data['confirm_count']) ? (int) $data['confirm_count'] : null;
        try {
            $ids = self::chosenIds($data, auth()->user());
            if ($ids === []) {
                throw new RuntimeException('Nobody matches: change the codes, the search or the filters.');
            }
            if (count($ids) > BulkRuns::maxRecords()) {
                throw new RuntimeException('More than '.BulkRuns::maxRecords().' employees match: narrow the choice.');
            }
            $change = array_intersect_key($data, array_flip(self::FORM_KEYS));

            if (count($ids) > BulkRuns::confirmOver()) {
                $run = app(BulkRuns::class)->start(auth()->user(), self::REGISTER, ChangeHolidayOperation::KEY, $ids, $change, 'selected', $confirmed);
                $run->refresh();
                Notification::make()->status($run->status === 'failed' ? 'danger' : ($run->refused + $run->failed > 0 ? 'warning' : 'success'))
                    ->title($run->operation_label.': '.($run->isFinished() ? $run->summary() : 'queued for '.$run->total.' employee(s)'))
                    ->body($run->isFinished() && $run->refused + $run->failed > 0 ? 'Some employees were not changed; the run lists each one with the reason.' : null)
                    ->actions([Action::make('open')->label('See each employee')->url(BulkRunResource::getUrl('view', ['record' => $run]))])->send();

                return;
            }

            $prepared = self::service()->prepare($change);
            $result = self::service()->apply(Employee::query()->with('person')->whereKey($ids)->orderBy('employee_code')->get(), $prepared, (string) ($data['reason'] ?? ''), auth()->user());
        } catch (RuntimeException $e) {
            Notification::make()->danger()->title('Not changed')->body($e->getMessage())->send();

            return;
        }

        $this->result = ['title' => EmployeeHolidayChanges::describe($prepared)] + $result;
        $refused = count($result['refused']);
        $first = $result['refused'][0] ?? null;
        Notification::make()->status($refused === 0 ? 'success' : (count($result['done']) === 0 ? 'danger' : 'warning'))
            ->title($this->result['title'])
            ->body(count($result['done']).' employee(s) changed, '.$refused.' refusal(s)'.($first !== null ? ': '.$first['label'].' — '.$first['reason'] : '.'))
            ->send();
    }

    public function clearResult(): void
    {
        $this->result = null;
    }

    // --- options --------------------------------------------------------------------------------------------------------

    /** @return array<string, string> id => "EMP001 · Asha Rao" */
    private static function employeeOptions(Builder $query): array
    {
        return $query->with('person')->orderBy('employees.employee_code')->limit(50)->get()->mapWithKeys(fn (Employee $e) => [$e->id => $e->auditLabel()])->all();
    }

    /**
     * Holidays of the active holiday calendars within the limits, grouped by calendar.
     *
     * @return array<string, array<int, string>>
     */
    public static function calendarHolidayOptions(): array
    {
        $service = self::service();

        return Holiday::query()->with('calendar')
            ->whereHas('calendar', fn (Builder $q) => $q->where('status', 'active'))
            ->whereDate('date', '>=', $service->earliestDate()->toDateString())->whereDate('date', '<=', $service->latestDate()->toDateString())
            ->orderBy('date')->orderBy('id')->limit(1000)->get()
            ->groupBy(fn (Holiday $h) => (string) $h->calendar?->name)
            ->map(fn ($holidays) => $holidays->mapWithKeys(fn (Holiday $h) => [$h->id => $h->date->format('D, d M Y').' · '.$h->name
                .' ('.config("peopleos.attendance.holiday_types.{$h->type}", ucfirst((string) $h->type)).')'])->all())
            ->all();
    }

    /**
     * The holiday library's published holidays within the limits, for the organisation's countries: national lists and
     * the regions its locations are in (all regions when none matches), grouped by list.
     *
     * @return array<string, array<int, string>>
     */
    public static function libraryOptions(): array
    {
        $service = self::service();
        $library = app(HolidayLibrary::class);
        $countries = Company::query()->whereNotNull('country_code')->pluck('country_code')->map(fn ($c) => strtoupper((string) $c))->unique()->values()->all();
        $countries = $countries !== [] ? $countries : array_keys($library->countries());
        $entries = HolidayLibraryEntry::query()->with('libraryList')
            ->whereHas('libraryList', fn ($q) => $q->published()->whereIn('country_code', $countries))
            ->whereDate('date', '>=', $service->earliestDate()->toDateString())->whereDate('date', '<=', $service->latestDate()->toDateString())
            ->orderBy('date')->orderBy('id')->limit(2000)->get();
        $regions = collect($countries)->mapWithKeys(fn (string $c) => [$c => array_keys($library->locationRegions($c))])->all();
        $mine = $entries->filter(fn (HolidayLibraryEntry $e) => $e->libraryList->isNational() || in_array($e->libraryList->region_code, $regions[$e->libraryList->country_code] ?? [], true));
        $entries = $mine->isNotEmpty() ? $mine : $entries;

        return $entries->groupBy(fn (HolidayLibraryEntry $e) => Countries::name($e->libraryList->country_code).' · '.$e->libraryList->regionLabel().' '.$e->libraryList->year)
            ->map(fn ($group) => $group->mapWithKeys(fn (HolidayLibraryEntry $e) => [$e->id => HolidayLibrary::optionLabel($e).' ('.HolidayLibrary::optionDescription($e).')'])->all())
            ->all();
    }
}
