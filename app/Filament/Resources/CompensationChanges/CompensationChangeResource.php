<?php

namespace App\Filament\Resources\CompensationChanges;

use App\Domain\Compensation\Models\CompensationChange;
use App\Domain\Employment\Models\Employee;
use App\Filament\Resources\CompensationChanges\Pages\ManageCompensationChanges;
use App\Filament\Support\CompensationActions;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/**
 * Phase 11: the compensation change queue across employees (within organisation scope). Full readers
 * (compensation.view) see every change; reviewers, approvers and executors see the changes waiting for
 * their step and those they acted on; nobody sees their own. Proposals start on Employee 360.
 */
class CompensationChangeResource extends Resource
{
    protected static ?string $model = CompensationChange::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowsRightLeft;

    protected static string|UnitEnum|null $navigationGroup = 'Compensation';

    protected static ?string $navigationLabel = 'Compensation changes';

    protected static ?int $navigationSort = 10;

    public static function getEloquentQuery(): Builder
    {
        $user = auth()->user();
        $query = parent::getEloquentQuery()->with(['employee.person', 'structure', 'proposer', 'reviewer', 'approver', 'executor']);
        if ($user === null) {
            return $query->whereRaw('1 = 0');
        }
        $query->whereNotIn('employee_id', Employee::query()->select('id')->where('user_id', $user->id));
        if ($user->hasPermission('compensation.view')) {
            return $query;
        }

        return $query->where(function (Builder $q) use ($user) {
            $q->where('proposed_by', $user->id)->orWhere('reviewed_by', $user->id)->orWhere('approved_by', $user->id)->orWhere('scheduled_by', $user->id);
            foreach (['submitted' => 'compensation.review', 'under_review' => 'compensation.approve', 'approved' => 'compensation.execute'] as $status => $permission) {
                if ($user->hasPermission($permission)) {
                    $q->orWhere('status', $status);
                }
            }
        });
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('employee.employee_code')->label('Employee')->description(fn (CompensationChange $record) => $record->employee?->person?->full_name)->searchable(),
                TextColumn::make('status')->badge()->formatStateUsing(fn (string $state) => CompensationChange::STATUSES[$state] ?? $state)
                    ->color(fn (string $state) => match ($state) {
                        'effective' => 'success', 'scheduled', 'approved' => 'info', 'rejected', 'cancelled' => 'danger', default => 'warning'
                    }),
                TextColumn::make('change_type')->label('Type')->formatStateUsing(fn (string $state) => config("peopleos.compensation.change_types.{$state}", $state)),
                TextColumn::make('source')->formatStateUsing(fn (string $state) => config("peopleos.compensation.sources.{$state}", $state))->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('effective_from')->label('Effective')->date()->sortable(),
                TextColumn::make('previous_ctc_annual')->label('From CTC')->numeric(2)->placeholder('—'),
                TextColumn::make('ctc_annual')->label('Proposed CTC')->numeric(2)->description(fn (CompensationChange $record) => $record->currency),
                TextColumn::make('increase')->label('Annual increase')->state(fn (CompensationChange $record) => $record->previous_ctc_annual === null ? null : $record->annualIncrease())->numeric(2)->placeholder('—'),
                TextColumn::make('proposer.name')->label('Proposed by')->toggleable(),
                TextColumn::make('approver.name')->label('Approved by')->placeholder('—')->toggleable(),
                TextColumn::make('reference')->limit(10)->tooltip(fn (CompensationChange $record) => $record->reference)->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('status')->options(CompensationChange::STATUSES),
                SelectFilter::make('change_type')->label('Type')->options(config('peopleos.compensation.change_types')),
            ])
            ->defaultSort('id', 'desc')
            ->recordActions([CompensationActions::changeSteps()])
            ->emptyStateHeading('No compensation changes')
            ->emptyStateDescription('Propose a change from an employee\'s Compensation tab in Employee 360.');
    }

    public static function getPages(): array
    {
        return ['index' => ManageCompensationChanges::route('/')];
    }

    public static function canCreate(): bool
    {
        return false;
    }
}
