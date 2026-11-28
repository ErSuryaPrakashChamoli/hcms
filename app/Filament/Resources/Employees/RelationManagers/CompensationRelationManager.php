<?php

namespace App\Filament\Resources\Employees\RelationManagers;

use App\Domain\Compensation\Models\EmployeeSalaryAssignment;
use App\Domain\Compensation\Services\CompensationAccess;
use App\Domain\Compensation\Services\CompensationPlanning;
use App\Domain\Compensation\Services\CompensationRanges;
use App\Domain\Compensation\Services\SalaryRevisions;
use App\Domain\Employment\Models\EmployeePosition;
use App\Domain\Employment\Services\SensitiveAccessAuditor;
use App\Domain\Payroll\Services\SalaryArrears;
use App\Domain\Workforce\Models\Position;
use App\Filament\Support\CompensationActions;
use App\Filament\Support\WorkforceActions;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Employee 360 → Rewards → Salary history (Phase 11; was the Phase 4 "Salary" tab, then "Compensation"). The canonical,
 * effective-dated compensation history: current, past and scheduled rows with their components, each with its change
 * against the salary before it (owner, 2026-11-27) and a line-by-line Compare with the audit trail of the change behind
 * it. Opening it is a sensitive read and is audited. Nothing here writes compensation: the salary is assigned, revised or
 * edited from Current salary above it (one wizard, proposals only), and "Propose from position" prefills a draft — every
 * proposal still needs review, approval and execution by other people.
 *
 * Full readers (compensation.view, in scope) see every row (corrected and cancelled ones too) with
 * reasons; managers (compensation.team) and the employee (compensation.self) see approved rows only.
 */
class CompensationRelationManager extends RelationManager
{
    protected static string $relationship = 'salaryAssignments';

    protected static ?string $title = 'Salary history';

    /** @var array<int, array{previous: ?float, change: ?float, percent: ?float}>|null this request's change per row */
    private ?array $changesByRow = null;

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return app(CompensationAccess::class)->mayViewCompensation(auth()->user(), $ownerRecord);
    }

    public function mount(): void
    {
        app(SensitiveAccessAuditor::class)->recordView($this->getOwnerRecord(), 'compensation');
    }

    private function isFull(): bool
    {
        return app(CompensationAccess::class)->level(auth()->user(), $this->getOwnerRecord()) === 'full';
    }

    public function table(Table $table): Table
    {
        $full = $this->isFull();

        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['structure', 'approver'])->when(! $full, fn (Builder $q) => $q->where('status', 'active')))
            ->columns([
                TextColumn::make('effective_from')->label('From')->date()->sortable(),
                TextColumn::make('effective_to')->label('To')->date()->placeholder('Open'),
                TextColumn::make('in_force')->label('')->badge()
                    ->state(fn (EmployeeSalaryAssignment $record) => ! $record->isActive() ? EmployeeSalaryAssignment::STATUSES[$record->status] : ($record->effective_from->isFuture() ? 'Scheduled' : ($record->isEffectiveOn() ? 'Current' : 'Past')))
                    ->color(fn (string $state) => match ($state) {
                        'Current' => 'success', 'Scheduled' => 'info', 'Past' => 'gray', default => 'danger'
                    }),
                TextColumn::make('change_type')->label('Type')->badge()->color('gray')->formatStateUsing(fn (string $state) => config("peopleos.compensation.change_types.{$state}", EmployeeSalaryAssignment::CHANGE_TYPES[$state] ?? $state)),
                TextColumn::make('structure.name')->label('Structure'),
                TextColumn::make('ctc_annual')->label('Annual CTC')->numeric(2)->description(fn (EmployeeSalaryAssignment $record) => $record->currency),
                // Owner, 2026-11-27: how much each salary moved against the one before it (approved history only).
                TextColumn::make('change')->label('Change')->placeholder('—')
                    ->state(fn (EmployeeSalaryAssignment $record) => ($p = $this->changeOf($record)['percent'] ?? null) === null ? null : ($p > 0 ? '+' : '').number_format($p, 2).'%')
                    ->description(fn (EmployeeSalaryAssignment $record) => ($c = $this->changeOf($record)['change'] ?? null) === null ? null : ($c >= 0 ? '+' : '−').number_format(abs($c), 2).' a year')
                    ->color(fn (EmployeeSalaryAssignment $record) => match (true) {
                        ($this->changeOf($record)['percent'] ?? 0) > 0 => 'success', ($this->changeOf($record)['percent'] ?? 0) < 0 => 'warning', default => null
                    }),
                TextColumn::make('monthly')->label('Monthly')->state(fn (EmployeeSalaryAssignment $record) => $record->monthlyCtc())->numeric(2),
                TextColumn::make('variable_target_annual')->label('Variable target')->numeric(2)->placeholder('—')->toggleable(),
                TextColumn::make('component_values')->label('Fixed components (monthly)')->toggleable()
                    ->state(fn (EmployeeSalaryAssignment $record) => collect($record->component_values ?? [])->map(fn ($v, $k) => $k.' '.number_format((float) $v, 2))->implode(', ') ?: '—'),
                // Descriptive only (range position and compa-ratio against the applicable approved range).
                TextColumn::make('range')->label('Range position')->visible($full)->toggleable()
                    ->state(fn (EmployeeSalaryAssignment $record) => $record->isActive() && $record->isEffectiveOn() ? $this->rangeLabel($record) : null)->placeholder('—'),
                TextColumn::make('reason')->placeholder('—')->wrap()->toggleable()->visible($full),
                TextColumn::make('approver.name')->label('Approved by')->placeholder('—')->toggleable()->visible($full),
            ])
            ->defaultSort('effective_from', 'desc')
            ->recordActions([
                // The breakup line by line: for HR and managers; the employee's own view is My compensation (its switches decide).
                Action::make('compare')->label('Compare')->icon(Heroicon::OutlinedArrowsRightLeft)->color('gray')
                    ->visible(fn (EmployeeSalaryAssignment $record) => $record->isActive() && app(CompensationAccess::class)->level(auth()->user(), $this->getOwnerRecord()) !== 'self')
                    ->modalHeading(fn (EmployeeSalaryAssignment $record) => 'Salary from '.$record->effective_from->format('j M Y'))
                    ->modalDescription('Line by line against the salary before it, both on this date; with who proposed, reviewed, approved and executed it.')
                    ->modalWidth(Width::FourExtraLarge)->slideOver()
                    ->modalSubmitAction(false)->modalCancelActionLabel('Close')
                    ->modalContent(fn (EmployeeSalaryAssignment $record) => view('filament.compensation.salary-revision', [
                        'revision' => app(SalaryRevisions::class)->revision($this->getOwnerRecord(), (int) $record->id), 'full' => $full,
                        // Owner, 2026-11-27: what a back-dated change paid (or recovered) for the months already closed.
                        'arrears' => $full && $record->compensation_change_id ? SalaryArrears::recordedFor((int) $record->compensation_change_id) : [],
                    ])),
            ])
            ->headerActions([
                Action::make('proposeFromPosition')->label('Propose from position')->icon('heroicon-m-briefcase')->color('gray')
                    ->visible(fn () => auth()->user()->can('compensation.propose') && (int) $this->getOwnerRecord()->user_id !== (int) auth()->id())
                    ->schema([
                        Select::make('position_id')->label('Position')->required()->searchable()->options(fn () => WorkforceActions::positionOptions()),
                        DatePicker::make('effective_from')->native(false)->required()->default(now()->startOfMonth()->addMonth()),
                    ])
                    ->modalDescription('Prefills a draft from the position\'s approved pay range. It changes nobody\'s position and still needs review, approval and execution.')
                    ->action(fn (array $data) => CompensationActions::run(fn () => app(CompensationPlanning::class)->proposeFromPosition($this->getOwnerRecord(), Position::query()->findOrFail($data['position_id']), auth()->user(), ['effective_from' => $data['effective_from']]), fn ($c) => 'Draft compensation change '.$c->reference.' created')),
            ]);
    }

    /** @return array{previous: ?float, change: ?float, percent: ?float}|null the row's change against the salary before it */
    private function changeOf(EmployeeSalaryAssignment $record): ?array
    {
        $this->changesByRow ??= app(SalaryRevisions::class)->changesByAssignment($this->getOwnerRecord());

        return $this->changesByRow[(int) $record->id] ?? null;
    }

    private function rangeLabel(EmployeeSalaryAssignment $record): string
    {
        $held = EmployeePosition::query()->where('employee_id', $record->employee_id)->effectiveOn()->orderByDesc('effective_from')->first();
        if ($held?->grade_id === null) {
            return 'No grade';
        }
        $ranges = app(CompensationRanges::class);
        $p = $ranges->position((float) $record->ctc_annual, $ranges->rangeFor((int) $held->grade_id, null, $held->company_id, null, $held->designation_id, (int) $record->salary_structure_id, $record->currency), $record->currency);

        return $p['band'] === null ? $p['note'] : ucfirst($p['band']).($p['compa_ratio'] !== null ? ' · compa-ratio '.$p['compa_ratio'] : '').($p['range_position'] !== null ? ' · position '.$p['range_position'] : '');
    }
}
