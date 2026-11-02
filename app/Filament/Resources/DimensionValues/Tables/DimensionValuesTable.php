<?php

namespace App\Filament\Resources\DimensionValues\Tables;

use App\Domain\Organisation\Enums\ActiveStatus;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class DimensionValuesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with('parent'))
            ->columns([
                TextColumn::make('name')->searchable()->sortable(),
                TextColumn::make('code')->searchable()->sortable(),
                TextColumn::make('parent.name')->label('Parent value')->placeholder('—'),
                TextColumn::make('description')->limit(60)->toggleable()->placeholder('—'),
                TextColumn::make('status')->badge(),
            ])
            ->filters([
                SelectFilter::make('status')->options(ActiveStatus::class),
                SelectFilter::make('parent_id')->label('Parent value')->relationship('parent', 'name'),
            ])
            ->defaultSort('name')
            ->recordActions([EditAction::make()]);
    }
}
