<?php

namespace App\Filament\Resources\Employees\RelationManagers;

use App\Domain\Performance\Services\PerformanceRelationships;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/** Phase 14 Employee 360: goals (Performance owns them). HR with performance.view, the configured manager, or the employee. */
class GoalsRelationManager extends RelationManager
{
    protected static string $relationship = 'goals';

    protected static ?string $title = 'Goals';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        $user = auth()->user();
        if ($user === null) {
            return false;
        }
        $relationships = app(PerformanceRelationships::class);

        return $user->hasPermission('performance.view') || (int) $ownerRecord->user_id === (int) $user->id || $relationships->manages($relationships->forUser($user), $ownerRecord->getKey());
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('title')->wrap(),
                TextColumn::make('status')->badge(),
                TextColumn::make('progress')->suffix('%')->placeholder('—'),
                TextColumn::make('weight')->placeholder('—'),
                TextColumn::make('due_date')->date()->placeholder('—'),
            ])
            ->defaultSort('id', 'desc');
    }
}
