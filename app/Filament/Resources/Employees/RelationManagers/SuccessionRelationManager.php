<?php

namespace App\Filament\Resources\Employees\RelationManagers;

use App\Domain\Succession\Models\ReadinessAssessment;
use App\Domain\Succession\Models\Successor;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/** Employee 360 → Succession (Phase 9): candidacy and readiness. succession.view within scope or succession.team for one's team; never the employee without succession.own_candidacy. */
class SuccessionRelationManager extends RelationManager
{
    protected static string $relationship = 'successorEntries';

    protected static ?string $title = 'Succession';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return auth()->user()?->can('view', new Successor(['employee_id' => $ownerRecord->id])) ?? false;
    }

    public function table(Table $table): Table
    {
        $employeeId = $this->getOwnerRecord()->id;

        return $table
            ->description('Confidential. Readiness is a label recorded by an authorised person, not a prediction.')
            ->modifyQueryUsing(fn ($query) => $query->with('plan.position'))
            ->columns([
                TextColumn::make('plan.position.title')->label('Critical position'),
                TextColumn::make('readiness')->label('Readiness')->state(fn (Successor $record) => ReadinessAssessment::query()->where('employee_id', $employeeId)
                    ->where('target_key', ReadinessAssessment::targetKey($record->plan?->critical_position_id, null))->where('status', 'current')->value('readiness_level'))
                    ->formatStateUsing(fn (?string $state) => config("peopleos.talent.readiness_levels.{$state}", $state))->placeholder('Not assessed'),
                TextColumn::make('added_at')->date(),
                TextColumn::make('removed_at')->date()->placeholder('—'),
                TextColumn::make('status')->badge()->color(fn (string $state) => $state === 'active' ? 'success' : 'gray'),
            ])
            ->defaultSort('id', 'desc');
    }
}
