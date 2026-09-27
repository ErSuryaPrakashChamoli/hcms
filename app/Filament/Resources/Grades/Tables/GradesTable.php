<?php

namespace App\Filament\Resources\Grades\Tables;

use App\Domain\Organisation\Enums\ActiveStatus;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class GradesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->searchable()->sortable(),
                TextColumn::make('code')->searchable()->sortable(),
                TextColumn::make('level.name')->label('Level')->sortable()->toggleable()->placeholder('—'),
                TextColumn::make('rank')->sortable(),
                TextColumn::make('status')->badge(),
                TextColumn::make('effective_from')->date()->sortable()->placeholder('Open')->toggleable(),
                TextColumn::make('effective_to')->date()->sortable()->placeholder('Open')->toggleable(),
                TextColumn::make('updated_at')->dateTime()->sortable()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('status')->options(ActiveStatus::class),
            ])
            ->defaultSort('rank')
            ->recordActions([
                EditAction::make(),
            ]);
    }
}
