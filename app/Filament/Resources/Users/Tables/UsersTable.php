<?php

namespace App\Filament\Resources\Users\Tables;

use App\Domain\Identity\Enums\UserStatus;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class UsersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->searchable()->sortable(),
                TextColumn::make('email')->searchable()->sortable(),
                TextColumn::make('roles.name')->badge()->separator(','),
                TextColumn::make('status')->badge(),
                TextColumn::make('last_login_at')->dateTime()->sortable()->placeholder('Never'),
                TextColumn::make('created_at')->dateTime()->sortable()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('status')->options(UserStatus::class),
                SelectFilter::make('roles')->relationship('roles', 'name')->multiple()->preload(),
            ])
            ->defaultSort('name')
            ->recordActions([
                EditAction::make(),
            ]);
    }
}
