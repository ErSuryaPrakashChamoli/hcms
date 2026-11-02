<?php

namespace App\Filament\Pages;

use App\Domain\Employment\Models\Employee;
use App\Domain\Experience\BulkOperations\CarryForwardLeaveOperation;
use App\Domain\Experience\Services\BulkOperations;
use App\Domain\Experience\Services\BulkRuns;
use App\Domain\Leave\Services\LeaveYearEnd;
use App\Filament\Resources\BulkRuns\BulkRunResource;
use App\Filament\Resources\LeaveBalances\LeaveBalanceResource;
use App\Filament\Support\EmployeeFilters;
use App\Filament\Support\Pages\Concerns\HasRegisterTools;
use App\Filament\Support\Pages\RegisterTablePage;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Text;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Contracts\Pagination\Paginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Livewire\Attributes\Url;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;
use UnitEnum;

/**
 * HROne parity TX-8a (matrix 24 §24.6.6 — global action "Carry forward leaves", `leavecarryforward_253`): the leave year
 * end on screen. Choose the leave year and the leave type (or every type a leave policy carries), narrow the employees
 * with the leave policy and WO-2's filters (search, pasted codes, organisation and location, status and type), and see
 * per employee and leave type the closing balance, the days to lapse and the days to carry forward — a dry run of the
 * year close (LeaveAccrual::yearEnd, the calculation `peopleos:leave:accrue --close-year` and the daily run post). Select
 * rows and "Carry forward" posts the year close for them (LeaveAccrual::closeYear: idempotent, audited as the year
 * close); selections above `peopleos.bulk.confirm_over` run as a WO-1 bulk run. "Download" keeps the preview as CSV;
 * "Pending" counts employees still to carry forward. Opens for leave.view with leave.carry_forward (TX-2; leave.manage
 * still grants it).
 */
class LeaveCarryForward extends RegisterTablePage
{
    use HasRegisterTools;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowUturnRight;

    protected static string|UnitEnum|null $navigationGroup = 'Leave';

    protected static ?string $navigationLabel = 'Carry forward leaves';

    protected static ?string $title = 'Carry forward leaves';

    protected static ?string $slug = 'carry-forward-leaves';

    protected static ?int $navigationSort = 30;

    protected string $view = 'filament.pages.leave-carry-forward';

    public const REGISTER = 'leave_carry_forward';

    /** The leave year (its first calendar year: 2026 = the year that starts in 2026). */
    #[Url]
    public ?string $year = null;

    /** A leave type code, or empty for every type a leave policy carries. */
    #[Url]
    public ?string $type = null;

    /**
     * The last direct carry forward's result, shown above the employees.
     *
     * @var array{title: string, written: int, done: list<array<string, mixed>>, skipped: list<array<string, mixed>>, refused: list<array<string, mixed>>}|null
     */
    public ?array $result = null;

    /** @var array<int, array<string, mixed>> this request's preview rows, per employee */
    private array $previewMemo = [];

    private ?int $pendingMemo = null;

    private ?LeaveYearEnd $service = null;

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user !== null && $user->hasPermission('leave.view') && $user->hasPermission(LeaveYearEnd::PERMISSION);
    }

    public static function registerKey(): string
    {
        return self::REGISTER;
    }

    public static function presetViews(): array
    {
        return [
            'pending' => ['label' => 'Still to carry forward', 'filters' => ['show' => ['value' => 'pending']]],
            'posted' => ['label' => 'Carried forward', 'filters' => ['show' => ['value' => 'posted']]],
        ];
    }

    public function getSubheading(): ?string
    {
        return 'The leave year end: what each employee carries into the next leave year and what lapses, by the leave policy (all of it, none, or up to the limit). Check it, select the rows and carry forward; the daily leave run also closes the year that ended on its first run after it.';
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);
        $this->normalise();
    }

    /** This request's service (it keeps the year's leave types once read). */
    private function years(): LeaveYearEnd
    {
        return $this->service ??= app(LeaveYearEnd::class);
    }

    public function period(): int
    {
        return (int) $this->year;
    }

    /** @return list<string>|null the chosen leave type, or null for every type a leave policy carries */
    public function codes(): ?array
    {
        return filled($this->type) ? [(string) $this->type] : null;
    }

    /** @return array<int, string> */
    public function yearOptions(): array
    {
        return $this->years()->yearOptions();
    }

    /** @return array<string, string> code => label */
    public function typeOptions(): array
    {
        return collect($this->years()->types($this->period()))->mapWithKeys(fn ($type, string $code) => [$code => $code.' · '.$type->name])->all();
    }

    public function yearEnded(): bool
    {
        return $this->years()->yearEnded($this->period());
    }

    public function yearLabel(): string
    {
        return $this->years()->label($this->period());
    }

    public function endsOn(): string
    {
        return $this->years()->endsOn($this->period())->format('j M Y');
    }

    /** "Pending actions": employees in the viewer's scope still to carry forward for the year and type(s). */
    public function pendingCount(): int
    {
        return $this->pendingMemo ??= $this->years()->pendingCount($this->period(), $this->years()->typeIds($this->period(), $this->codes()));
    }

    public function pendingUrl(): string
    {
        return static::getUrl(['year' => $this->period(), 'type' => $this->type, 'view' => 'pending']);
    }

    public function updatedYear(): void
    {
        $this->normalise();
        $this->afterChange();
    }

    public function updatedType(): void
    {
        $this->normalise();
        $this->afterChange();
    }

    private function normalise(): void
    {
        if (! array_key_exists((int) $this->year, $this->yearOptions()) || ! is_numeric($this->year)) {
            $this->year = (string) $this->years()->defaultPeriod();
        }
        if (filled($this->type) && ! array_key_exists((string) $this->type, $this->typeOptions())) {
            $this->type = null;
        }
    }

    private function afterChange(): void
    {
        $this->previewMemo = [];
        $this->pendingMemo = null;
        $this->result = null;
        $this->resetTable();
    }

    // --- The preview -------------------------------------------------------------------------------------------------

    public function table(Table $table): Table
    {
        $lines = fn (string $key) => fn (Employee $record) => array_map(fn (array $line) => $this->cell($line, $key), $this->row($record)['lines']);

        return $table
            ->query(fn (): Builder => $this->population())
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['person', 'currentPosition.department', 'currentPosition.designation']))
            ->heading(fn () => 'Leave year '.$this->yearLabel())
            ->description(fn () => $this->yearEnded()
                ? 'The year has ended. Rows "To carry forward" are posted when you carry them forward; rows already carried forward show what was posted.'
                : 'The year ends on '.$this->endsOn().': the figures are the balances so far, and carrying forward opens once the year has ended.')
            ->columns([
                TextColumn::make('employee_code')->label('Employee code')->sortable(),
                TextColumn::make('person.display_name')->label('Employee name')->searchable(query: EmployeeFilters::search())->wrap(),
                TextColumn::make('policy')->label('Policy name')->state(fn (Employee $record) => $this->row($record)['policy'])->placeholder('—')->wrap(),
                TextColumn::make('leave_type')->label('Leave type')->state($lines('type'))->listWithLineBreaks(),
                TextColumn::make('closing')->label('No. of total leave')->state($lines('closing'))->listWithLineBreaks()->alignEnd(),
                TextColumn::make('lapse')->label('No. of leaves to be lapsed')->state($lines('lapse'))->listWithLineBreaks()->alignEnd(),
                TextColumn::make('carry')->label('No. of leaves to be carried forward')->state($lines('carry'))->listWithLineBreaks()->alignEnd()->weight('bold'),
                TextColumn::make('status')->label('Status')->state($lines('status'))->listWithLineBreaks()->badge()
                    ->color(fn (string $state) => match ($state) {
                        LeaveYearEnd::STATUSES['posted'] => 'success', LeaveYearEnd::STATUSES['pending'] => 'warning', default => 'gray',
                    }),
                TextColumn::make('currentPosition.department.name')->label('Department')->placeholder('—')->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('currentPosition.designation.name')->label('Designation')->placeholder('—')->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('show')->label('Show')->options(['pending' => 'Still to carry forward', 'posted' => 'Carried forward'])->placeholder('Everyone with a balance')
                    ->query(fn (Builder $query, array $data) => match ($data['value'] ?? null) {
                        'pending' => $query->whereIn('employees.id', $this->years()->pending($this->period(), $this->typeIds())),
                        'posted' => $query->whereIn('employees.id', $this->years()->posted($this->period(), $this->typeIds())),
                        default => $query,
                    }),
                EmployeeFilters::codes(),
                EmployeeFilters::organisation(),
                EmployeeFilters::workforce(),
                // Last: the leave policy is resolved per employee (as the year close resolves it) for those the other filters keep.
                SelectFilter::make('policy')->label('Leave policy')->options(fn () => $this->years()->policyOptions())
                    ->query(fn (Builder $query, array $data) => filled($data['value'] ?? null)
                        ? $query->whereIn('employees.id', $this->years()->onPolicy($query, (int) $data['value'], $this->period()) ?: [0])
                        : $query),
            ])
            ->toolbarActions([$this->carryForwardAction()])
            ->emptyStateHeading('No one to show')
            ->emptyStateDescription('Nobody in your access has a balance in these leave types for the year, or the filters leave everyone out.')
            ->defaultSort('employee_code')
            ->paginated([25, 50, 100]);
    }

    /** @return list<int> */
    private function typeIds(): array
    {
        return $this->years()->typeIds($this->period(), $this->codes());
    }

    /** Employed employees (as the year close takes them) with a balance or a year-end entry in the year and type(s), in the viewer's scope. */
    private function population(): Builder
    {
        $typeIds = $this->typeIds();
        if ($typeIds === []) {
            return Employee::query()->whereRaw('1 = 0');
        }
        $period = $this->period();

        return $this->years()->population()->where(fn (Builder $q) => $q
            ->whereIn('employees.id', $this->years()->withBalance($period, $typeIds))
            ->orWhereIn('employees.id', $this->years()->posted($period, $typeIds)));
    }

    /**
     * One employee's preview (computed for the whole page at once).
     *
     * @return array{policy_id: ?int, policy: ?string, lines: list<array<string, mixed>>}
     */
    public function row(Employee $employee): array
    {
        $id = (int) $employee->id;
        if (! array_key_exists($id, $this->previewMemo)) {
            $records = $this->getTableRecords();
            $page = collect($records instanceof Paginator ? $records->items() : $records)->keyBy(fn (Employee $e) => (int) $e->id);
            $page->put($id, $employee);
            $this->previewMemo += $this->years()->preview($page->values(), $this->period(), $this->codes());
        }

        return $this->previewMemo[$id] ?? ['policy_id' => null, 'policy' => null, 'lines' => []];
    }

    /** @param  array<string, mixed>  $line */
    private function cell(array $line, string $key): string
    {
        return match ($key) {
            'type' => $line['code'].' · '.$line['type'],
            'status' => LeaveYearEnd::STATUSES[$line['status']] ?? (string) $line['status'],
            default => LeaveYearEnd::days((float) $line[$key]),
        };
    }

    protected function getHeaderActions(): array
    {
        return [
            ...$this->registerToolActions(),
            Action::make('download')->label('Download')->icon(Heroicon::OutlinedArrowDownTray)->color('gray')
                ->action(fn () => $this->downloadCsv()),
            Action::make('balances')->label('Leave balances')->icon(Heroicon::OutlinedTableCells)->color('gray')
                ->visible(fn () => LeaveBalanceResource::canAccess())
                ->url(fn () => LeaveBalanceResource::getUrl('index')),
        ];
    }

    public function downloadCsv(): StreamedResponse
    {
        abort_unless(static::canAccess(), 403);
        $employees = $this->getFilteredSortedTableQuery()->with('person')->limit(BulkRuns::maxRecords())->get();
        $csv = $this->years()->csv(auth()->user(), $employees, $this->period(), $this->codes());
        $name = 'carry-forward-leaves-'.$this->period().($this->type ? '-'.strtolower((string) preg_replace('/[^A-Za-z0-9]+/', '-', (string) $this->type)) : '').'.csv';

        return response()->streamDownload(fn () => print ($csv), $name, ['Content-Type' => 'text/csv']);
    }

    // --- Carry forward the selected rows -----------------------------------------------------------------------------

    private function carryForwardAction(): BulkAction
    {
        return BulkAction::make('carryForward')->label('Carry forward')->icon(Heroicon::OutlinedArrowUturnRight)->color('primary')
            ->disabled(fn () => ! $this->yearEnded())
            ->tooltip(fn () => $this->yearEnded() ? null : 'The leave year ends on '.$this->endsOn().': carry forward once it has ended.')
            ->deselectRecordsAfterCompletion()
            ->modalHeading(fn (BulkAction $action) => 'Carry forward leaves · '.$this->years()->name($this->period()).' · '.count($action->getSelectedRecords()).' employee(s)')
            ->modalSubmitActionLabel('Carry forward')
            ->schema(function (BulkAction $action) {
                $count = count($action->getSelectedRecords());

                return [
                    Text::make($this->summary($count)),
                    TextInput::make('confirm_count')->label('Type '.$count.' to confirm')->visible($count > BulkRuns::confirmOver())->required($count > BulkRuns::confirmOver())
                        ->in([(string) $count])->validationMessages(['in' => 'Type exactly '.$count.'.']),
                ];
            })
            ->action(fn (BulkAction $action, array $data) => $this->carryForwardSelected(collect($action->getSelectedRecords()), $data));
    }

    private function summary(int $count): string
    {
        $types = $this->codes() === null ? 'every leave type a leave policy carries' : implode(', ', array_intersect_key($this->typeOptions(), array_flip($this->codes())));

        return 'Posts the year close of the '.$this->years()->name($this->period()).' leave year for '.$count.' employee(s) in '.$types.': the days to carry forward go into the next leave year and the rest lapse, as the preview shows. '
            .'Rows already carried forward are left as they are; employees outside your access are refused. '
            .$this->runNote($count);
    }

    private function runNote(int $count): string
    {
        if ($count <= BulkRuns::confirmOver()) {
            return 'It runs now and the result is shown above the employees.';
        }

        return app(BulkOperations::class)->allows(auth()->user(), CarryForwardLeaveOperation::KEY)
            ? 'More than '.BulkRuns::confirmOver().' employees: it runs as a bulk run in the background (People → Bulk runs lists each employee).'
            : 'More than '.BulkRuns::confirmOver().' employees need the bulk run right (bulk.run): select fewer employees.';
    }

    /**
     * Small selections post now through LeaveYearEnd::post (result summary above the employees); larger ones start a
     * WO-1 bulk run.
     *
     * @param  Collection<int, Employee>  $employees
     * @param  array<string, mixed>  $data
     */
    public function carryForwardSelected(Collection $employees, array $data): void
    {
        abort_unless(static::canAccess(), 403);
        $confirmed = isset($data['confirm_count']) && is_numeric($data['confirm_count']) ? (int) $data['confirm_count'] : null;
        $period = $this->period();

        if ($employees->count() > BulkRuns::confirmOver()) {
            try {
                $run = app(BulkRuns::class)->start(auth()->user(), self::REGISTER, CarryForwardLeaveOperation::KEY, $employees->map(fn (Employee $e) => $e->getKey())->all(),
                    ['period' => $period, 'codes' => $this->codes()], 'selected', $confirmed);
            } catch (RuntimeException $e) {
                Notification::make()->danger()->title('Not started')->body($e->getMessage())->send();

                return;
            }
            $run->refresh();
            Notification::make()->status($run->status === 'failed' ? 'danger' : ($run->refused + $run->failed > 0 ? 'warning' : 'success'))
                ->title($run->operation_label.': '.($run->isFinished() ? $run->summary() : 'queued for '.$run->total.' employee(s)'))
                ->body($run->isFinished() && $run->refused + $run->failed > 0 ? 'Some employees were not carried forward; the run lists each one with the reason.' : null)
                ->actions([Action::make('open')->label('See each employee')->url(BulkRunResource::getUrl('view', ['record' => $run]))])->send();
            $this->afterPosting();

            return;
        }

        try {
            $result = $this->years()->post($employees, $period, $this->codes(), auth()->user());
        } catch (RuntimeException $e) {
            Notification::make()->danger()->title('Not carried forward')->body($e->getMessage())->send();

            return;
        }
        $this->afterPosting();
        $this->result = ['title' => 'Carry forward leaves · '.$this->years()->name($period)] + $result;
        $refused = count($result['refused']);
        $first = $result['refused'][0] ?? null;
        Notification::make()->status($refused === 0 ? 'success' : (count($result['done']) + count($result['skipped']) === 0 ? 'danger' : 'warning'))
            ->title($refused === 0 ? 'Leave carried forward successfully.' : 'Carry forward leaves · '.$this->years()->name($period))
            ->body(count($result['done']).' employee(s) carried forward ('.$result['written'].' entries), '.count($result['skipped']).' already done or with nothing to carry, '.$refused.' refusal(s)'
                .($first !== null ? ': '.$first['label'].' — '.$first['reason'] : '.'))
            ->send();
    }

    private function afterPosting(): void
    {
        $this->previewMemo = [];
        $this->pendingMemo = null;
    }

    public function clearResult(): void
    {
        $this->result = null;
    }
}
