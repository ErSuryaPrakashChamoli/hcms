<?php

namespace App\Filament\Resources\Companies\Tables;

use App\Domain\Organisation\Enums\ActiveStatus;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class CompaniesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->searchable()->sortable(),
                TextColumn::make('code')->searchable()->sortable(),
                TextColumn::make('legal_name')->searchable()->toggleable(),
                TextColumn::make('status')->badge(),
                TextColumn::make('country_code')->label('Country')->toggleable(),
                TextColumn::make('effective_from')->date()->sortable()->placeholder('Open'),
                TextColumn::make('effective_to')->date()->sortable()->placeholder('Open'),
                TextColumn::make('updated_at')->dateTime()->sortable()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('status')->options(ActiveStatus::class),
            ])
            ->defaultSort('name')
            ->recordActions([
                ViewAction::make(),
                EditAction::make(),
            ]);
    }
}
