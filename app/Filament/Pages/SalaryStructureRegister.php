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
use App\Domain\Employment\Actions\ChangeStatutoryApplicabilityAction;
use App\Domain\Employment\Models\Employee;
use App\Domain\Employment\Services\SensitiveAccessAuditor;
use App\Domain\Experience\BulkOperations\AssignSalaryOperation;
use App\Domain\Experience\Services\BulkOperations;
use App\Domain\Experience\Services\BulkRuns;
use App\Domain\Identity\Services\AccessScopes;
use App\Domain\Letters\Services\Letters;
use App\Domain\Payroll\Services\SalaryProposalPreview;
use App\Filament\Resources\BulkRuns\BulkRunResource;
use App\Filament\Resources\CompensationChanges\CompensationChangeResource;
use App\Filament\Resources\EmployeePaymentModes\EmployeePaymentModeResource;
use App\Filament\Resources\Employees\EmployeeResource;
use App\Filament\Support\EmployeeFilters;
use App\Filament\Support\Pages\Concerns\HasRegisterTools;
use App\Filament\Support\Pages\RegisterTablePage;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Grid;
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
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Pagination\Paginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\HtmlString;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;
use UnitEnum;

/**
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

    /** @var array<int, array{in_force: ?CompensationSnapshot, request: ?CompensationChange, regime: ?string}> this request's rows */
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
                TextColumn::make('salary_state')->label('State')->badge()
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
                Action::make('open360')->label('Employee 360')->icon(Heroicon::OutlinedUser)->color('gray')
                    ->visible(fn (Employee $record) => EmployeeResource::canView($record))
                    ->url(fn (Employee $record) => EmployeeResource::getUrl('view', ['record' => $record])),
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

    /** @return array{in_force: ?CompensationSnapshot, request: ?CompensationChange, regime: ?string} */
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
        foreach ($ids as $id) {
            $this->rows[$id] = ['in_force' => $inForce[$id] ?? null, 'request' => $requests[$id] ?? null, 'regime' => $regimes[$id] ?? null];
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
        return preg_match('/^[=+\-@]/', $text) === 1 && ! is_numeric($text) ? "'".$text : $text;
    }

    // --- Assign salary (one employee) ------------------------------------------------------------------------------

    private function assignAction(): Action
    {
        return Action::make('assign')->label('Assign salary')->icon(Heroicon::OutlinedBanknotes)
            ->visible(fn (Employee $record) => $this->canPropose() && (int) $record->user_id !== (int) auth()->id())
            ->modalHeading(fn (Employee $record) => 'Assign salary · '.$record->employee_code.' · '.($record->person?->display_name ?? ''))
            ->modalWidth(Width::FiveExtraLarge)
            ->modalSubmitActionLabel('Propose')
            ->mountUsing(function (Employee $record, $schema) {
                // Seeing the statutory details is a sensitive read, audited as on Employee 360.
                if (auth()->user()->can('viewSensitive', $record)) {
                    app(SensitiveAccessAuditor::class)->recordView($record, 'statutory', 'assign_salary');
                }
                $schema?->fill();
            })
            ->steps(fn (Employee $record) => $this->assignSteps($record))
            ->action(fn (Employee $record, array $data) => $this->assign($record, $data));
    }

    /** @return list<Step> */
    private function assignSteps(Employee $record): array
    {
        $current = app(CompensationOutput::class)->on($record, today());

        return [
            Step::make('Employee statutory')->description('PF, ESI, PT and tax regime')->schema($this->statutorySchema($record)),
            Step::make('Salary details')->description('Structure, CTC and the date')->schema(AssignSalaryOperation::fields($current)),
            Step::make('Preview')->description('Breakup, deductions estimate, checks')->schema([
                View::make('filament.payroll.salary-proposal-preview')->columnSpanFull()
                    ->viewData(fn (Get $get) => $this->previewData($record, $this->formState($get))),
                Actions::make([
                    Action::make('printBreakup')->label('Print breakup')->icon(Heroicon::OutlinedPrinter)->color('gray')
                        ->action(fn (Get $get) => $this->printProposal((int) $record->id, $this->formState($get))),
                ]),
            ]),
            Step::make('Review')->description('What will be proposed')->schema([
                Text::make(fn (Get $get) => $this->reviewHtml($record, $this->formState($get))),
            ]),
        ];
    }

    /** @return list<mixed> step 1: the statutory details as Employee 360 shows them, changed through its action */
    private function statutorySchema(Employee $record): array
    {
        $user = auth()->user();
        if (! $user->can('viewSensitive', $record)) {
            return [Text::make('Statutory details need employee.sensitive.view. Payroll applies what Employee 360 holds (PF, ESI, PT and the tax regime); ask HR to check them before the salary takes effect.')];
        }
        $detail = $record->statutoryDetail()->first();
        $yes = fn (?bool $value) => $value === null ? '—' : ($value ? 'Yes' : 'No');
        $onFile = fn (?string $value) => filled($value) ? 'On file' : 'Missing';
        $facts = [
            'PF applicable' => $yes($detail?->pf_applicable), 'ESI applicable' => $yes($detail?->esic_applicable), 'PT applicable' => $yes($detail?->pt_applicable),
            'PT state' => $detail?->pt_state_code ?: '—', 'Tax regime (statutory details)' => $detail?->tax_regime ? ucfirst((string) $detail->tax_regime) : '—',
            'PAN' => $onFile($detail?->pan), 'UAN' => $onFile($detail?->uan), 'ESI number' => $onFile($detail?->esic_number),
        ];
        $html = '<dl class="grid grid-cols-1 gap-x-6 gap-y-1 text-sm sm:grid-cols-2" data-statutory-summary>'
            .collect($facts)->map(fn ($v, $k) => '<div class="flex justify-between"><dt class="text-gray-500 dark:text-gray-400">'.e($k).'</dt><dd>'.e($v).'</dd></div>')->implode('')
            .'</dl>'.($detail === null ? '<p class="mt-2 text-sm text-warning-600">No statutory details on file: payroll applies PF and PT by default.</p>' : '');
        $schema = [Text::make(new HtmlString($html))];
        if (! $user->can('updateSensitive', $record)) {
            return [...$schema, Text::make('Changing statutory details needs employee.sensitive.update.')];
        }

        return [...$schema,
            Toggle::make('change_statutory')->label('Change statutory details')->default(false)->live(),
            Grid::make(['default' => 1, 'md' => 2])->visible(fn (Get $get) => (bool) $get('change_statutory'))->schema([
                Toggle::make('pf_applicable')->label('PF applicable')->default($detail?->pf_applicable ?? true),
                Toggle::make('esic_applicable')->label('ESI applicable')->default((bool) ($detail?->esic_applicable ?? false)),
                Toggle::make('pt_applicable')->label('PT applicable')->default($detail?->pt_applicable ?? true),
                Select::make('tax_regime')->label('Tax regime')->options(['old' => 'Old regime', 'new' => 'New regime'])->default($detail?->tax_regime),
                TextInput::make('pt_state_code')->label('PT state')->maxLength(16)->default($detail?->pt_state_code),
                Textarea::make('statutory_reason')->label('Reason for change')->required(fn (Get $get) => (bool) $get('change_statutory'))->maxLength(1000)->columnSpanFull(),
            ]),
        ];
    }

    /** @return array<string, mixed> the assign form's values the services read (step 2), plus the statutory toggle */
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
     * The preview step: the breakup for this employee, the read-only statutory estimate, the salary checks and the payment
     * mode — or why the proposal would be refused.
     *
     * @param  array<string, mixed>  $state
     * @return array<string, mixed>
     */
    public function previewData(Employee $employee, array $state): array
    {
        $user = auth()->user();
        $assignments = app(SalaryAssignments::class);
        try {
            if (($refusal = $assignments->refusal($employee, $user)) !== null) {
                throw new RuntimeException($refusal);
            }
            $proposal = $assignments->check($employee, AssignSalaryOperation::input($state), $user);
            $segment = $assignments->segment($employee, $proposal);
            if ($segment === null) {
                throw new RuntimeException('The structure has no approved version on the effective date.');
            }
            $result = app(SalaryProposalPreview::class)->for($employee, $segment);
        } catch (RuntimeException $e) {
            return ['failure' => 'This proposal would be refused: '.$e->getMessage(), 'result' => null, 'proposal' => null, 'current' => null, 'payModesUrl' => null];
        } catch (Throwable $e) {
            report($e);

            return ['failure' => 'The preview could not be worked out from these values.', 'result' => null, 'proposal' => null, 'current' => null, 'payModesUrl' => null];
        }

        return [
            'failure' => null, 'result' => $result, 'proposal' => $proposal,
            'current' => app(CompensationOutput::class)->on($employee, $proposal['effective_from']),
            'payModesUrl' => EmployeePaymentModeResource::canViewAny() ? EmployeePaymentModeResource::getUrl('index', ['tableSearch' => $employee->employee_code]) : null,
        ];
    }

    /** "Print breakup": the proposal's salary annexure as a printable page in the letter studio's layout (audited). */
    public function printProposal(int $employeeId, array $state): ?StreamedResponse
    {
        $user = auth()->user();
        $employee = static::population()->with('person')->whereKey($employeeId)->first();
        abort_unless(static::canAccess() && $this->canPropose() && $employee !== null && app(AccessScopes::class)->allows($user, $employee), 403);
        $assignments = app(SalaryAssignments::class);
        try {
            $proposal = $assignments->check($employee, AssignSalaryOperation::input($state), $user);
            $segment = $assignments->segment($employee, $proposal) ?? throw new RuntimeException('The structure has no approved version on the effective date.');
            $file = app(Letters::class)->breakup($employee, $segment, $user);
        } catch (RuntimeException $e) {
            Notification::make()->danger()->title('Breakup not printed')->body($e->getMessage())->persistent()->send();

            return null;
        }

        return response()->streamDownload(fn () => print ($file['content']), $file['name'], ['Content-Type' => 'text/html; charset=UTF-8']);
    }

    /** @param  array<string, mixed>  $state */
    private function reviewHtml(Employee $employee, array $state): HtmlString
    {
        $user = auth()->user();
        try {
            if (($refusal = app(SalaryAssignments::class)->refusal($employee, $user)) !== null) {
                throw new RuntimeException($refusal);
            }
            $proposal = app(SalaryAssignments::class)->check($employee, AssignSalaryOperation::input($state), $user);
        } catch (RuntimeException $e) {
            return new HtmlString('<p class="text-sm text-danger-600" data-review="refused">This proposal would be refused: '.e($e->getMessage()).'</p>');
        }
        $current = app(CompensationOutput::class)->on($employee, $proposal['effective_from']);
        $structure = SalaryStructure::query()->find($proposal['salary_structure_id']);
        $lines = [
            'Change' => config("peopleos.compensation.change_types.{$proposal['change_type']}", $proposal['change_type']).' from '.Carbon::parse($proposal['effective_from'])->format('j M Y'),
            'Salary structure' => ($structure?->name ?? '—').($current !== null && $current->structureId !== (int) $proposal['salary_structure_id'] ? ' (was '.$current->structureCode.')' : ''),
            'Annual CTC' => $proposal['currency'].' '.number_format((float) $proposal['ctc_annual'], 2)
                .($current !== null ? ' (was '.number_format($current->ctcAnnual, 2).($proposal['increase_percent'] !== null ? ', '.($proposal['increase_percent'] >= 0 ? '+' : '').$proposal['increase_percent'].'%' : '').')' : ''),
            'Statutory details' => ($state['change_statutory'] ?? false) ? 'Changed with this proposal (audited, through the Employee 360 action)' : 'Unchanged',
            'Next' => ($state['submit'] ?? true) ? 'Submitted for review; then approval and execution by other people' : 'Saved as a draft for you to submit',
        ];

        return new HtmlString('<dl class="grid grid-cols-1 gap-y-1 text-sm" data-review="ok">'
            .collect($lines)->map(fn ($v, $k) => '<div class="flex flex-wrap justify-between gap-2"><dt class="text-gray-500 dark:text-gray-400">'.e($k).'</dt><dd>'.e($v).'</dd></div>')->implode('')
            .'</dl><p class="mt-2 text-xs text-gray-500">Nothing is approved or paid here: the proposal joins the salary structure requests, and payroll uses it only once it is executed and in force.</p>');
    }

    /** @param  array<string, mixed>  $data */
    private function assign(Employee $record, array $data): void
    {
        $user = auth()->user();
        try {
            $change = DB::transaction(function () use ($record, $data, $user) {
                if ((bool) ($data['change_statutory'] ?? false)) {
                    if (! $user->can('updateSensitive', $record)) {
                        throw new RuntimeException('Changing statutory details needs employee.sensitive.update.');
                    }
                    app(ChangeStatutoryApplicabilityAction::class)->handle($record, array_intersect_key($data, array_flip(ChangeStatutoryApplicabilityAction::FIELDS)), $user, (string) ($data['statutory_reason'] ?? ''));
                }

                return app(SalaryAssignments::class)->propose($record, AssignSalaryOperation::input($data), $user, (bool) ($data['submit'] ?? true));
            });
            Notification::make()->success()
                ->title($change->status === 'submitted' ? 'Salary proposed and submitted for review' : 'Salary proposal saved as a draft')
                ->body('Reference '.$change->reference.'. A reviewer, an approver and an executor still decide it.')
                ->actions(CompensationChangeResource::canAccess() ? [Action::make('requests')->label('Salary structure requests')->url(CompensationChangeResource::getUrl('index'))] : [])
                ->send();
            $this->afterChange();
        } catch (RuntimeException|AuthorizationException|ValidationException $e) {
            Notification::make()->danger()->title('Salary not proposed')->body($e->getMessage())->persistent()->send();
        }
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
                        Text::make('Each employee gets one compensation change, '.'submitted for review unless you chose a draft. Employees with a change already pending, your own record and employees outside your access are refused and listed with the reason on the bulk run.'),
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
     * Each selected employee (the first 25): the CTC in force and the CTC proposed, or why the employee will be refused —
     * the same checks the run makes.
     *
     * @param  Collection<int, Employee>  $records
     * @param  array<string, mixed>  $state
     */
    private function bulkPreviewHtml(Collection $records, array $state): HtmlString
    {
        $user = auth()->user();
        $assignments = app(SalaryAssignments::class);
        $output = app(CompensationOutput::class);
        $input = AssignSalaryOperation::input($state);
        $items = [];
        foreach ($records->take(25) as $employee) {
            $who = e($employee->employee_code.' · '.($employee->person?->display_name ?? ''));
            try {
                if (($refusal = $assignments->refusal($employee, $user)) !== null) {
                    throw new RuntimeException($refusal);
                }
                $proposal = $assignments->check($employee, $input, $user);
            } catch (RuntimeException $e) {
                $items[] = '<li data-preview="refused">'.$who.': <span class="text-danger-600">'.e($e->getMessage()).'</span></li>';

                continue;
            }
            $current = $output->on($employee, $proposal['effective_from']);
            $items[] = '<li data-preview="ok"><strong>'.$who.'</strong>: '.e(($current?->structureCode ?? '—').' → '.(SalaryStructure::query()->whereKey($proposal['salary_structure_id'])->value('code') ?? '—'))
                .' · annual CTC '.e(($current !== null ? number_format($current->ctcAnnual, 2) : '—').' → '.number_format((float) $proposal['ctc_annual'], 2).' '.$proposal['currency'])
                .' from '.e(Carbon::parse($proposal['effective_from'])->format('j M Y')).'</li>';
        }
        $more = $records->count() > 25 ? '<p class="text-xs text-gray-500">… and '.($records->count() - 25).' more, each checked when the run starts.</p>' : '';

        return new HtmlString('<div class="space-y-2 text-sm" data-assign-preview><ul class="list-disc space-y-1 ps-5">'.implode('', $items).'</ul>'.$more
            .'<p class="text-xs text-gray-500">For an employee\'s breakup, deductions estimate and checks, use "Assign salary" on their row.</p></div>');
    }

    private function afterChange(): void
    {
        $this->rows = [];
        $this->flushCachedTableRecords();
    }
}
