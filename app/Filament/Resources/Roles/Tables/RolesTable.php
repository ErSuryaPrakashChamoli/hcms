<?php

namespace App\Filament\Resources\Roles\Tables;

use App\Domain\Organisation\Enums\ActiveStatus;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class RolesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->searchable()->sortable(),
                TextColumn::make('slug')->searchable()->toggleable(),
                TextColumn::make('description')->wrap()->toggleable(),
                IconColumn::make('is_system')->label('System')->boolean(),
                TextColumn::make('status')->badge()->sortable(),
                TextColumn::make('reliever.name')->label('Reliever')->placeholder('—')->toggleable(),
                TextColumn::make('permissions_count')->counts('permissions')->label('Permissions')->sortable(),
                TextColumn::make('users_count')->counts('users')->label('Users')->sortable(),
                // HROne parity CA-9: page access per My space item.
                TextColumn::make('myspace_hidden_items')->label('Left out of My space')->toggleable()
                    ->state(fn ($record) => is_array($record->myspace_hidden_items) ? count($record->myspace_hidden_items) : 0)
                    ->formatStateUsing(fn ($state) => $state > 0 ? $state.' pages' : 'None'),
            ])
            ->filters([SelectFilter::make('status')->options(ActiveStatus::class)])
            ->defaultSort('name')
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ]);
    }
}
