<?php

namespace App\Filament\Resources\Employees\RelationManagers;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/** Phase 14 Employee 360: exit cases (Exit owns them) — type, status and dates; the reason and notes stay on the case. */
class ExitRelationManager extends RelationManager
{
    protected static string $relationship = 'exitCases';

    protected static ?string $title = 'Exit';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        $user = auth()->user();

        return $user !== null && ($user->hasPermission('exit.view') || $user->hasPermission('exit.manage') || (int) $ownerRecord->user_id === (int) $user->id);
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('number'),
                TextColumn::make('type')->formatStateUsing(fn (string $state) => config("peopleos.exit.types.{$state}", $state)),
                TextColumn::make('status')->badge(),
                TextColumn::make('initiated_on')->date()->placeholder('—'),
                TextColumn::make('last_working_day')->date()->placeholder('—'),
            ])
            ->defaultSort('id', 'desc');
    }
}
