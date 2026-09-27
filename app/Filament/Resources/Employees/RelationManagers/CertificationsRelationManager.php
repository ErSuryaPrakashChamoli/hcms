<?php

namespace App\Filament\Resources\Employees\RelationManagers;

use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class CertificationsRelationManager extends PersonSatelliteRelationManager
{
    protected static string $relationship = 'certifications';

    public function form(Schema $schema): Schema
    {
        return $schema->columns(2)->components([
            TextInput::make('name')->required()->maxLength(255),
            TextInput::make('issuing_body')->maxLength(255),
            TextInput::make('credential_id')->maxLength(255),
            DatePicker::make('issued_on')->native(false),
            DatePicker::make('expires_on')->native(false)->afterOrEqual('issued_on'),
            Toggle::make('is_verified')->label('Verified'),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name'),
                TextColumn::make('issuing_body')->placeholder('—'),
                TextColumn::make('credential_id')->placeholder('—')->toggleable(),
                TextColumn::make('issued_on')->date()->placeholder('—'),
                TextColumn::make('expires_on')->date()->placeholder('Never')->color(fn ($state) => $state && $state->isPast() ? 'danger' : null),
                IconColumn::make('is_verified')->label('Verified')->boolean(),
            ])
            ->headerActions([CreateAction::make()])
            ->recordActions([EditAction::make(), DeleteAction::make()]);
    }
}
