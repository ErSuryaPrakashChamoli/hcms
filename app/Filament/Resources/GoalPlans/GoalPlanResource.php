<?php

namespace App\Filament\Resources\GoalPlans;

use App\Domain\Performance\Models\GoalPlan;
use App\Domain\Performance\Models\PerformanceCycle;
use App\Domain\Performance\Support\AppraisalVisibility;
use App\Filament\Resources\GoalPlans\Pages\ListGoalPlans;
use App\Filament\Resources\GoalPlans\Pages\ViewGoalPlan;
use App\Filament\Resources\Goals\GoalResource;
use App\Filament\Support\EmployeeFilters;
use App\Filament\Support\PerformanceRegisters;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use UnitEnum;

/**
 * HROne parity GO-1 (HR Desk → Performance → Goal requests, "Goal history", matrix 27 §27.2.4): every goal sheet
 * submission and proposal (HP-8d `goal_plans`) with its decision — employee, cycle, version (the n-th request of that
 * sheet), submitted by / on, approval route, status, goals and weightage, decided by / on and the note, and the approval
 * trail (workflow run or Approval Center). Read-only: requests are decided in the Approval Center, by the workflow or with
 * GoalPlans; nothing is changed here.
 *
 * For performance HR (performance.view / performance.calibrate); goal plans carry the employee access scope.
 */
class GoalPlanResource extends Resource
{
    protected static ?string $model = GoalPlan::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentCheck;

    protected static string|UnitEnum|null $navigationGroup = 'Performance';

    protected static ?string $navigationLabel = 'Goal requests';

    protected static ?string $modelLabel = 'goal request';

    protected static ?string $slug = 'goal-requests';

    protected static ?int $navigationSort = 17;

    public static function canAccess(): bool
    {
        return AppraisalVisibility::isHr(auth()->user());
    }

    public static function canViewAny(): bool
    {
        return static::canAccess();
    }

    public static function canView(Model $record): bool
    {
        return static::canAccess();
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery()->with(['employee.person', 'cycle', 'submitter', 'decider']);
        if (! static::canAccess()) {
            return $query->whereRaw('1 = 0');
        }

        // The version: this request's place among the requests of the same goal sheet (1 = the first).
        return $query->select('goal_plans.*')->addSelect(['request_version' => DB::table('goal_plans as earlier')
            ->whereColumn('earlier.employee_id', 'goal_plans.employee_id')->whereColumn('earlier.performance_cycle_id', 'goal_plans.performance_cycle_id')
            ->whereColumn('earlier.id', '<=', 'goal_plans.id')->selectRaw('count(*)')]);
    }

    public static function statusColor(?string $state): string
    {
        return match ($state) {
            'pending' => 'warning', 'approved' => 'success', 'rejected' => 'danger', default => 'gray'
        };
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('employee.person.full_name')->label('Employee')
                    ->description(fn (GoalPlan $record) => $record->employee?->employee_code)
                    ->searchable(query: EmployeeFilters::search('employee')),
                TextColumn::make('cycle.name')->label('Goal period'),
                TextColumn::make('request_version')->label('Version')->state(fn (GoalPlan $record) => (int) ($record->getAttributes()['request_version'] ?? 1)),
                TextColumn::make('submitted_at')->label('Requested')->dateTime()->sortable()->description(fn (GoalPlan $record) => $record->submitter?->name),
                TextColumn::make('approval')->label('Route')->badge()->color('gray')->formatStateUsing(fn (?string $state) => $state === 'workflow' ? 'Workflow' : 'Manager'),
                TextColumn::make('status')->badge()->color(fn (?string $state) => self::statusColor($state))
                    ->formatStateUsing(fn (?string $state) => config("peopleos.performance.goal_plan_statuses.{$state}", $state)),
                TextColumn::make('goals')->label('Goals'),
                TextColumn::make('total_weight')->label('Weightage')->suffix('%')->formatStateUsing(fn ($state) => PerformanceRegisters::number($state)),
                TextColumn::make('decided_at')->label('Decided')->dateTime()->placeholder('—')->sortable()->description(fn (GoalPlan $record) => $record->decider?->name),
                TextColumn::make('decision_note')->label('Note')->limit(60)->wrap()->placeholder('—')->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('approval_trail')->label('Approval')->placeholder('—')->toggleable()
                    ->state(fn (GoalPlan $record) => PerformanceRegisters::approvalLabel($record, $record->isPending()))
                    ->url(fn (GoalPlan $record) => PerformanceRegisters::approvalUrl($record, $record->isPending())),
            ])
            ->defaultSort('id', 'desc')
            ->filters([
                SelectFilter::make('performance_cycle_id')->label('Goal period')
                    ->options(fn () => PerformanceCycle::query()->orderByDesc('period_start')->pluck('name', 'id')->all()),
                SelectFilter::make('status')->multiple()->options(fn () => config('peopleos.performance.goal_plan_statuses')),
                SelectFilter::make('approval')->label('Route')->options(['manager' => 'Manager', 'workflow' => 'Workflow']),
                EmployeeFilters::dateRange('request_dates', 'Requested', 'submitted_at'),
                EmployeeFilters::dateRange('decision_dates', 'Decided', 'decided_at'),
                ...EmployeeFilters::forRegister('employee'),
            ])
            ->recordActions([
                ViewAction::make(),
                Action::make('openGoals')->label('Goals')->icon(Heroicon::OutlinedFlag)->color('gray')
                    ->url(fn (GoalPlan $record) => GoalResource::getUrl('index', ['filters' => ['employee_id' => ['value' => $record->employee_id], 'performance_cycle_id' => ['value' => $record->performance_cycle_id]]])),
            ]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Goal request')->columns(3)->schema([
                TextEntry::make('employee.person.full_name')->label('Employee'),
                TextEntry::make('employee.employee_code')->label('Code'),
                TextEntry::make('cycle.name')->label('Goal period'),
                TextEntry::make('request_version')->label('Version')->state(fn (GoalPlan $record) => (int) ($record->getAttributes()['request_version'] ?? 1)),
                TextEntry::make('status')->badge()->color(fn (?string $state) => self::statusColor($state))
                    ->formatStateUsing(fn (?string $state) => config("peopleos.performance.goal_plan_statuses.{$state}", $state)),
                TextEntry::make('approval')->label('Route')->formatStateUsing(fn (?string $state) => $state === 'workflow' ? 'Workflow' : 'Reporting manager or HR'),
                TextEntry::make('submitted_at')->label('Requested on')->dateTime(),
                TextEntry::make('submitter.name')->label('Requested by')->placeholder('—'),
                TextEntry::make('goals')->label('Goals on the sheet'),
                TextEntry::make('total_weight')->label('Weightage')->suffix('%')->formatStateUsing(fn ($state) => PerformanceRegisters::number($state)),
                TextEntry::make('decided_at')->label('Decided on')->dateTime()->placeholder('Not yet'),
                TextEntry::make('decider.name')->label('Decided by')->placeholder('—'),
                TextEntry::make('decision_note')->label('Note')->placeholder('—')->columnSpanFull(),
                TextEntry::make('approval_trail')->label('Approval workflow')->columnSpanFull()
                    ->state(fn (GoalPlan $record) => PerformanceRegisters::approvalLabel($record, $record->isPending()))
                    ->url(fn (GoalPlan $record) => PerformanceRegisters::approvalUrl($record, $record->isPending()))
                    ->placeholder('Decided without a workflow run'),
            ]),
        ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListGoalPlans::route('/'),
            'view' => ViewGoalPlan::route('/{record}'),
        ];
    }
}
