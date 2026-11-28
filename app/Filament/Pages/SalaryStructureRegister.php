<?php

namespace App\Filament\Pages;

use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Audit\Services\AuditRecorder;
use App\Domain\Compensation\Contracts\CompensationOutput;
use App\Domain\Compensation\Models\CompensationChange;
use App\Domain\Compensation\Models\SalaryStructure;
use App\Domain\Compensation\Services\SalaryAssignments;
use App\Domain\Compensation\Support\CompensationSnapshot;
use App\Domain\Compliance\Services\DeclaredRegimes;
use App\Domain\Employment\Models\Employee;
use App\Domain\Experience\BulkOperations\AssignSalaryOperation;
use App\Domain\Experience\Services\BulkOperations;
use App\Domain\Experience\Services\BulkRuns;
use App\Filament\Resources\BulkRuns\BulkRunResource;
use App\Filament\Resources\Employees\EmployeeResource;
use App\Filament\Resources\Employees\Pages\ViewEmployee;
use App\Filament\Support\Compensation\SalaryChangeWizard;
use App\Filament\Support\EmployeeFilters;
use App\Filament\Support\Pages\Concerns\HasRegisterTools;
use App\Filament\Support\Pages\RegisterTablePage;
use App\Filament\Support\Review\Outcome;
use App\Livewire\Compensation\CurrentSalary;
use App\Support\Csv\SpreadsheetCell;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\BulkAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\View;
use Filament\Schemas\Components\Wizard\Step;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Contracts\Pagination\Paginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\HtmlString;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;
use UnitEnum;

/**
 * Owner, 2026-11-27: who has a salary and who is left is the first thing the page says (the Assigned / Not assigned
 * cards and the Salary column, by the salary in force today); each row opens the employee's Current salary on Employee
 * 360 (breakup, statutory, revisions), and its one salary action is "Assign salary" for someone who never had one and
 * "Revise salary" otherwise (a hike % or a new amount on CTC, gross or net pay), with "Edit salary in force" beside it —
 * the wizard shared with Employee 360 (SalaryChangeWizard).
 *
 * HROne parity PO-2 (matrix 25 §25.2.1 #1–#5, §25.2.2 #1, #12, #18, #21–#23, #30): HROne's employee-centric "Salary
 * structures" records — one row per employed person in the reader's access scope with the structure and annual CTC in
 * force (through CompensationOutput), the state of their salary structure (assigned / not assigned / pending / approved /
 * rejected, from their latest compensation change), its source and dates and the tax regime of their declaration; WO-2's
 * employee filters, saved views, a "Not assigned" preset, report link and an audited CSV (compensation.export).
 *
 * "Assign salary" (one employee: a four-step wizard — statutory details through the Employee 360 action, the proposal with
 * "revise by %", a breakup preview with a read-only statutory estimate and the salary checks, review; selected employees:
 * the same proposal for each through the WO-1 bulk framework) only PROPOSES: every proposal is a compensation change
 * reviewed, approved and executed by other people, as from Employee 360. Opens for compensation.view; assigning needs
 * compensation.propose (and bulk.run for many).
 */
class SalaryStructureRegister extends RegisterTablePage
{
    use HasRegisterTools;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUserGroup;

    protected static string|UnitEnum|null $navigationGroup = 'Compensation';

    protected static ?string $navigationLabel = 'Employee salary structures';

    protected static ?string $title = 'Salary structures by employee';

    protected static ?string $slug = 'employee-salary-structures';

    protected static ?int $navigationSort = 9;

    protected string $view = 'filament.pages.salary-structure-register';

    public const REGISTER = 'employee_salary_structures';

    /** Keys of the assign form that SalaryAssignments and the statutory step read. */
    private const FORM_KEYS = ['change_type', 'effective_from', 'salary_structure_id', 'ctc_mode', 'ctc_annual', 'increase_percent', 'currency', 'variable_target_annual', 'component_values', 'reason', 'internal_notes', 'submit'];

    /** @var array<int, array{in_force: ?CompensationSnapshot, request: ?CompensationChange, regime: ?string, ever: bool}> this request's rows */
    private array $rows = [];

    /** @var array<int, string> structure id => name */
    private array $structureNames = [];

    public static function canAccess(): bool
    {
        return auth()->user()?->hasPermission('compensation.view') ?? false;
    }

    public static function registerKey(): string
    {
        return self::REGISTER;
    }

    public static function presetViews(): array
    {
        return [
            'not_assigned' => ['label' => 'Not assigned (no salary structure in force)', 'filters' => ['salary_in_force' => ['value' => 'no']]],
            'pending' => ['label' => 'Pending requests', 'filters' => ['state' => ['values' => ['pending']]]],
            'approved' => ['label' => 'Approved, not yet in force', 'filters' => ['state' => ['values' => ['approved']]]],
            'rejected' => ['label' => 'Rejected last', 'filters' => ['state' => ['values' => ['rejected']]]],
        ];
    }

    public static function registerReports(): array
    {
        return ['ctc_register', 'employees_without_salary', 'salary_revisions_this_fy'];
    }

    /** Lifecycle states that may hold compensation now or later (the register's people). */
    public static function states(): array
    {
        return array_values(array_unique([...(array) config('peopleos.compensation.lifecycle.current', []), ...(array) config('peopleos.compensation.lifecycle.future', [])]));
    }

    public function getSubheading(): ?string
    {
        return 'Every employee with the salary structure and CTC in force and the state of their latest salary request. "Assign salary" proposes; a reviewer, an approver and an executor still decide each proposal.';
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);
    }

    public function canPropose(): bool
    {
        return auth()->user()?->hasPermission('compensation.propose') ?? false;
    }

    // --- The register ------------------------------------------------------------------------------------------------

    public function table(Table $table): Table
    {
        return $table
            ->query(fn (): Builder => static::population())
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['person', 'currentPosition.designation']))
            ->columns([
                TextColumn::make('employee_code')->label('Employee code')->sortable(),
                TextColumn::make('person.display_name')->label('Employee')->searchable(query: EmployeeFilters::search())
                    ->description(fn (Employee $record) => $record->currentPosition?->designation?->name),
                // Owner, 2026-11-27: assigned or not, by the salary in force today, before anything else.
                TextColumn::make('salary_in_force')->label('Salary')->badge()
                    ->state(fn (Employee $record) => $this->row($record)['in_force'] !== null ? 'Assigned' : 'Not assigned')
                    ->color(fn (Employee $record) => $this->row($record)['in_force'] !== null ? 'success' : 'danger')
                    ->icon(fn (Employee $record) => $this->row($record)['in_force'] !== null ? Heroicon::OutlinedCheckCircle : Heroicon::OutlinedExclamationCircle),
                // HROne's chip (PO-2): assigned / not assigned / pending / approved / rejected; the Salary column and the
                // latest request say the same at a glance, so it is one click away in the column picker.
                TextColumn::make('salary_state')->label('Status')->badge()->toggleable(isToggledHiddenByDefault: true)
                    ->state(fn (Employee $record) => SalaryAssignments::STATES[$this->stateOf($record)])
                    ->color(fn (Employee $record) => match ($this->stateOf($record)) {
                        'assigned' => 'success', 'not_assigned' => 'danger', 'pending' => 'warning', 'approved' => 'info', default => 'gray'
                    }),
                TextColumn::make('structure_in_force')->label('Structure in force')->placeholder('—')
                    ->state(fn (Employee $record) => ($s = $this->row($record)['in_force']) ? ($this->structureNames[$s->structureId] ?? $s->structureCode) : null)
                    ->description(fn (Employee $record) => $this->row($record)['in_force']?->structureCode),
                TextColumn::make('ctc_in_force')->label('Annual CTC in force')->numeric(2)->placeholder('—')
                    ->state(fn (Employee $record) => $this->row($record)['in_force']?->ctcAnnual)
                    ->description(fn (Employee $record) => $this->row($record)['in_force']?->currency),
                TextColumn::make('in_force_from')->label('In force from')->date()->placeholder('—')->toggleable()
                    ->state(fn (Employee $record) => $this->row($record)['in_force']?->effectiveFrom),
                TextColumn::make('request_status')->label('Latest request')->placeholder('—')
                    ->state(fn (Employee $record) => ($c = $this->row($record)['request']) ? (CompensationChange::STATUSES[$c->status] ?? $c->status) : null)
                    ->description(fn (Employee $record) => ($c = $this->row($record)['request']) ? config("peopleos.compensation.change_types.{$c->change_type}", $c->change_type) : null),
                TextColumn::make('request_source')->label('Source')->placeholder('—')->toggleable()
                    ->state(fn (Employee $record) => ($c = $this->row($record)['request']) ? config("peopleos.compensation.sources.{$c->source}", $c->source) : null),
                TextColumn::make('requested_on')->label('Request date')->date()->placeholder('—')
                    ->state(fn (Employee $record) => $this->row($record)['request']?->created_at),
                TextColumn::make('request_effective_from')->label('Request effective from')->date()->placeholder('—')->toggleable()
                    ->state(fn (Employee $record) => $this->row($record)['request']?->effective_from),
                TextColumn::make('tax_regime')->label('Tax regime')->toggleable()
                    ->state(fn (Employee $record) => self::regimeLabel($this->row($record)['regime'])),
            ])
            ->filters([
                SelectFilter::make('state')->label('State')->multiple()->options(SalaryAssignments::STATES)
                    ->query(fn (Builder $query, array $data) => app(SalaryAssignments::class)->whereState($query, array_values((array) ($data['values'] ?? [])))),
                SelectFilter::make('salary_in_force')->label('Salary structure in force')->options(['yes' => 'Assigned', 'no' => 'Not assigned'])
                    ->query(fn (Builder $query, array $data) => in_array($data['value'] ?? null, ['yes', 'no'], true) ? app(SalaryAssignments::class)->whereInForce($query, $data['value'] === 'yes') : $query),
                SelectFilter::make('salary_structure')->label('Salary structure (pay group)')->multiple()
                    ->options(fn () => SalaryStructure::query()->orderBy('name')->limit(500)->pluck('name', 'id')->all())
                    ->query(function (Builder $query, array $data): Builder {
                        $ids = array_values(array_filter(array_map('intval', (array) ($data['values'] ?? []))));

                        return $ids === [] ? $query : $query->whereIn('employees.id', app(CompensationOutput::class)->coveredEmployeeIds(today(), $ids));
                    }),
                SelectFilter::make('source')->label('Source of the latest request')->multiple()->options((array) config('peopleos.compensation.sources', []))
                    ->query(fn (Builder $query, array $data) => app(SalaryAssignments::class)->whereRequest($query, array_values((array) ($data['values'] ?? [])))),
                SelectFilter::make('tax_regime')->label('Tax regime (declaration of this financial year)')->multiple()->options(DeclaredRegimes::options())
                    ->query(fn (Builder $query, array $data) => app(DeclaredRegimes::class)->whereRegime($query, 'employees.id', array_values((array) ($data['values'] ?? [])))),
                Filter::make('requested')->label('Request date')->columns(2)
                    ->schema([
                        DatePicker::make('from')->label('Requested from')->native(false),
                        DatePicker::make('until')->label('Requested until')->native(false),
                    ])
                    ->query(fn (Builder $query, array $data) => app(SalaryAssignments::class)->whereRequest($query, [], $data['from'] ?? null, $data['until'] ?? null))
                    ->indicateUsing(fn (array $data) => filled($data['from'] ?? null) || filled($data['until'] ?? null)
                        ? 'Requested '.(filled($data['from'] ?? null) ? 'from '.Carbon::parse($data['from'])->format('j M Y').' ' : '').(filled($data['until'] ?? null) ? 'until '.Carbon::parse($data['until'])->format('j M Y') : '')
                        : null),
                EmployeeFilters::codes(),
                EmployeeFilters::organisation(),
                EmployeeFilters::workforce(),
                EmployeeFilters::excludePendingResignation(),
            ])
            ->recordActions([
                $this->assignAction(),
                Action::make('viewSalary')->label('View salary')->icon(Heroicon::OutlinedEye)->color('gray')
                    ->visible(fn (Employee $record) => EmployeeResource::canView($record) && CurrentSalary::canViewForRecord($record, ViewEmployee::class))
                    ->url(fn (Employee $record) => EmployeeResource::getUrl('view', ['record' => $record]).'#list-CurrentSalary'),
                ActionGroup::make([
                    $this->correctAction(),
                    Action::make('open360')->label('Employee 360')->icon(Heroicon::OutlinedUser)->color('gray')
                        ->visible(fn (Employee $record) => EmployeeResource::canView($record))
                        ->url(fn (Employee $record) => EmployeeResource::getUrl('view', ['record' => $record])),
                ])->label('More')->icon(Heroicon::OutlinedEllipsisHorizontal)->color('gray'),
            ])
            ->toolbarActions([$this->assignSelectedAction()])
            ->defaultSort('employee_code')
            ->emptyStateHeading('No employees')
            ->emptyStateDescription('Employees you can see, and who can hold a salary, appear here.')
            ->paginated([25, 50, 100]);
    }

    /** The register's people: employees who may hold compensation now or later, inside the reader's access scope. */
    public static function population(): Builder
    {
        return Employee::query()->whereIn('employees.lifecycle_state', self::states());
    }

    /** @return array{in_force: ?CompensationSnapshot, request: ?CompensationChange, regime: ?string, ever: bool} */
    private function row(Employee $employee): array
    {
        $id = (int) $employee->id;
        if (! array_key_exists($id, $this->rows)) {
            $records = $this->getTableRecords();
            $ids = collect($records instanceof Paginator ? $records->items() : $records)->map(fn ($r) => (int) $r->getKey())->push($id)->unique()->values()->all();
            $this->load($ids);
        }

        return $this->rows[$id];
    }

    private function stateOf(Employee $employee): string
    {
        $row = $this->row($employee);

        return SalaryAssignments::stateOf($row['request'], $row['in_force'] !== null);
    }

    /** @param  list<int>  $ids */
    private function load(array $ids): void
    {
        $inForce = app(CompensationOutput::class)->inForceOn($ids, today());
        $requests = app(SalaryAssignments::class)->latestRequests($ids);
        $regimes = app(DeclaredRegimes::class)->forEmployees($ids);
        $ever = array_flip(app(SalaryAssignments::class)->everCompensated($ids));
        foreach ($ids as $id) {
            $this->rows[$id] = ['in_force' => $inForce[$id] ?? null, 'request' => $requests[$id] ?? null, 'regime' => $regimes[$id] ?? null, 'ever' => isset($ever[$id])];
        }
        $missing = array_diff(collect($inForce)->map(fn (CompensationSnapshot $s) => $s->structureId)->unique()->all(), array_keys($this->structureNames));
        if ($missing !== []) {
            $this->structureNames += SalaryStructure::query()->whereIn('id', $missing)->pluck('name', 'id')->map(fn ($n) => (string) $n)->all();
        }
    }

    public static function regimeLabel(?string $regime): string
    {
        return $regime === null ? 'New (no declaration)' : (string) (config("peopleos.compliance.tax_regimes.{$regime}") ?? ucfirst($regime));
    }

    /** @return array<string, int> employees in each state (the whole register in the reader's scope) */
    public function stateCounts(): array
    {
        return app(SalaryAssignments::class)->counts(static::population());
    }

    /**
     * Owner, 2026-11-27: who has a salary in force today and who is left (the whole register in the reader's scope).
     *
     * @return array{assigned: int, not_assigned: int}
     */
    public function inForceCounts(): array
    {
        $assignments = app(SalaryAssignments::class);

        return ['assigned' => $assignments->whereInForce(static::population(), true)->count(), 'not_assigned' => $assignments->whereInForce(static::population(), false)->count()];
    }

    /** An Assigned / Not assigned card: show the register with (or without) a salary in force. */
    public function showInForce(string $value): void
    {
        if (in_array($value, ['yes', 'no'], true)) {
            $this->applyRegisterView(['filters' => ['salary_in_force' => ['value' => $value]]], $value === 'yes' ? 'Salary assigned' : 'Salary not assigned');
            $this->afterChange();
        }
    }

    /** A state card: show the register in that state. */
    public function showState(string $state): void
    {
        if (array_key_exists($state, SalaryAssignments::STATES)) {
            $this->applyRegisterView(['filters' => ['state' => ['values' => [$state]]]], SalaryAssignments::STATES[$state]);
            $this->afterChange();
        }
    }

    protected function getHeaderActions(): array
    {
        return [
            ...$this->registerToolActions(),
            Action::make('downloadCsv')->label('Download CSV')->icon(Heroicon::OutlinedArrowDownTray)->color('gray')
                ->visible(fn () => auth()->user()?->hasPermission('compensation.export') ?? false)
                ->action(fn () => $this->downloadCsv()),
        ];
    }

    /** HROne's "Export All": the filtered register as CSV (compensation.export; audited; formula-injection safe). */
    public function downloadCsv(): StreamedResponse
    {
        $user = auth()->user();
        abort_unless(static::canAccess() && $user->hasPermission('compensation.export'), 403);
        $employees = $this->getFilteredSortedTableQuery()->with('person')->limit(BulkRuns::maxRecords())->get();
        $this->load($employees->pluck('id')->map(fn ($id) => (int) $id)->all());

        $out = fopen('php://temp', 'w+');
        fputcsv($out, ['Employee code', 'Employee', 'State', 'Structure in force', 'Structure code', 'Annual CTC in force', 'Currency', 'In force from', 'Latest request', 'Request reference', 'Change type', 'Source', 'Request date', 'Request effective from', 'Tax regime']);
        foreach ($employees as $employee) {
            $row = $this->rows[(int) $employee->id];
            $snap = $row['in_force'];
            $change = $row['request'];
            fputcsv($out, array_map(self::cell(...), [
                $employee->employee_code, $employee->person?->display_name, SalaryAssignments::STATES[SalaryAssignments::stateOf($change, $snap !== null)],
                $snap ? ($this->structureNames[$snap->structureId] ?? null) : null, $snap?->structureCode, $snap?->ctcAnnual, $snap?->currency, $snap?->effectiveFrom->toDateString(),
                $change ? (CompensationChange::STATUSES[$change->status] ?? $change->status) : null, $change?->reference,
                $change ? config("peopleos.compensation.change_types.{$change->change_type}", $change->change_type) : null,
                $change ? config("peopleos.compensation.sources.{$change->source}", $change->source) : null,
                $change?->created_at?->toDateString(), $change?->effective_from?->toDateString(), self::regimeLabel($row['regime']),
            ]));
        }
        rewind($out);
        $csv = (string) stream_get_contents($out);
        fclose($out);

        app(AuditRecorder::class)->record(AuditAction::Export, 'compensation', null, [], null, actor: $user, entityLabel: 'Employee salary structures', metadata: [
            'export' => self::REGISTER, 'rows' => $employees->count(),
            'filters' => collect($this->tableFilters ?? [])->filter(fn ($v) => collect((array) $v)->flatten()->filter(fn ($x) => filled($x) && $x !== false)->isNotEmpty())->keys()->values()->all(),
        ]);

        return response()->streamDownload(fn () => print ($csv), 'employee-salary-structures-'.today()->format('Y-m-d').'.csv', ['Content-Type' => 'text/csv']);
    }

    private static function cell(mixed $value): string
    {
        $text = is_float($value) ? number_format($value, 2, '.', '') : (string) ($value ?? '');

        // Spreadsheet formula injection: a cell starting with = + - @ is read as text.
        return SpreadsheetCell::guard($text);
    }

    // --- Assign, revise or edit one employee's salary (the wizard shared with Employee 360) --------------------------

    /** "Assign salary" for someone who never had one, "Revise salary" otherwise (SalaryChangeWizard). */
    private function purposeOf(Employee $record): string
    {
        return $this->row($record)['ever'] ? 'revise' : 'assign';
    }

    private function assignAction(): Action
    {
        return Action::make('assign')
            ->label(fn (Employee $record) => SalaryChangeWizard::PURPOSES[$this->purposeOf($record)])
            ->icon(fn (Employee $record) => $this->purposeOf($record) === 'assign' ? Heroicon::OutlinedBanknotes : Heroicon::OutlinedArrowTrendingUp)
            ->visible(fn (Employee $record) => $this->canPropose() && (int) $record->user_id !== (int) auth()->id())
            ->modalHeading(fn (Employee $record) => SalaryChangeWizard::PURPOSES[$this->purposeOf($record)].' · '.$record->employee_code.' · '.($record->person?->display_name ?? ''))
            ->modalWidth(Width::FiveExtraLarge)
            ->modalSubmitActionLabel('Propose')
            ->mountUsing(function (Employee $record, $schema) {
                SalaryChangeWizard::auditOpening($record, $this->purposeOf($record));
                $schema?->fill();
            })
            ->steps(fn (Employee $record) => SalaryChangeWizard::steps($record, $this->purposeOf($record)))
            ->action(function (Employee $record, array $data) {
                if (SalaryChangeWizard::submit($record, $data, $this->purposeOf($record)) !== null) {
                    $this->afterChange();
                }
            });
    }

    /** "Edit salary in force": a correction on the date of the salary in force (proposed like any change). */
    private function correctAction(): Action
    {
        return Action::make('correct')->label(SalaryChangeWizard::PURPOSES['correct'])->icon(Heroicon::OutlinedPencilSquare)
            ->visible(fn (Employee $record) => $this->canPropose() && (int) $record->user_id !== (int) auth()->id() && $this->row($record)['in_force'] !== null)
            ->modalHeading(fn (Employee $record) => SalaryChangeWizard::PURPOSES['correct'].' · '.$record->employee_code.' · '.($record->person?->display_name ?? ''))
            ->modalWidth(Width::FiveExtraLarge)
            ->modalSubmitActionLabel('Propose')
            ->mountUsing(function (Employee $record, $schema) {
                SalaryChangeWizard::auditOpening($record, 'correct');
                $schema?->fill();
            })
            ->steps(fn (Employee $record) => SalaryChangeWizard::steps($record, 'correct'))
            ->action(function (Employee $record, array $data) {
                if (SalaryChangeWizard::submit($record, $data, 'correct') !== null) {
                    $this->afterChange();
                }
            });
    }

    /** @return array<string, mixed> the bulk form's values the services read */
    private function formState(Get $get, bool $many = false): array
    {
        $state = [];
        foreach (self::FORM_KEYS as $key) {
            if ($many && in_array($key, ['variable_target_annual', 'component_values'], true)) {
                continue;
            }
            $state[$key] = $get($key);
        }

        return $state;
    }

    /**
     * The compare step for one employee (SalaryChangeWizard::previewData): the breakup, before → after, the read-only
     * statutory estimate, the salary checks and the payment mode — or why the proposal would be refused.
     *
     * @param  array<string, mixed>  $state
     * @return array<string, mixed>
     */
    public function previewData(Employee $employee, array $state): array
    {
        return SalaryChangeWizard::previewData($employee, $state, SalaryChangeWizard::purposeFor($employee));
    }

    /** "Print breakup" of a proposal for one employee of this register (SalaryChangeWizard::printProposal, audited). */
    public function printProposal(int $employeeId, array $state): ?StreamedResponse
    {
        $employee = static::population()->whereKey($employeeId)->first();
        abort_unless(static::canAccess() && $this->canPropose() && $employee !== null, 403);

        return SalaryChangeWizard::printProposal($employeeId, $state, SalaryChangeWizard::purposeFor($employee));
    }

    // --- Assign salary (selected employees, WO-1 bulk run) ---------------------------------------------------------

    private function assignSelectedAction(): BulkAction
    {
        $operation = app(BulkOperations::class)->find(AssignSalaryOperation::KEY);

        return BulkAction::make('assignSelected')->label('Assign salary')->icon(Heroicon::OutlinedBanknotes)
            ->visible(fn () => $operation !== null && app(BulkOperations::class)->allows(auth()->user(), $operation))
            ->deselectRecordsAfterCompletion()
            ->modalHeading(fn (BulkAction $action) => 'Assign salary · '.count($action->getSelectedRecords()).' employee(s)')
            ->modalWidth(Width::FourExtraLarge)
            ->modalSubmitActionLabel('Start')
            ->steps(function (BulkAction $action) {
                $records = collect($action->getSelectedRecords());
                $typed = BulkRuns::needsTypedCount($records->count());

                return [
                    Step::make('Employee statutory')->description('Checked one employee at a time')->schema([Text::make(fn () => $this->bulkStatutoryHtml($records))]),
                    Step::make('Salary details')->description('The same proposal for each')->schema(AssignSalaryOperation::fields(many: true)),
                    Step::make('Preview')->description('Each employee before and after')->schema([Text::make(fn (Get $get) => $this->bulkPreviewHtml($records, $this->formState($get, true)))]),
                    Step::make('Review')->description('One proposal per employee')->schema([
                        Text::make(fn (Get $get) => $this->bulkReview($records, (bool) ($get('submit') ?? true))),
                        TextInput::make('confirm_count')->label('Type '.$records->count().' to confirm')->visible($typed)->required($typed)
                            ->in([(string) $records->count()])->validationMessages(['in' => 'Type exactly '.$records->count().'.']),
                    ]),
                ];
            })
            ->action(function (BulkAction $action, array $data) {
                $confirmed = isset($data['confirm_count']) && is_numeric($data['confirm_count']) ? (int) $data['confirm_count'] : null;
                unset($data['confirm_count']);
                try {
                    $run = app(BulkRuns::class)->start(auth()->user(), self::REGISTER, AssignSalaryOperation::KEY, collect($action->getSelectedRecords())->map(fn ($r) => $r->getKey())->all(),
                        AssignSalaryOperation::input($data), 'selected', $confirmed);
                } catch (RuntimeException $e) {
                    Notification::make()->danger()->title('Bulk run not started')->body($e->getMessage())->send();

                    return;
                }
                $run->refresh();
                Notification::make()->status($run->status === 'failed' ? 'danger' : ($run->refused + $run->failed > 0 ? 'warning' : 'success'))
                    ->title('Assign salary: '.($run->isFinished() ? $run->summary() : 'queued for '.$run->total.' employee(s)'))
                    ->body($run->isFinished() && $run->refused + $run->failed > 0 ? 'Some employees got no proposal; the run lists each one with the reason.' : null)
                    ->actions([Action::make('open')->label('See each employee')->url(BulkRunResource::getUrl('view', ['record' => $run]))])->send();
                $this->afterChange();
            });
    }

    /** @param  Collection<int, Employee>  $records */
    private function bulkStatutoryHtml(Collection $records): HtmlString
    {
        $missing = Employee::query()->whereKey($records->pluck('id')->all())->whereDoesntHave('statutoryDetail')->count();

        return new HtmlString('<p class="text-sm">Statutory details (PF, ESI, PT, tax regime) are changed for one employee at a time: "Assign salary" on the employee\'s row, or Employee 360.</p>'
            .'<p class="mt-2 text-sm '.($missing > 0 ? 'text-warning-600' : 'text-gray-500').'" data-statutory-missing="'.$missing.'">'.$missing.' of the '.$records->count().' selected employee(s) have no statutory details on file (payroll then applies PF and PT by default).</p>');
    }

    /**
     * The bulk wizard's Review: what starting does, as an outcome card (one change per employee, who, what is refused and
     * what comes next).
     *
     * @param  Collection<int, Employee>  $records
     */
    private function bulkReview(Collection $records, bool $submit): Outcome
    {
        $count = $records->count();
        $employees = $records->take(10)->map(fn (Employee $e) => $e->auditLabel())->values()->all();

        return Outcome::make('What happens when you start')->data('assign-review')
            ->headline('Each of the '.$count.' selected '.str('employee')->plural($count).' gets one compensation change, '.($submit ? 'submitted for review.' : 'saved as a draft.'),
                $submit ? null : 'You chose a draft: each one waits for you to submit it.')
            ->people($count.' '.str('employee')->plural($count), $employees, $count - count($employees))
            ->note('Employees with a change already pending, your own record and employees outside your access; each is listed with the reason on the bulk run.', 'refuse', 'Refused:')
            ->note('A reviewer, an approver and an executor (other people) decide each change.', 'next', 'Next:');
    }

    /**
     * Each selected employee (the first 25): the CTC in force and the CTC proposed, or why the employee will be refused —
     * the same checks the run makes — as an outcome card (the refused as a list to read first, the rest as a table).
     *
     * @param  Collection<int, Employee>  $records
     * @param  array<string, mixed>  $state
     */
    private function bulkPreviewHtml(Collection $records, array $state): Outcome
    {
        $user = auth()->user();
        $assignments = app(SalaryAssignments::class);
        $output = app(CompensationOutput::class);
        $input = AssignSalaryOperation::input($state);
        $rows = [];
        $refused = [];
        $from = null;
        foreach ($records->take(25) as $employee) {
            $who = $employee->auditLabel();
            try {
                if (($refusal = $assignments->refusal($employee, $user)) !== null) {
                    throw new RuntimeException($refusal);
                }
                $proposal = $assignments->check($employee, $input, $user);
            } catch (RuntimeException $e) {
                $refused[] = $who.': '.$e->getMessage();

                continue;
            }
            $current = $output->on($employee, $proposal['effective_from']);
            $from ??= Carbon::parse($proposal['effective_from'])->format('j M Y');
            $rows[] = [
                $who,
                ($current?->structureCode ?? '—').' → '.(SalaryStructure::query()->whereKey($proposal['salary_structure_id'])->value('code') ?? '—'),
                $current !== null ? $current->currency.' '.number_format($current->ctcAnnual, 2) : '—',
                $proposal['currency'].' '.number_format((float) $proposal['ctc_annual'], 2),
            ];
        }
        $checked = min($records->count(), 25);
        $more = $records->count() - $checked;

        $outcome = Outcome::make('Each employee before and after')->data('assign-preview')
            ->data('preview-ok', (string) count($rows))->data('preview-refused', (string) count($refused))
            ->headline(match (true) {
                count($rows) === $checked && $checked === 1 => 'The employee',
                count($rows) === $checked => 'All '.$checked.' employees',
                default => count($rows).' of the '.$checked.' employees',
            }.($more > 0 ? ' checked here ' : ' ').(count($rows) === 1 ? 'gets' : 'get').' the proposal'.($from !== null ? ' from '.$from : '').'.',
                $more > 0 ? 'The first '.$checked.' of the '.$records->count().' selected are checked here.' : null);
        if ($refused !== []) {
            $outcome->status('warning', 'Some would be refused')
                ->problems('warning', count($refused).' '.str('employee')->plural(count($refused)).' would be refused', $refused, 'They get no proposal; the run lists each one with the reason.');
        } elseif ($more === 0 && $rows !== []) {
            $outcome->status('clear', 'Every check passed');
        }
        if ($rows !== []) {
            $outcome->table('Proposal per employee', ['Employee', 'Structure', 'Annual CTC in force', 'Annual CTC proposed'], $rows, numeric: [2, 3]);
        }
        if ($more > 0) {
            $outcome->note($more.' more '.str('employee')->plural($more).', each checked when the run starts.', 'check', 'Not shown here:');
        }

        return $outcome->note('For an employee\'s breakup, deductions estimate and checks, use "Assign salary" on their row.', 'info');
    }

    private function afterChange(): void
    {
        $this->rows = [];
        $this->flushCachedTableRecords();
    }
}
