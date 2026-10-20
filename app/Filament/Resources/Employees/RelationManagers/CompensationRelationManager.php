<?php

namespace App\Filament\Resources\Employees\RelationManagers;

use App\Domain\Compensation\Models\EmployeeSalaryAssignment;
use App\Domain\Compensation\Models\SalaryStructure;
use App\Domain\Compensation\Services\CompensationAccess;
use App\Domain\Compensation\Services\CompensationChanges;
use App\Domain\Compensation\Services\CompensationPlanning;
use App\Domain\Compensation\Services\CompensationRanges;
use App\Domain\Employment\Models\EmployeePosition;
use App\Domain\Employment\Services\SensitiveAccessAuditor;
use App\Domain\Workforce\Models\Position;
use App\Filament\Support\CompensationActions;
use App\Filament\Support\WorkforceActions;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\KeyValue;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use RuntimeException;

/**
 * Employee 360 → Compensation (Phase 11; was the Phase 4 "Salary" tab). The canonical, effective-dated
 * compensation history: current, past and scheduled rows with their components. Opening it is a
 * sensitive read and is audited. Nothing here writes compensation: "Propose compensation change"
 * creates a proposal that still needs review, approval and execution by other people.
 *
 * Full readers (compensation.view, in scope) see every row (corrected and cancelled ones too) with
 * reasons; managers (compensation.team) and the employee (compensation.self) see approved rows only.
 */
class CompensationRelationManager extends RelationManager
{
    protected static string $relationship = 'salaryAssignments';

    protected static ?string $title = 'Compensation';

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
            ->headerActions([
                Action::make('proposeFromPosition')->label('Propose from position')->icon('heroicon-m-briefcase')->color('gray')
                    ->visible(fn () => auth()->user()->can('compensation.propose') && (int) $this->getOwnerRecord()->user_id !== (int) auth()->id())
                    ->schema([
                        Select::make('position_id')->label('Position')->required()->searchable()->options(fn () => WorkforceActions::positionOptions()),
                        DatePicker::make('effective_from')->native(false)->required()->default(now()->startOfMonth()->addMonth()),
                    ])
                    ->modalDescription('Prefills a draft from the position\'s approved pay range. It changes nobody\'s position and still needs review, approval and execution.')
                    ->action(fn (array $data) => CompensationActions::run(fn () => app(CompensationPlanning::class)->proposeFromPosition($this->getOwnerRecord(), Position::query()->findOrFail($data['position_id']), auth()->user(), ['effective_from' => $data['effective_from']]), fn ($c) => 'Draft compensation change '.$c->reference.' created')),
                Action::make('propose')->label('Propose compensation change')->icon('heroicon-m-banknotes')
                    ->visible(fn () => auth()->user()->can('compensation.propose') && (int) $this->getOwnerRecord()->user_id !== (int) auth()->id())
                    ->schema([
                        Select::make('change_type')->options(config('peopleos.compensation.change_types'))->default('annual_increment')->required(),
                        DatePicker::make('effective_from')->native(false)->required()->default(now()->startOfMonth()->addMonth()),
                        Select::make('salary_structure_id')->label('Structure')->required()->options(fn () => SalaryStructure::query()->where('status', 'active')->pluck('name', 'id')->all())
                            ->default(fn () => $this->getOwnerRecord()->salaryAssignments()->where('status', 'active')->orderByDesc('effective_from')->value('salary_structure_id')),
                        TextInput::make('ctc_annual')->label('Annual CTC')->numeric()->required()->minValue(1),
                        Select::make('currency')->options(fn () => array_combine(config('peopleos.compensation.currencies'), config('peopleos.compensation.currencies')))->default(config('peopleos.settings.tenant.base_currency', 'INR'))->required(),
                        TextInput::make('variable_target_annual')->label('Variable target (annual)')->numeric()->minValue(0),
                        KeyValue::make('component_values')->label('Fixed component amounts (monthly)')->keyLabel('Component code')->valueLabel('Amount')->helperText('For components calculated as "fixed", e.g. CONV = 1600'),
                        Textarea::make('reason')->required()->maxLength(2000),
                        Textarea::make('internal_notes')->label('Internal notes (never shown to the employee)')->maxLength(2000),
                        Toggle::make('submit')->label('Submit for review now')->default(true),
                    ])
                    ->action(function (array $data) {
                        $changes = app(CompensationChanges::class);
                        try {
                            $change = $changes->propose($this->getOwnerRecord(), $data, auth()->user());
                            if ($data['submit'] ?? false) {
                                $changes->submit($change, auth()->user());
                            }
                            Notification::make()->success()->title(($data['submit'] ?? false) ? 'Compensation change submitted for review' : 'Compensation change saved as a draft')->body('Reference '.$change->reference)->send();
                        } catch (RuntimeException $e) {
                            Notification::make()->danger()->title('Cannot propose')->body($e->getMessage())->persistent()->send();
                        }
                    }),
            ]);
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
