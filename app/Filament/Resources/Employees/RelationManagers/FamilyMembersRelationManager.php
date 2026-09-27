<?php

namespace App\Filament\Resources\Employees\RelationManagers;

use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class FamilyMembersRelationManager extends PersonSatelliteRelationManager
{
    protected static string $relationship = 'familyMembers';

    protected static ?string $title = 'Family & nominees';

    public function form(Schema $schema): Schema
    {
        return $schema->columns(2)->components([
            TextInput::make('name')->required()->maxLength(255),
            Select::make('relation')->options(config('peopleos.people.family_relations'))->required(),
            DatePicker::make('date_of_birth')->native(false),
            Select::make('gender')->options(config('peopleos.people.genders')),
            TextInput::make('phone')->tel()->maxLength(32),
            Toggle::make('is_dependent')->label('Dependent'),
            Toggle::make('is_nominee')->label('Nominee')->live(),
            TextInput::make('nominee_share')->numeric()->minValue(0)->maxValue(100)->suffix('%')->visible(fn (Get $get) => (bool) $get('is_nominee')),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name'),
                TextColumn::make('relation')->badge()->formatStateUsing(fn (string $state) => config("peopleos.people.family_relations.{$state}", $state)),
                TextColumn::make('date_of_birth')->date()->placeholder('—'),
                IconColumn::make('is_dependent')->label('Dependent')->boolean(),
                IconColumn::make('is_nominee')->label('Nominee')->boolean(),
                TextColumn::make('nominee_share')->suffix('%')->placeholder('—'),
            ])
            ->headerActions([CreateAction::make()])
            ->recordActions([EditAction::make(), DeleteAction::make()]);
    }
}
