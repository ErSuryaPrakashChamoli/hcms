<?php

namespace App\Filament\Resources\Employees\RelationManagers;

use App\Domain\People\Actions\ChangeEmergencyContactAction;
use App\Filament\Support\ProfileChangeActions;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

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
            // Phase 12: every write goes through the People change action (authorization, validation, audit).
            ->headerActions([CreateAction::make()->using(fn (array $data) => ProfileChangeActions::run(fn () => app(ChangeEmergencyContactAction::class)->add($this->getOwnerRecord(), $data, auth()->user())))])
            ->recordActions([
                EditAction::make()->using(fn (Model $record, array $data) => ProfileChangeActions::run(fn () => app(ChangeEmergencyContactAction::class)->update($this->getOwnerRecord(), $record, $data, auth()->user()))),
                DeleteAction::make()->using(fn (Model $record) => ProfileChangeActions::run(fn () => app(ChangeEmergencyContactAction::class)->remove($this->getOwnerRecord(), $record, auth()->user()) ?? true)),
            ]);
    }
}
