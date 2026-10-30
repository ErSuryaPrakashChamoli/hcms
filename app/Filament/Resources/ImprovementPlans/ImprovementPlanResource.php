<?php

namespace App\Filament\Resources\ImprovementPlans;

use App\Domain\Employment\Models\Employee;
use App\Domain\Performance\Models\ImprovementPlan;
use App\Domain\Performance\Models\PerformanceLookup;
use App\Domain\Performance\Policies\EmployeeOwnedPolicy;
use App\Domain\Performance\Services\ImprovementPlanRules;
use App\Domain\Performance\Services\ImprovementPlans;
use App\Domain\Performance\Services\PerformanceRelationships;
use App\Filament\Resources\ImprovementPlans\Pages\ManageImprovementPlans;
use App\Filament\Support\EmployeeFilters;
use App\Filament\Support\PerformanceActions;
use App\Filament\Support\PerformanceRegisters;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/**
 * Performance improvement plans (§34). Sensitive: HR, the manager and the employee only. HROne setup parity HP-8f: the
 * end date follows the employee's PIP policy period; plans and outcomes waiting for approval are decided here or in the
 * Approval Center; close checklist items are reviewed before the plan is closed.
 */
class ImprovementPlanResource extends Resource
{
    protected static ?string $model = ImprovementPlan::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedLifebuoy;

    protected static string|UnitEnum|null $navigationGroup = 'Performance';

    protected static ?string $navigationLabel = 'Improvement plans';

    protected static ?int $navigationSort = 40;

    public static function canCreate(): bool
    {
        return auth()->user()->can('performance.manage') || auth()->user()->can('performance.team');
    }

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery()->with(['employee.person', 'manager.person', 'creator', 'approver'])
            ->withCount(['checkpoints as pending_checkpoints_count' => fn (Builder $q) => $q->where('status', 'pending')]);
        $user = auth()->user();
        if ($user->can('performance.view')) {
            return $query;
        }
        $me = EmployeeOwnedPolicy::employeeOf($user);

        return $query->where(fn (Builder $q) => $q->where('employee_id', $me?->id ?? 0)->orWhere('manager_id', $me?->id ?? 0));
    }

    public static function form(Schema $schema): Schema
    {
        $me = PerformanceActions::me();

        return $schema->columns(2)->components([
            Select::make('employee_id')->label('Employee')->required()->searchable()->live()
                ->options(fn () => ($me && ! auth()->user()->can('performance.manage') ? Employee::query()->with('person')->whereIn('id', app(PerformanceRelationships::class)->reportIds($me)) : Employee::query()->with('person')->employed())->get()->mapWithKeys(fn ($e) => [$e->id => "{$e->employee_code} · {$e->person?->full_name}"])->all())
                // HP-8f: the end date follows the employee's PIP policy period.
                ->afterStateUpdated(fn (Get $get, Set $set) => self::fillEndDate($get, $set)),
            Select::make('manager_id')->label('Manager')->searchable()->default(fn () => $me?->id)
                ->options(fn () => Employee::query()->with('person')->employed()->get()->mapWithKeys(fn ($e) => [$e->id => "{$e->employee_code} · {$e->person?->full_name}"])->all()),
            DatePicker::make('start_date')->native(false)->required()->default(now())->live()->afterStateUpdated(fn (Get $get, Set $set) => self::fillEndDate($get, $set)),
            DatePicker::make('end_date')->native(false)->required()->default(now()->addMonths(2))->afterOrEqual('start_date'),
            // HP-8a: the PIP reasons (performance masters); the reason text keeps the details.
            Select::make('reason_codes')->label('PIP reasons')->multiple()->native(false)->columnSpanFull()
                ->options(fn () => PerformanceLookup::codeOptions('pip_reason'))
                ->visible(fn () => PerformanceLookup::codeOptions('pip_reason') !== []),
            Textarea::make('reason')->required()->rows(3)->columnSpanFull(),
            Repeater::make('objectives')->columnSpanFull()->columns(3)->minItems(1)->schema([
                TextInput::make('objective')->required()->columnSpan(2),
                TextInput::make('measure')->placeholder('How success is measured'),
            ]),
        ]);
    }

    /** HP-8f: end date = start + the PIP policy's period (two months without one, as before). */
    private static function fillEndDate(Get $get, Set $set): void
    {
        if (blank($get('start_date'))) {
            return;
        }
        $employee = filled($get('employee_id')) ? Employee::query()->find($get('employee_id')) : null;
        $set('end_date', app(ImprovementPlanRules::class)->defaultEnd($employee, $get('start_date'))->toDateString());
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('employee.person.full_name')->label('Employee'),
                TextColumn::make('manager.person.full_name')->label('Manager')->placeholder('—'),
                TextColumn::make('start_date')->date()->sortable(),
                TextColumn::make('end_date')->date(),
                TextColumn::make('reason_codes')->label('Reasons')->placeholder('—')->wrap()->toggleable()
                    ->state(fn (ImprovementPlan $record) => $record->reason_codes ? PerformanceLookup::query()->ofKind('pip_reason')->whereIn('code', $record->reason_codes)->orderBy('sort_order')->pluck('name')->implode(', ') : null),
                TextColumn::make('objectives')->label('Objectives')->state(fn (ImprovementPlan $record) => count($record->objectives ?? [])),
                TextColumn::make('status')->badge()->color(fn (string $state) => match ($state) {
                    'active', 'extended' => 'warning', 'completed' => 'success', 'unsuccessful' => 'danger', 'pending_approval', 'pending_outcome' => 'info', default => 'gray'
                })->formatStateUsing(fn (string $state) => config("peopleos.performance.pip_statuses.{$state}", $state)),
                TextColumn::make('pending_checkpoints_count')->label('Open checkpoints')->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('outcome')->limit(60)->placeholder('—')->wrap(),
                // HROne parity GO-1 (§27.5.1 #5, #6): request and approval facts and the approval trail, for performance HR.
                TextColumn::make('created_at')->label('Requested')->date()->sortable()->description(fn (ImprovementPlan $record) => $record->creator?->name)
                    ->toggleable()->visible(fn () => PerformanceRegisters::hrSide()),
                TextColumn::make('approved_at')->label('Approved')->date()->placeholder('—')->sortable()->description(fn (ImprovementPlan $record) => $record->approver?->name)
                    ->toggleable()->visible(fn () => PerformanceRegisters::hrSide()),
                TextColumn::make('approval_trail')->label('Approval')->placeholder('—')->toggleable(isToggledHiddenByDefault: true)
                    ->state(fn (ImprovementPlan $record) => PerformanceRegisters::approvalLabel($record, in_array($record->status, ['pending_approval', 'pending_outcome'], true)))
                    ->url(fn (ImprovementPlan $record) => PerformanceRegisters::approvalUrl($record, in_array($record->status, ['pending_approval', 'pending_outcome'], true)))
                    ->visible(fn () => PerformanceRegisters::hrSide()),
            ])
            ->defaultSort('id', 'desc')
            ->filters([
                SelectFilter::make('status')->options(config('peopleos.performance.pip_statuses')),
                // HROne parity GO-1 (§27.5.1 #3, §27.5.2 #2): evaluation status and the request / approval / end date ranges,
                // with the WO-2 employee filters — for performance HR only (the manager's and employee's lists are as before).
                ...PerformanceRegisters::hrOnly([
                    self::evaluationFilter(),
                    EmployeeFilters::dateRange('request_dates', 'Requested', 'created_at'),
                    EmployeeFilters::dateRange('approval_dates', 'Approved', 'approved_at'),
                    EmployeeFilters::dateRange('end_dates', 'PIP end date', 'end_date'),
                    ...EmployeeFilters::forRegister('employee'),
                ]),
            ])
            ->recordActions([
                Action::make('activate')->label('Activate')->icon('heroicon-m-play')->color('primary')
                    ->visible(fn (ImprovementPlan $record) => $record->status === 'draft' && auth()->user()->can('update', $record))
                    ->requiresConfirmation()
                    ->action(fn (ImprovementPlan $record) => PerformanceActions::run(fn () => app(ImprovementPlans::class)->activate($record, auth()->user()), fn (ImprovementPlan $plan) => $plan->status === 'pending_approval' ? 'Plan sent for approval' : 'Plan started')),
                Action::make('checkpoint')->label('Add checkpoint')->icon('heroicon-m-flag')->color('gray')
                    ->visible(fn (ImprovementPlan $record) => $record->isOpen() && auth()->user()->can('update', $record))
                    ->schema([TextInput::make('title')->required()->maxLength(255), DatePicker::make('due_date')->native(false)->required(), Textarea::make('notes')->maxLength(1000)])
                    ->action(fn (ImprovementPlan $record, array $data) => PerformanceActions::run(fn () => app(ImprovementPlans::class)->addCheckpoint($record, $data['title'], $data['due_date'], $data['notes'] ?? null, auth()->user()), 'Checkpoint added')),
                // HP-8f: decisions on a plan or an outcome waiting for approval (also in the Approval Center).
                Action::make('approvePlan')->label('Approve plan')->icon('heroicon-m-check')->color('success')
                    ->visible(fn (ImprovementPlan $record) => $record->status === 'pending_approval' && app(ImprovementPlans::class)->mayDecide(auth()->user(), $record))
                    ->schema([Textarea::make('note')->maxLength(500)])
                    ->action(fn (ImprovementPlan $record, array $data) => PerformanceActions::run(fn () => app(ImprovementPlans::class)->approve($record, $data['note'] ?? null, auth()->user()), 'Plan approved and started')),
                Action::make('rejectPlan')->label('Reject plan')->icon('heroicon-m-x-mark')->color('danger')
                    ->visible(fn (ImprovementPlan $record) => $record->status === 'pending_approval' && app(ImprovementPlans::class)->mayDecide(auth()->user(), $record))
                    ->schema([Textarea::make('note')->label('Reason')->required()->maxLength(500)])
                    ->action(fn (ImprovementPlan $record, array $data) => PerformanceActions::run(fn () => app(ImprovementPlans::class)->reject($record, $data['note'], auth()->user()), 'Plan rejected')),
                Action::make('approveOutcome')->label('Approve outcome')->icon('heroicon-m-check-badge')->color('success')
                    ->visible(fn (ImprovementPlan $record) => $record->status === 'pending_outcome' && app(ImprovementPlans::class)->mayDecide(auth()->user(), $record))
                    ->modalDescription(fn (ImprovementPlan $record) => 'Outcome: '.config("peopleos.performance.pip_statuses.{$record->requested_outcome}", $record->requested_outcome).'.')
                    ->schema([Textarea::make('note')->maxLength(500)])
                    ->action(fn (ImprovementPlan $record, array $data) => PerformanceActions::run(fn () => app(ImprovementPlans::class)->approveEvaluation($record, $data['note'] ?? null, auth()->user()), 'Outcome recorded')),
                Action::make('sendBackOutcome')->label('Send back outcome')->icon('heroicon-m-arrow-uturn-left')->color('danger')
                    ->visible(fn (ImprovementPlan $record) => $record->status === 'pending_outcome' && app(ImprovementPlans::class)->mayDecide(auth()->user(), $record))
                    ->schema([Textarea::make('note')->label('Reason')->required()->maxLength(500)])
                    ->action(fn (ImprovementPlan $record, array $data) => PerformanceActions::run(fn () => app(ImprovementPlans::class)->rejectEvaluation($record, $data['note'], auth()->user()), 'Outcome sent back')),
                Action::make('reviewCheckpoint')->label('Review checkpoint')->icon('heroicon-m-clipboard-document-check')->color('gray')
                    ->visible(fn (ImprovementPlan $record) => ($record->isOpen() || in_array($record->status, ['completed', 'unsuccessful'], true)) && auth()->user()->can('update', $record) && $record->checkpoints()->where('status', 'pending')->exists())
                    ->schema(fn (ImprovementPlan $record) => [
                        Select::make('checkpoint_id')->label('Checkpoint')->required()->options($record->checkpoints()->where('status', 'pending')->when(! $record->isOpen(), fn ($q) => $q->where('kind', 'close'))->get()
                            ->mapWithKeys(fn ($c) => [$c->id => $c->due_date->toDateString().' · '.$c->title.($c->kind !== 'checkpoint' ? ' ('.config("peopleos.performance.checkpoint_kinds.{$c->kind}", $c->kind).($c->owner ? ', '.$c->owner : '').')' : '')])->all()),
                        Select::make('status')->options(collect(config('peopleos.performance.checkpoint_statuses'))->except('pending')->all())->required(),
                        Textarea::make('outcome')->required()->maxLength(1000),
                    ])
                    ->action(fn (ImprovementPlan $record, array $data) => PerformanceActions::run(fn () => app(ImprovementPlans::class)->reviewCheckpoint($record->checkpoints()->findOrFail($data['checkpoint_id']), $data['status'], $data['outcome'], auth()->user()), 'Checkpoint reviewed')),
                Action::make('extend')->label('Extend')->icon('heroicon-m-calendar')->color('warning')
                    ->visible(fn (ImprovementPlan $record) => $record->isOpen() && auth()->user()->can('update', $record))
                    ->schema([DatePicker::make('end_date')->native(false)->required(), Textarea::make('reason')->required()->maxLength(255)])
                    ->action(fn (ImprovementPlan $record, array $data) => PerformanceActions::run(fn () => app(ImprovementPlans::class)->extend($record, $data['end_date'], $data['reason'], auth()->user()), 'Plan extended')),
                Action::make('close')->label('Close')->icon('heroicon-m-check-circle')->color('success')
                    ->visible(fn (ImprovementPlan $record) => $record->isOpen() && auth()->user()->can('update', $record))
                    ->schema([Select::make('status')->options(collect(config('peopleos.performance.pip_statuses'))->only(['completed', 'unsuccessful', 'cancelled'])->all())->required(), Textarea::make('outcome')->required()->maxLength(1000)])
                    ->action(fn (ImprovementPlan $record, array $data) => PerformanceActions::run(fn () => app(ImprovementPlans::class)->close($record, $data['status'], $data['outcome'], auth()->user()), 'Outcome recorded')),
                Action::make('closeOut')->label('Close plan')->icon('heroicon-m-lock-closed')->color('gray')
                    ->visible(fn (ImprovementPlan $record) => in_array($record->status, ['completed', 'unsuccessful'], true) && auth()->user()->can('update', $record))
                    ->schema([Textarea::make('reason')->required()->maxLength(500)])
                    ->action(fn (ImprovementPlan $record, array $data) => PerformanceActions::run(fn () => app(ImprovementPlans::class)->closeOut($record, $data['reason'], auth()->user()), 'Plan closed')),
            ]);
    }

    /** HROne parity GO-1: HROne's PIP "Evaluation Status" (due, waiting for approval, decided). */
    public const EVALUATION = [
        'due' => 'Evaluation due (plan ended, no outcome yet)',
        'waiting' => 'Outcome waiting for approval',
        'improved' => 'Improved performance (successfully completed)',
        'not_improved' => 'No scope of improvement (unsuccessful)',
        'extended' => 'Extended',
        'closed' => 'Closed after the outcome',
    ];

    public static function evaluationFilter(): SelectFilter
    {
        return SelectFilter::make('evaluation')->label('Evaluation status')->multiple()->options(self::EVALUATION)
            ->query(function (Builder $query, array $data): Builder {
                $values = array_values(array_intersect((array) ($data['values'] ?? []), array_keys(self::EVALUATION)));
                if ($values === []) {
                    return $query;
                }

                return $query->where(function (Builder $q) use ($values) {
                    foreach ($values as $value) {
                        match ($value) {
                            'due' => $q->orWhere(fn (Builder $w) => $w->whereIn('status', ['active', 'extended'])->whereDate('end_date', '<', today()->toDateString())),
                            'waiting' => $q->orWhere('status', 'pending_outcome'),
                            'improved' => $q->orWhere('status', 'completed'),
                            'not_improved' => $q->orWhere('status', 'unsuccessful'),
                            'extended' => $q->orWhere('status', 'extended'),
                            'closed' => $q->orWhere('status', 'closed'),
                        };
                    }
                });
            });
    }

    public static function getPages(): array
    {
        return ['index' => ManageImprovementPlans::route('/')];
    }
}
