<?php

namespace App\Filament\Resources\Assets\RelationManagers;

use App\Domain\Assets\Models\AssetMovement;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class MovementsRelationManager extends RelationManager
{
    protected static string $relationship = 'movements';

    protected static ?string $title = 'Lifecycle';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return auth()->user()?->can('view', $ownerRecord) ?? false;
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with(['fromEmployee.person', 'toEmployee.person', 'fromLocation', 'toLocation', 'actor']))
            ->columns([
                TextColumn::make('occurred_at')->dateTime(),
                TextColumn::make('type')->badge()->color('gray')->formatStateUsing(fn (string $state) => config("peopleos.assets.movement_types.{$state}", $state)),
                TextColumn::make('from')->label('From')->state(fn (AssetMovement $record) => $record->fromEmployee?->person?->full_name ?? $record->fromLocation?->name)->placeholder('—'),
                TextColumn::make('to')->label('To')->state(fn (AssetMovement $record) => $record->toEmployee?->person?->full_name ?? $record->toLocation?->name)->placeholder('—'),
                TextColumn::make('status_after')->label('Status')->badge()->color('gray')->formatStateUsing(fn (?string $state) => config("peopleos.assets.statuses.{$state}", $state)),
                TextColumn::make('note')->placeholder('—')->wrap(),
                TextColumn::make('actor.name')->label('By')->placeholder('System'),
            ])
            ->defaultSort('id', 'desc');
    }
}
