<?php

namespace App\Filament\Resources\Roles\Tables;

use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
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
                TextColumn::make('permissions_count')->counts('permissions')->label('Permissions')->sortable(),
                TextColumn::make('users_count')->counts('users')->label('Users')->sortable(),
            ])
            ->defaultSort('name')
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ]);
    }
}
