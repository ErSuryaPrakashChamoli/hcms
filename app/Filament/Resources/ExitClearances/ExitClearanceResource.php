<?php

namespace App\Filament\Resources\ExitClearances;

use App\Domain\Exit\Models\ExitClearance;
use App\Domain\Exit\Support\ClearanceOwners;
use App\Filament\Resources\ExitCases\ExitCaseResource;
use App\Filament\Resources\ExitClearances\Pages\ListExitClearances;
use App\Filament\Support\BulkRecordActions;
use App\Filament\Support\EmployeeFilters;
use App\Filament\Support\ExitActions;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/**
 * HROne parity WO-11 (§23.6.3 #1, #4, §23.2.2 #6): the Clearances register — one row per clearance stage of every leaver:
 * leaver, last working day, stage, owner, status, due / expected / cleared dates, recoverable amount, evidence. HR
 * (exit.view, exit.manage, exit.settle) sees every stage of the exits in their access scope; a stage owner (exit.clear) the
 * stages they own — through their owner role or a P4 delegation too. Acting on a stage uses the exit case's own actions
 * (Clear, Block, Not applicable, …) and services. In navigation for HR only; stage owners reach it from My tasks and the
 * exit case.
 */
class ExitClearanceResource extends Resource
{
    protected static ?string $model = ExitClearance::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentCheck;

    protected static string|UnitEnum|null $navigationGroup = 'Exit';

    protected static ?string $navigationLabel = 'Clearances';

    protected static ?string $modelLabel = 'clearance stage';

    protected static ?string $pluralModelLabel = 'clearances';

    protected static ?string $slug = 'exit-clearances';

    protected static ?int $navigationSort = 2;

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user !== null && ($user->can('exit.view') || $user->can('exit.manage') || $user->can('exit.settle') || $user->can('exit.clear'));
    }

    public static function shouldRegisterNavigation(): bool
    {
        return (bool) (auth()->user()?->can('exit.view') || auth()->user()?->can('exit.manage'));
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function isHr(): bool
    {
        $user = auth()->user();

        return $user !== null && ($user->can('exit.view') || $user->can('exit.manage') || $user->can('exit.settle'));
    }

    public static function getEloquentQuery(): Builder
    {
        // The exit case's access scope applies through whereHas (ExitCase is scoped by its employee).
        $query = parent::getEloquentQuery()->with(['exitCase.employee.person', 'owner', 'ownerRole', 'clearer'])->whereHas('exitCase');
        if (self::isHr()) {
            return $query;
        }
        $owners = ClearanceOwners::for(auth()->user());

        return $query->where(fn (Builder $q) => $q->whereIn('owner_user_id', $owners['users'])->when($owners['roles'] !== [], fn (Builder $r) => $r->orWhereIn('owner_role_id', $owners['roles'])));
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('exitCase.number')->label('Exit')->searchable()
                    ->url(fn (ExitClearance $record) => $record->exitCase ? ExitCaseResource::getUrl('view', ['record' => $record->exitCase]) : null),
                TextColumn::make('exitCase.employee.person.full_name')->label('Leaver')->searchable(query: EmployeeFilters::search('exitCase.employee'))
                    ->description(fn (ExitClearance $record) => $record->exitCase?->employee?->employee_code),
                TextColumn::make('exitCase.last_working_day')->label('Last working day')->date()->sortable(),
                TextColumn::make('name')->label('Stage'),
                TextColumn::make('owner')->label('Owner')->state(fn (ExitClearance $record) => $record->owner?->name ?? $record->ownerRole?->name)->placeholder('Unassigned'),
                TextColumn::make('status')->badge()->color(fn (string $state) => match ($state) {
                    'cleared' => 'success', 'blocked' => 'danger', 'na' => 'gray', default => 'warning'
                })->formatStateUsing(fn (string $state, ExitClearance $record) => $record->closed_reason !== null ? (ExitClearance::CLOSED_REASONS[$record->closed_reason] ?? $state) : config("peopleos.exit.clearance_statuses.{$state}", $state)),
                TextColumn::make('due_on')->label('Due')->date()->sortable()->placeholder('—')->color(fn (ExitClearance $record) => $record->isOverdue() ? 'danger' : null),
                TextColumn::make('exitCase.clearance_started_at')->label('Assigned')->date()->placeholder('Not started')->toggleable(),
                TextColumn::make('expected_on')->label('Expected')->date()->placeholder('—')->toggleable(),
                TextColumn::make('cleared_at')->label('Completed')->date()->placeholder('—'),
                TextColumn::make('recoverable_amount')->label('Recover')->numeric(2)->placeholder('—')->toggleable(),
                TextColumn::make('evidence_name')->label('Evidence')->placeholder('—')->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('exitCase.status')->label('Exit status')->badge()->color('gray')->formatStateUsing(fn (?string $state) => config("peopleos.exit.statuses.{$state}", $state))->toggleable(),
            ])
            ->defaultSort('id', 'desc')
            ->filters([
                SelectFilter::make('status')->options(config('peopleos.exit.clearance_statuses'))->multiple()->default(['pending', 'blocked']),
                SelectFilter::make('stage')->options(collect(config('peopleos.exit.clearance_stages'))->map(fn (array $s) => $s['name'])->all()),
                SelectFilter::make('exit_status')->label('Exit status')->options(config('peopleos.exit.statuses'))->multiple()
                    ->query(fn (Builder $query, array $data) => $query->when($data['values'] ?? [], fn (Builder $q, array $values) => $q->whereHas('exitCase', fn (Builder $c) => $c->whereIn('status', $values)))),
                Filter::make('overdue')->label('Overdue')->toggle()
                    ->query(fn (Builder $query) => $query->whereIn('status', ['pending', 'blocked'])->whereNotNull('due_on')->whereDate('due_on', '<', today()->toDateString())),
                Filter::make('mine')->label('Mine to clear')->toggle()
                    ->query(function (Builder $query) {
                        $owners = ClearanceOwners::for(auth()->user());

                        return $query->where(fn (Builder $q) => $q->whereIn('owner_user_id', $owners['users'])->when($owners['roles'] !== [], fn (Builder $r) => $r->orWhereIn('owner_role_id', $owners['roles'])));
                    }),
                Filter::make('not_started')->label('Not started (serving notice)')->toggle()
                    ->query(fn (Builder $query) => $query->whereHas('exitCase', fn (Builder $c) => $c->whereIn('status', ['initiated', 'notice']))),
                // HROne parity WO-2: the shared employee filters through the stage's exit; the last working day.
                EmployeeFilters::codes('exitCase.employee'),
                EmployeeFilters::organisation('exitCase.employee'),
                EmployeeFilters::dateRange('last_working_days', 'Last working day', 'last_working_day', 'exitCase', true),
                EmployeeFilters::dateRange('due_dates', 'Due', 'due_on'),
            ])
            ->recordActions([
                Action::make('openExit')->label('Open exit')->icon(Heroicon::OutlinedArrowTopRightOnSquare)->color('gray')
                    ->url(fn (ExitClearance $record) => $record->exitCase ? ExitCaseResource::getUrl('view', ['record' => $record->exitCase]) : null),
                ...ExitActions::forClearance(),
            ])
            // HROne parity WO-11 / WO-1: start the selected stages' clearance, or cancel them with a reason (D-WO3).
            ->toolbarActions([BulkRecordActions::selected('exit_clearances', ListExitClearances::BULK_OPERATIONS)]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListExitClearances::route('/'),
        ];
    }
}
