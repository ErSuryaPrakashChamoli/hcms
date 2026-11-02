<?php

namespace App\Filament\Pages;

use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Audit\Services\AuditRecorder;
use App\Domain\Bgv\Models\BgvCase;
use App\Domain\Documents\Models\DocumentType;
use App\Domain\Documents\Models\EmployeeDocument;
use App\Domain\Documents\Services\DocumentRules;
use App\Domain\Employment\Models\Employee;
use App\Domain\Employment\Models\EmployeePosition;
use App\Domain\Employment\Models\ReportingRelationship;
use App\Domain\Employment\Services\UpcomingJoiners as Joiners;
use App\Domain\Experience\Services\BulkRuns;
use App\Domain\Lifecycle\Enums\LifecycleState;
use App\Filament\Resources\Employees\EmployeeResource;
use App\Filament\Support\EmployeeFilters;
use App\Filament\Support\Pages\Concerns\HasRegisterTools;
use App\Filament\Support\Pages\RegisterTablePage;
use App\Filament\Support\UpcomingJoinerActions;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\DatePicker;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Enums\FiltersLayout;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\Indicator;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use UnitEnum;

/**
 * HROne parity WO-4 (matrix 23 §23.3.1 #1, #9, #16, "New hires (upcoming join)"): the people hired before their first day, with
 * the expected joining date, days to join, onboarding progress, background check and documents still pending; the joining
 * status filter (joining / not joining); WO-2's codes and organisation filters (on the planned position: joiners have no
 * current position before day one), saved views, the report link and an audited CSV. Rows: change the joining date, mark as
 * not joining, download all documents, send the profile-completion mail (UpcomingJoinerActions). Inside the viewer's access
 * scope. Opens for employee.view with one joiner right (lifecycle, onboarding or hiring).
 */
class UpcomingJoiners extends RegisterTablePage
{
    use HasRegisterTools;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUserPlus;

    protected static string|UnitEnum|null $navigationGroup = 'People';

    protected static ?string $navigationLabel = 'Upcoming joiners';

    protected static ?string $title = 'Upcoming joiners';

    protected static ?string $slug = 'upcoming-joiners';

    protected static ?int $navigationSort = 3;

    protected string $view = 'filament.pages.upcoming-joiners';

    public const REGISTER = 'upcoming_joiners';

    public const JOINER_PERMISSIONS = ['employee.lifecycle', 'employee.create', 'onboarding.view', 'onboarding.manage'];

    /** @var array<int, array{missing: int, to_verify: int}> per employee on this page */
    private array $documentsMemo = [];

    /** @var list<int>|null mandatory-for-onboarding document type ids */
    private ?array $mandatoryTypes = null;

    public static function canAccess(): bool
    {
        $user = auth()->user();
        if ($user === null || ! $user->hasPermission('employee.view')) {
            return false;
        }
        foreach (self::JOINER_PERMISSIONS as $permission) {
            if ($user->hasPermission($permission)) {
                return true;
            }
        }

        return false;
    }

    public static function registerKey(): string
    {
        return self::REGISTER;
    }

    public static function presetViews(): array
    {
        $today = now()->startOfDay();

        return [
            'this_week' => ['label' => 'Joining in the next 7 days', 'filters' => ['joining_status' => ['value' => 'joining'], 'expected' => ['from' => $today->toDateString(), 'until' => $today->copy()->addDays(7)->toDateString()]]],
            'this_month' => ['label' => 'Joining this month', 'filters' => ['joining_status' => ['value' => 'joining'], 'expected' => ['from' => $today->copy()->startOfMonth()->toDateString(), 'until' => $today->copy()->endOfMonth()->toDateString()]]],
            'overdue' => ['label' => 'Joining date passed, not marked as joined', 'filters' => ['joining_status' => ['value' => 'joining'], 'expected' => ['until' => $today->copy()->subDay()->toDateString()]]],
            'not_joining' => ['label' => 'Not joining', 'filters' => ['joining_status' => ['value' => 'not_joining']]],
        ];
    }

    public static function registerReports(): array
    {
        return ['upcoming_joiners'];
    }

    public function getSubheading(): ?string
    {
        return 'People hired before their first day. Change a joining date, mark someone as not joining, download their documents or ask them for missing details; "Mark as joined" is on the Employee 360.';
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);
    }

    public function table(Table $table): Table
    {
        $user = auth()->user();

        return $table
            ->query(fn (): Builder => Joiners::query('all'))
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['person', 'positions.designation', 'positions.department', 'positions.location', 'onboardingPlan', 'latestBgvCase',
                'reportingRelationships' => fn ($r) => $r->where('is_primary', true)->with('manager.person')]))
            ->columns([
                TextColumn::make('employee_code')->label('Code')->sortable(),
                TextColumn::make('person.display_name')->label('Name')->searchable(query: EmployeeFilters::search())->weight('medium')->wrap(),
                TextColumn::make('expected_joining')->label('Expected joining')->date('d M Y')->placeholder('—')
                    ->state(fn (Employee $record) => Joiners::expectedDate($record))
                    ->sortable(query: fn (Builder $query, string $direction) => $query->orderByRaw(Joiners::expectedDateSql().' '.($direction === 'desc' ? 'desc' : 'asc'))->orderBy('employees.id')),
                TextColumn::make('days_to_join')->label('Days to join')->badge()
                    ->state(fn (Employee $record) => $this->daysLabel($record))
                    ->color(fn (Employee $record) => $this->daysColor($record)),
                TextColumn::make('planned_designation')->label('Designation')->placeholder('—')->toggleable()
                    ->state(fn (Employee $record) => $this->plannedPosition($record)?->designation?->name),
                TextColumn::make('planned_department')->label('Department')->placeholder('—')->toggleable()
                    ->state(fn (Employee $record) => $this->plannedPosition($record)?->department?->name),
                TextColumn::make('planned_location')->label('Location')->placeholder('—')->toggleable(isToggledHiddenByDefault: true)
                    ->state(fn (Employee $record) => $this->plannedPosition($record)?->location?->name),
                TextColumn::make('planned_manager')->label('Reporting manager')->placeholder('—')->toggleable(isToggledHiddenByDefault: true)
                    ->state(fn (Employee $record) => $this->plannedManager($record)),
                TextColumn::make('onboarding_progress')->label('Onboarding')->placeholder('Not started')
                    ->state(fn (Employee $record) => $record->onboardingPlan === null ? null
                        : ($record->onboardingPlan->status === 'in_progress' ? $record->onboardingPlan->progress.'% done' : (string) ($record->onboardingPlan::STATUSES[$record->onboardingPlan->status] ?? $record->onboardingPlan->status))),
                TextColumn::make('bgv_status')->label('Background check')->badge()->color('gray')->placeholder('Not initiated')
                    ->visible(fn () => $user?->hasPermission('bgv.view') ?? false)
                    ->state(fn (Employee $record) => ($case = $record->latestBgvCase) === null ? null
                        : (BgvCase::STATUSES[$case->status] ?? $case->status).($case->overall_result && $case->overall_result !== 'pending' ? ' · '.(BgvCase::RESULTS[$case->overall_result] ?? $case->overall_result) : '')),
                TextColumn::make('documents_pending')->label('Documents pending')
                    ->visible(fn () => $user?->hasPermission('document.view') ?? false)
                    ->state(fn (Employee $record) => $this->documentsLabel($record))
                    ->color(fn (Employee $record) => $this->documentsLabel($record) === 'None' ? 'gray' : 'warning'),
                TextColumn::make('lifecycle_state')->label('Status')->badge(),
                TextColumn::make('not_joining')->label('Not joining')->placeholder('—')->toggleable(isToggledHiddenByDefault: true)->wrap()
                    ->state(fn (Employee $record) => $record->lifecycle_state === LifecycleState::NotJoined ? $this->notJoiningLabel($record) : null),
                TextColumn::make('work_email')->label('Work e-mail')->placeholder('—')->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('joining_status')->label('Joining status')->options(Joiners::JOINING_STATUSES)->default('joining')->selectablePlaceholder(false)
                    ->query(fn (Builder $query, array $data) => match ($data['value'] ?? 'joining') {
                        'all' => $query,
                        'not_joining' => $query->where('employees.lifecycle_state', LifecycleState::NotJoined->value),
                        default => $query->whereIn('employees.lifecycle_state', Joiners::preJoiningStates()),
                    }),
                $this->expectedDateFilter(),
                EmployeeFilters::codes(),
                EmployeeFilters::organisation(positionRelation: 'positions'),
                EmployeeFilters::backgroundCheck()->visible(fn () => $user?->hasPermission('bgv.view') ?? false),
            ])
            ->filtersLayout(FiltersLayout::AboveContentCollapsible)
            ->persistFiltersInSession()
            ->defaultSort('expected_joining')
            ->recordActions([
                Action::make('open')->label('Open')->icon(Heroicon::OutlinedArrowTopRightOnSquare)->color('gray')
                    ->url(fn (Employee $record) => EmployeeResource::getUrl('view', ['record' => $record])),
                ActionGroup::make(UpcomingJoinerActions::all())->label('Actions')->icon(Heroicon::OutlinedEllipsisVertical),
            ])
            ->paginated([10, 25, 50, 100]);
    }

    protected function getHeaderActions(): array
    {
        return [
            ...$this->registerToolActions(),
            Action::make('exportCsv')->label('Export')->icon(Heroicon::OutlinedArrowDownTray)->color('gray')
                ->action(fn () => $this->exportCsv()),
            Action::make('hire')->label('Hire employee')->icon(Heroicon::OutlinedUserPlus)
                ->visible(fn () => EmployeeResource::canCreate())
                ->url(fn () => EmployeeResource::getUrl('create')),
        ];
    }

    /** The register as CSV: what the viewer sees with the filters applied (at most the bulk limit), audited as an EXPORT. */
    public function exportCsv(): mixed
    {
        $user = auth()->user();
        abort_unless(static::canAccess(), 403);
        $employees = $this->getFilteredSortedTableQuery()->limit(BulkRuns::maxRecords())->get();
        $bgv = $user->hasPermission('bgv.view');
        $documents = $user->hasPermission('document.view');

        $out = fopen('php://temp', 'w+');
        fputcsv($out, array_values(array_filter(['Employee code', 'Name', 'Expected joining date', 'Days to join', 'Designation', 'Department', 'Location', 'Reporting manager', 'Onboarding progress',
            $bgv ? 'Background check' : null, $documents ? 'Documents missing' : null, $documents ? 'Documents to verify' : null, 'Status', 'Not joining reason'])));
        foreach ($employees as $employee) {
            $position = $this->plannedPosition($employee);
            $docs = $documents ? $this->documentCounts($employee) : null;
            $row = [
                $employee->employee_code, $employee->person?->display_name, Joiners::expectedDate($employee)?->toDateString(), Joiners::daysToJoin($employee),
                $position?->designation?->name, $position?->department?->name, $position?->location?->name, $this->plannedManager($employee),
                $employee->onboardingPlan === null ? null : $employee->onboardingPlan->progress.'% ('.$employee->onboardingPlan->status.')',
            ];
            if ($bgv) {
                $row[] = $employee->latestBgvCase === null ? 'Not initiated' : $employee->latestBgvCase->status.($employee->latestBgvCase->overall_result ? ' / '.$employee->latestBgvCase->overall_result : '');
            }
            if ($docs !== null) {
                array_push($row, $docs['missing'], $docs['to_verify']);
            }
            array_push($row, $employee->lifecycle_state->getLabel(), $employee->lifecycle_state === LifecycleState::NotJoined ? $this->notJoiningLabel($employee) : null);
            fputcsv($out, array_map(fn ($v) => self::cell($v), $row));
        }
        rewind($out);
        $csv = (string) stream_get_contents($out);
        fclose($out);

        app(AuditRecorder::class)->record(AuditAction::Export, 'employment', null, [], null, actor: $user, entityLabel: 'Upcoming joiners', metadata: [
            'register' => self::REGISTER, 'rows' => $employees->count(), 'filters' => array_keys(array_filter((array) $this->tableFilters, fn ($v) => filled($v))),
        ]);

        return response()->streamDownload(fn () => print ($csv), 'upcoming-joiners-'.now()->toDateString().'.csv', ['Content-Type' => 'text/csv']);
    }

    /** A spreadsheet never runs a cell as a formula. */
    private static function cell(mixed $value): mixed
    {
        return is_string($value) && $value !== '' && in_array($value[0], ['=', '+', '-', '@', "\t", "\r"], true) ? "'".$value : $value;
    }

    private function expectedDateFilter(): Filter
    {
        return Filter::make('expected')->label('Expected joining date')->columns(2)
            ->schema([
                DatePicker::make('from')->label('Expected joining from')->native(false),
                DatePicker::make('until')->label('Expected joining until')->native(false),
            ])
            ->query(function (Builder $query, array $data): Builder {
                $from = filled($data['from'] ?? null) ? Carbon::parse($data['from'])->toDateString() : null;
                $until = filled($data['until'] ?? null) ? Carbon::parse($data['until'])->toDateString() : null;

                return $query
                    ->when($from !== null, fn (Builder $q) => $q->whereRaw(Joiners::expectedDateSql().' >= ?', [$from]))
                    ->when($until !== null, fn (Builder $q) => $q->whereRaw(Joiners::expectedDateSql().' <= ?', [$until.' 23:59:59']));
            })
            ->indicateUsing(function (array $data): array {
                $parts = array_filter([
                    filled($data['from'] ?? null) ? 'from '.Carbon::parse($data['from'])->format('j M Y') : null,
                    filled($data['until'] ?? null) ? 'until '.Carbon::parse($data['until'])->format('j M Y') : null,
                ]);

                return $parts === [] ? [] : [Indicator::make('Expected joining '.implode(' ', $parts))];
            });
    }

    /** The position the joiner was hired into (the latest row; they have no current one before day one). */
    private function plannedPosition(Employee $employee): ?EmployeePosition
    {
        return $employee->positions->first();
    }

    private function plannedManager(Employee $employee): ?string
    {
        /** @var ReportingRelationship|null $line */
        $line = $employee->reportingRelationships->sortByDesc(fn (ReportingRelationship $r) => $r->effective_from?->toDateString().'-'.str_pad((string) $r->id, 10, '0', STR_PAD_LEFT))->first();

        return $line?->manager?->person?->display_name;
    }

    private function daysLabel(Employee $employee): ?string
    {
        if ($employee->lifecycle_state === LifecycleState::NotJoined) {
            return 'Not joining';
        }
        $days = Joiners::daysToJoin($employee);

        return match (true) {
            $days === null => null,
            $days === 0 => 'Today',
            $days === 1 => 'Tomorrow',
            $days > 1 => "In {$days} days",
            default => abs($days).' day(s) overdue',
        };
    }

    private function daysColor(Employee $employee): string
    {
        $days = $employee->lifecycle_state === LifecycleState::NotJoined ? null : Joiners::daysToJoin($employee);

        return match (true) {
            $days === null => 'gray',
            $days < 0 => 'danger',
            $days <= 7 => 'warning',
            default => 'info',
        };
    }

    /** @return array{missing: int, to_verify: int} */
    private function documentCounts(Employee $employee): array
    {
        if (isset($this->documentsMemo[$employee->id])) {
            return $this->documentsMemo[$employee->id];
        }
        $this->mandatoryTypes ??= DocumentType::query()->where('status', 'active')->where('mandatory_for_onboarding', true)->pluck('id')->map(fn ($id) => (int) $id)->all();
        $current = $this->mandatoryTypes === [] ? [] : EmployeeDocument::query()->where('employee_id', $employee->id)->whereIn('status', DocumentRules::CURRENT)
            ->whereIn('document_type_id', $this->mandatoryTypes)->distinct()->pluck('document_type_id')->map(fn ($id) => (int) $id)->all();

        return $this->documentsMemo[$employee->id] = [
            'missing' => count(array_diff($this->mandatoryTypes, $current)),
            'to_verify' => EmployeeDocument::query()->where('employee_id', $employee->id)->where('status', 'pending')->count(),
        ];
    }

    private function documentsLabel(Employee $employee): string
    {
        $counts = $this->documentCounts($employee);
        $parts = array_filter([
            $counts['missing'] > 0 ? $counts['missing'].' missing' : null,
            $counts['to_verify'] > 0 ? $counts['to_verify'].' to verify' : null,
        ]);

        return $parts === [] ? 'None' : implode(' · ', $parts);
    }

    private function notJoiningLabel(Employee $employee): ?string
    {
        $reason = app(Joiners::class)->notJoining($employee);

        return $reason === null ? null : $reason['label'].($reason['note'] ? ' — '.$reason['note'] : '').($reason['on'] ? ' ('.Carbon::parse($reason['on'])->format('d M Y').')' : '');
    }
}
