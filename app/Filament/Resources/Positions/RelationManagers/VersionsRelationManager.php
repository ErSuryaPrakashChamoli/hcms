<?php

namespace App\Filament\Resources\Positions\RelationManagers;

use App\Domain\Workforce\Models\PositionVersion;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/** Position → History: every effective-dated version (same-day superseded versions are kept and marked). */
class VersionsRelationManager extends RelationManager
{
    protected static string $relationship = 'versions';

    protected static ?string $title = 'History';

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with(['designation', 'parent']))
            ->columns([
                TextColumn::make('version')->prefix('v'),
                TextColumn::make('status')->badge()->formatStateUsing(fn (string $state) => config("peopleos.workforce.position_statuses.{$state}", $state)),
                TextColumn::make('effective_from')->date(),
                TextColumn::make('effective_to')->date()->placeholder('open')->description(fn (PositionVersion $record) => $record->isInForce() ? null : 'superseded the same day'),
                TextColumn::make('title'),
                TextColumn::make('headcount')->label('Seats'),
                TextColumn::make('fte_capacity')->label('FTE'),
                TextColumn::make('parent.code')->label('Parent')->placeholder('—'),
                TextColumn::make('change_type')->badge()->color('gray'),
                TextColumn::make('reason')->wrap()->placeholder('—')->toggleable(),
            ])
            ->defaultSort('version', 'desc');
    }
}
