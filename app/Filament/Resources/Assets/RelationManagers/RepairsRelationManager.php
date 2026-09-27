<?php

namespace App\Filament\Resources\Assets\RelationManagers;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class RepairsRelationManager extends RelationManager
{
    protected static string $relationship = 'repairs';

    protected static ?string $title = 'Repairs';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return auth()->user()?->can('asset.manage') || auth()->user()?->can('asset.view');
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('sent_on')->date(),
                TextColumn::make('returned_on')->date()->placeholder('—'),
                TextColumn::make('vendor')->placeholder('—'),
                TextColumn::make('issue')->wrap(),
                TextColumn::make('resolution')->placeholder('—')->wrap(),
                TextColumn::make('cost')->numeric(2)->placeholder('—'),
                TextColumn::make('status')->badge()->color(fn (string $state) => $state === 'open' ? 'warning' : 'success'),
            ])
            ->defaultSort('id', 'desc');
    }
}
