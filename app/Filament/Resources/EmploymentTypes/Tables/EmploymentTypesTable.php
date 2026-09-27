<?php

namespace App\Filament\Resources\EmploymentTypes\Tables;

use App\Domain\Organisation\Enums\ActiveStatus;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class EmploymentTypesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->searchable()->sortable(),
                TextColumn::make('code')->searchable()->sortable(),
                TextColumn::make('status')->badge(),
                TextColumn::make('updated_at')->dateTime()->sortable()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('status')->options(ActiveStatus::class),
            ])
            ->defaultSort('name')
            ->recordActions([
                EditAction::make(),
            ]);
    }
}
