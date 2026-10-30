<?php

namespace App\Filament\Resources\GoalSheets;

use App\Domain\Performance\Models\Appraisal;
use App\Domain\Performance\Models\GoalPlan;
use App\Domain\Performance\Models\PerformanceCycle;
use App\Domain\Performance\Services\GoalSheets;
use App\Domain\Performance\Support\AppraisalVisibility;
use App\Domain\Performance\Support\PerformanceLabels;
use App\Filament\Resources\GoalPlans\GoalPlanResource;
use App\Filament\Resources\Goals\GoalResource;
use App\Filament\Resources\GoalSheets\Pages\ListGoalSheets;
use App\Filament\Support\EmployeeFilters;
use App\Filament\Support\PerformanceRegisters;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

/**
 * HROne parity GO-1 (HR Desk → Performance → Employee goals, matrix 27 §27.2.1 #1, #3, #7, #10): goal sheets — one row
 * per employee and performance cycle (the appraisal of the cycle's population), with the sheet's status worked out by
 * GoalSheets (not created, draft, waiting for approval, sent back, active), its goals and weightage, the latest goal
 * request and its approval trail. "View sheet" lists the goals with their KPIs, the competencies rated with them and
 * their weightage, and every request (version) of the sheet; "Goals" opens the Goals list on that sheet. Read-only: goals
 * are changed on the Goals list, requests decided where they always were.
 *
 * A second, read-only resource on appraisals (the Appraisals register stays the appraisal's own: every appraisal link
 * names AppraisalResource, and nothing resolves a resource from the model). For performance HR (performance.view /
 * performance.calibrate), in their access scope.
 */
class GoalSheetResource extends Resource
{
    protected static ?string $model = Appraisal::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentList;

    protected static string|UnitEnum|null $navigationGroup = 'Performance';

    protected static ?string $navigationLabel = 'Goal sheets';

    protected static ?string $modelLabel = 'goal sheet';

    protected static ?string $slug = 'goal-sheets';

    protected static ?int $navigationSort = 16;

    public static function canAccess(): bool
    {
        return AppraisalVisibility::isHr(auth()->user());
    }

    public static function canViewAny(): bool
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
        $query = parent::getEloquentQuery()->with(['cycle', 'employee.person']);
        if (! static::canAccess()) {
            return $query->whereRaw('1 = 0');
        }

        return app(GoalSheets::class)->withSheet($query);
    }

    public static function statusColor(string $status): string
    {
        return match ($status) {
            'not_created' => 'danger', 'draft' => 'gray', 'pending' => 'warning', 'sent_back' => 'danger', 'active' => 'success', default => 'gray'
        };
    }

    private static function attribute(Appraisal $record, string $key): mixed
    {
        return $record->getAttributes()[$key] ?? null;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('employee.person.full_name')->label('Employee')
                    ->description(fn (Appraisal $record) => $record->employee?->employee_code)
                    ->searchable(query: EmployeeFilters::search('employee')),
                TextColumn::make('cycle.name')->label('Goal period'),
                TextColumn::make('sheet_status')->label('Sheet status')->badge()
                    ->state(fn (Appraisal $record) => GoalSheets::statusFrom($record))
                    ->color(fn (string $state) => self::statusColor($state))
                    ->formatStateUsing(fn (string $state) => GoalSheets::STATUSES[$state] ?? $state),
                TextColumn::make('sheet_goals')->label('Goals')->state(fn (Appraisal $record) => (int) self::attribute($record, 'sheet_goals')),
                TextColumn::make('sheet_weight')->label('Weightage')->suffix('%')->state(fn (Appraisal $record) => PerformanceRegisters::number(self::attribute($record, 'sheet_weight') ?? 0)),
                TextColumn::make('sheet_plans')->label('Version')->placeholder('—')
                    ->state(fn (Appraisal $record) => ((int) self::attribute($record, 'sheet_plans')) ?: null),
                TextColumn::make('sheet_plan_status')->label('Latest request')->badge()->placeholder('—')
                    ->color(fn (?string $state) => GoalPlanResource::statusColor($state))
                    ->formatStateUsing(fn (?string $state) => config("peopleos.performance.goal_plan_statuses.{$state}", $state))
                    ->state(fn (Appraisal $record) => self::attribute($record, 'sheet_plan_status'))
                    ->url(fn (Appraisal $record) => ($id = self::attribute($record, 'sheet_plan_id')) !== null ? GoalPlanResource::getUrl('view', ['record' => $id]) : null),
                TextColumn::make('sheet_approval')->label('Approval')->placeholder('—')->toggleable()
                    ->state(fn (Appraisal $record) => ($plan = self::latestPlan($record)) === null ? null : PerformanceRegisters::approvalLabel($plan, $plan->isPending()))
                    ->url(fn (Appraisal $record) => ($plan = self::latestPlan($record)) === null ? null : PerformanceRegisters::approvalUrl($plan, $plan->isPending())),
            ])
            ->defaultSort('id', 'desc')
            ->filters([
                SelectFilter::make('performance_cycle_id')->label('Goal period')
                    ->options(fn () => PerformanceCycle::query()->orderByDesc('period_start')->pluck('name', 'id')->all()),
                SelectFilter::make('sheet_status')->label('Sheet status')->options(GoalSheets::STATUSES)
                    ->query(fn (Builder $query, array $data) => filled($data['value'] ?? null) ? app(GoalSheets::class)->whereStatus($query, (string) $data['value']) : $query),
                ...EmployeeFilters::forRegister('employee'),
            ])
            ->recordActions([
                Action::make('viewSheet')->label('View sheet')->icon(Heroicon::OutlinedEye)->color('gray')->slideOver()
                    ->modalHeading(fn (Appraisal $record) => 'Goal sheet · '.($record->employee?->person?->full_name ?? '').' · '.($record->cycle?->name ?? ''))
                    ->modalSubmitAction(false)->modalCancelActionLabel('Close')
                    ->schema(fn (Appraisal $record) => self::sheetSchema($record)),
                Action::make('openGoals')->label('Goals')->icon(Heroicon::OutlinedFlag)->color('gray')
                    ->url(fn (Appraisal $record) => GoalResource::getUrl('index', ['filters' => ['employee_id' => ['value' => $record->employee_id], 'performance_cycle_id' => ['value' => $record->performance_cycle_id]]])),
            ]);
    }

    private static function latestPlan(Appraisal $record): ?GoalPlan
    {
        $id = self::attribute($record, 'sheet_plan_id');

        return $id === null ? null : GoalPlan::query()->find($id);
    }

    /** The sheet view: goals with their KPIs, competencies with their weightage, and every goal request (version) in order. */
    public static function sheetSchema(Appraisal $record): array
    {
        $sheet = app(GoalSheets::class)->sheet($record);
        $required = $sheet['required_weight'];
        $competency = PerformanceLabels::plural('competency', 'Competencies');

        return [
            Section::make('Goals')->columns(3)->schema([
                TextEntry::make('sheet_status_entry')->label('Sheet status')->badge()->color(self::statusColor($sheet['status']))->state(GoalSheets::STATUSES[$sheet['status']] ?? $sheet['status']),
                TextEntry::make('sheet_weight_entry')->label('Weightage')->state(PerformanceRegisters::number($sheet['total_weight']).'%'.($required !== null ? ' of '.PerformanceRegisters::number($required).'% required' : '')),
                TextEntry::make('sheet_goal_count')->label('Goals')->state((string) $sheet['goals']->count()),
                TextEntry::make('sheet_goal_lines')->hiddenLabel()->columnSpanFull()->listWithLineBreaks()->placeholder('No goals on this sheet yet')
                    ->state(PerformanceRegisters::goalLines($sheet['goals'])),
            ]),
            Section::make($competency)->schema([
                TextEntry::make('sheet_competency_share')->label($competency.' share of the rating')
                    ->state($sheet['competency_share'] === null ? null : PerformanceRegisters::number($sheet['competency_share']).'%')->placeholder('—'),
                TextEntry::make('sheet_competency_lines')->hiddenLabel()->listWithLineBreaks()->placeholder('No '.mb_strtolower($competency).' rated with these goals')
                    ->state(array_map(fn (array $c) => $c['name'].' · weight '.PerformanceRegisters::number($c['weight']).'%', $sheet['competencies'])),
            ]),
            Section::make('Goal requests (versions)')->schema([
                TextEntry::make('sheet_plan_lines')->hiddenLabel()->listWithLineBreaks()->placeholder('Never submitted for approval')
                    ->state($sheet['plans']->values()->map(fn (GoalPlan $plan, int $i) => 'Version '.($i + 1).' · requested '.$plan->submitted_at?->toDateString().($plan->submitter ? ' by '.$plan->submitter->name : '')
                        .' · '.config("peopleos.performance.goal_plan_statuses.{$plan->status}", $plan->status)
                        .($plan->decided_at ? ' on '.$plan->decided_at->toDateString().($plan->decider ? ' by '.$plan->decider->name : '') : '')
                        .' · '.$plan->goals.' goal(s), '.PerformanceRegisters::number($plan->total_weight).'%')->all()),
            ]),
        ];
    }

    public static function getPages(): array
    {
        return ['index' => ListGoalSheets::route('/')];
    }
}
