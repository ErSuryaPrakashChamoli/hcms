<?php

namespace App\Filament\Resources\Employees\RelationManagers;

use App\Domain\Compensation\Models\CompensationChange;
use App\Domain\Compensation\Services\CompensationAccess;
use App\Domain\Identity\Services\AccessScopes;
use App\Filament\Support\CompensationActions;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Employee 360 → Compensation changes (Phase 11): proposals and their decisions. Shown to full
 * compensation readers and to the people with a duty in the approval chain; never to the employee
 * or to managers through compensation.team. Every action goes through CompensationChanges, which
 * enforces proposer ≠ reviewer ≠ approver ≠ executor.
 */
class CompensationChangesRelationManager extends RelationManager
{
    protected static string $relationship = 'compensationChanges';

    protected static ?string $title = 'Compensation changes';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        $user = auth()->user();
        if ($user === null || (int) $ownerRecord->user_id === (int) $user->id) {
            return false;
        }
        $access = app(CompensationAccess::class);
        if ($access->level($user, $ownerRecord) === 'full') {
            return true;
        }

        return collect(['compensation.propose', 'compensation.review', 'compensation.approve', 'compensation.execute'])->contains(fn ($p) => $user->hasPermission($p))
            && app(AccessScopes::class)->allows($user, $ownerRecord);
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['structure', 'proposer', 'reviewer', 'approver', 'executor']))
            ->columns([
                TextColumn::make('reference')->label('Reference')->copyable()->limit(10)->tooltip(fn (CompensationChange $record) => $record->reference),
                TextColumn::make('status')->badge()->formatStateUsing(fn (string $state) => CompensationChange::STATUSES[$state] ?? $state)
                    ->color(fn (string $state) => match ($state) {
                        'effective' => 'success', 'scheduled', 'approved' => 'info', 'rejected', 'cancelled' => 'danger', default => 'warning'
                    }),
                TextColumn::make('change_type')->label('Type')->formatStateUsing(fn (string $state) => config("peopleos.compensation.change_types.{$state}", $state)),
                TextColumn::make('effective_from')->label('Effective')->date()->sortable(),
                TextColumn::make('previous_ctc_annual')->label('From CTC')->numeric(2)->placeholder('—'),
                TextColumn::make('ctc_annual')->label('Proposed CTC')->numeric(2)->description(fn (CompensationChange $record) => $record->currency),
                TextColumn::make('structure.name')->label('Structure')->toggleable(),
                TextColumn::make('reason')->wrap()->limit(80)->toggleable(),
                TextColumn::make('proposer.name')->label('Proposed by'),
                TextColumn::make('reviewer.name')->label('Reviewed by')->placeholder('—')->toggleable(),
                TextColumn::make('approver.name')->label('Approved by')->placeholder('—')->toggleable(),
                TextColumn::make('executor.name')->label('Executed by')->placeholder('—')->toggleable(),
            ])
            ->defaultSort('id', 'desc')
            ->recordActions([CompensationActions::changeSteps()]);
    }
}
