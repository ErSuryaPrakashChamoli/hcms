<?php

namespace App\Filament\Resources\Locations\Tables;

use App\Domain\Organisation\Enums\ActiveStatus;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class LocationsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->searchable()->sortable(),
                TextColumn::make('code')->searchable()->sortable(),
                TextColumn::make('company.name')->label('Company')->sortable()->toggleable()->placeholder('—'),
                TextColumn::make('parentLocation.name')->label('Parent branch')->toggleable()->placeholder('—'),
                TextColumn::make('region.name')->label('Region')->toggleable(isToggledHiddenByDefault: true)->placeholder('—'),
                TextColumn::make('businessRegion.name')->label('Business region')->toggleable(isToggledHiddenByDefault: true)->placeholder('—'),
                TextColumn::make('type')->badge()->toggleable(),
                TextColumn::make('city')->searchable()->toggleable(),
                TextColumn::make('status')->badge(),
                TextColumn::make('effective_from')->date()->sortable()->placeholder('Open')->toggleable(),
                TextColumn::make('effective_to')->date()->sortable()->placeholder('Open')->toggleable(),
                TextColumn::make('updated_at')->dateTime()->sortable()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('status')->options(ActiveStatus::class),
                SelectFilter::make('parent_location_id')->label('Parent branch')->relationship('parentLocation', 'name'),
                SelectFilter::make('region_id')->label('Region')->relationship('region', 'name'),
            ])
            ->defaultSort('name')
            ->recordActions([
                EditAction::make(),
            ]);
    }
}
