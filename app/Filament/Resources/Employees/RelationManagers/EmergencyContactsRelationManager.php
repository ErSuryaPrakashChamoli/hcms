<?php

namespace App\Filament\Resources\Employees\RelationManagers;

use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class EmergencyContactsRelationManager extends PersonSatelliteRelationManager
{
    protected static string $relationship = 'emergencyContacts';

    public function form(Schema $schema): Schema
    {
        return $schema->columns(2)->components([
            TextInput::make('name')->required()->maxLength(255),
            TextInput::make('relation')->maxLength(64),
            TextInput::make('phone')->tel()->required()->maxLength(32),
            TextInput::make('alternate_phone')->tel()->maxLength(32),
            TextInput::make('email')->email()->maxLength(255),
            TextInput::make('priority')->numeric()->minValue(1)->maxValue(9)->default(1),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('priority')->sortable(),
                TextColumn::make('name'),
                TextColumn::make('relation')->placeholder('—'),
                TextColumn::make('phone'),
                TextColumn::make('alternate_phone')->placeholder('—'),
                TextColumn::make('email')->placeholder('—'),
            ])
            ->defaultSort('priority')
            ->headerActions([CreateAction::make()])
            ->recordActions([EditAction::make(), DeleteAction::make()]);
    }
}
