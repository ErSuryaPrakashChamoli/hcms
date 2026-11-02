<?php

namespace App\Filament\Resources\Goals\RelationManagers;

use App\Domain\Performance\Models\Goal;
use App\Domain\Performance\Models\GoalAlignment;
use App\Domain\Performance\Models\GoalCheckIn;
use App\Domain\Performance\Support\GoalVisibility;
use App\Domain\Performance\Support\PerformanceLabels;
use App\Filament\Resources\Goals\GoalResource;
use App\Filament\Support\EmployeeFilters;
use App\Filament\Support\PerformanceActions;
use Filament\Actions\Action;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * HROne parity GO-4 (matrix 27 §27.2.3 #6–9, = MS-14 "Linked goals"): the goals aligned to this goal — now and earlier —
 * with their owner, KPIs, progress, goal status, final achievement, the latest check-in (period, status, comment), linked
 * on and unlinked on. Re-linking is allowed (a new row; the history keeps both). Only goals the viewer may see are listed:
 * the Goals list's own rule (HR all; others organisation goals, their own and their reports' goals) and the private-goal
 * rule (GoalVisibilityScope) apply to each aligned goal. Filters: linked / unlinked, status, type and the WO-2 employee
 * search and organisation filters. Read-only, except "Check in" on an aligned goal the viewer may check in on.
 */
class LinkedGoalsRelationManager extends RelationManager
{
    protected static string $relationship = 'alignments';

    protected static ?string $title = 'Linked goals';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        $user = auth()->user();

        return $user !== null && $ownerRecord instanceof Goal && $user->can('update', $ownerRecord);
    }

    public function isReadOnly(): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query
                ->whereHas('goal', fn (Builder $goals) => GoalResource::restrictToViewer($goals))
                ->with(['goal.employee.person', 'goal.ownerEmployee.person', 'goal.organisationNode', 'goal.keyResults', 'goal.checkIns', 'linker', 'unlinker']))
            ->columns([
                TextColumn::make('index')->label('S.no')->rowIndex(),
                TextColumn::make('goal.title')->label('Linked goal')->wrap()
                    ->description(fn (GoalAlignment $record) => config("peopleos.performance.goal_levels.{$record->goal?->level}", $record->goal?->level)),
                TextColumn::make('owner')->label('Owner')
                    ->state(fn (GoalAlignment $record) => $record->goal?->employee?->person?->full_name ?? $record->goal?->ownerEmployee?->person?->full_name ?? $record->goal?->organisationNode?->auditLabel() ?? '—')
                    ->description(fn (GoalAlignment $record) => $record->goal?->employee?->employee_code ?? $record->goal?->ownerEmployee?->employee_code)
                    ->searchable(query: fn (Builder $query, string $search) => $query->whereHas('goal', fn (Builder $g) => $g->where(fn (Builder $w) => $w
                        ->whereHas('employee', fn (Builder $e) => EmployeeFilters::search()($e, $search))
                        ->orWhereHas('ownerEmployee', fn (Builder $e) => EmployeeFilters::search()($e, $search))))),
                TextColumn::make('kpis')->label(PerformanceLabels::plural('kpi', 'Key results'))
                    ->state(fn (GoalAlignment $record) => $record->goal?->keyResults->count() ?? 0)
                    ->description(fn (GoalAlignment $record) => $record->goal?->keyResults->pluck('title')->take(3)->implode(' · ') ?: null),
                TextColumn::make('goal.progress')->label('Progress')->suffix('%'),
                TextColumn::make('final_achievement')->label('Final achievement')->suffix('%')
                    ->state(fn (GoalAlignment $record) => $record->goal === null ? null : number_format($record->goal->finalAchievement(), 2))
                    ->visible(fn () => GoalVisibility::overrideEnabled()),
                TextColumn::make('health')->label('Goal status')->badge()
                    ->state(fn (GoalAlignment $record) => $record->goal?->healthLabel())
                    ->color(fn (GoalAlignment $record) => match ($record->goal?->healthKey()) {
                        'on_track' => 'success', 'behind' => 'warning', 'at_risk' => 'danger', default => 'gray'
                    }),
                TextColumn::make('goal.status')->label('Status')->badge()->color('gray')
                    ->formatStateUsing(fn (?string $state) => config("peopleos.performance.goal_statuses.{$state}", $state)),
                TextColumn::make('last_period')->label('Last check-in')->placeholder('—')
                    ->state(fn (GoalAlignment $record) => self::lastCheckIn($record)?->periodLabel() ?? self::lastCheckIn($record)?->created_at?->format('d M Y'))
                    ->description(fn (GoalAlignment $record) => self::lastCheckIn($record)?->note),
                TextColumn::make('linked_at')->label('Linked on')->date(),
                TextColumn::make('unlinked_at')->label('Unlinked on')->date()->placeholder('Still linked'),
            ])
            ->defaultSort('linked_at', 'desc')
            ->filters([
                SelectFilter::make('linked')->label('Linked')->options(['linked' => 'Still linked', 'unlinked' => 'Unlinked'])
                    ->query(fn (Builder $query, array $data) => match ($data['value'] ?? null) {
                        'linked' => $query->whereNull('unlinked_at'),
                        'unlinked' => $query->whereNotNull('unlinked_at'),
                        default => $query,
                    }),
                SelectFilter::make('status')->label('Status')->options(config('peopleos.performance.goal_statuses'))
                    ->query(fn (Builder $query, array $data) => $query->when($data['value'] ?? null, fn (Builder $q, string $status) => $q->whereHas('goal', fn (Builder $g) => $g->where('status', $status)))),
                SelectFilter::make('type')->label('Type')->options(PerformanceLabels::goalTypes())
                    ->query(fn (Builder $query, array $data) => $query->when($data['value'] ?? null, fn (Builder $q, string $type) => $q->whereHas('goal', fn (Builder $g) => $g->where('type', $type)))),
                EmployeeFilters::organisation('goal.employee', ['company_id', 'business_unit_id', 'department_id', 'location_id']),
            ])
            ->recordActions([
                PerformanceActions::goalCheckIn(fn (GoalAlignment $record) => $record->isLinked() ? $record->goal : null, 'checkInLinked'),
                Action::make('checkIns')->label('Check-ins')->icon(Heroicon::OutlinedClock)->color('gray')
                    ->modalHeading(fn (GoalAlignment $record) => 'Check-ins · '.$record->goal?->title)
                    ->modalSubmitAction(false)->modalCancelActionLabel('Close')
                    ->modalContent(fn (GoalAlignment $record): View => view('filament.performance.goal-check-ins', ['checkIns' => self::checkIns($record)])),
                Action::make('openGoal')->label('Open')->icon(Heroicon::OutlinedArrowTopRightOnSquare)->color('gray')
                    ->visible(fn (GoalAlignment $record) => $record->goal !== null && auth()->user()->can('update', $record->goal))
                    ->url(fn (GoalAlignment $record) => GoalResource::getUrl('edit', ['record' => $record->goal_id])),
            ]);
    }

    private static function lastCheckIn(GoalAlignment $record): ?GoalCheckIn
    {
        return $record->goal?->checkIns->first();
    }

    /** @return Collection<int, GoalCheckIn> the aligned goal's check-ins, newest first */
    public static function checkIns(GoalAlignment $record): Collection
    {
        $record->loadMissing('goal');

        return $record->goal === null ? collect() : $record->goal->checkIns()->with(['keyResult', 'author'])->limit(50)->get();
    }
}
