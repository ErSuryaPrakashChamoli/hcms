<?php

namespace App\Filament\Resources\Employees\RelationManagers;

use App\Domain\People\Actions\ChangeAddressAction;
use App\Filament\Support\ProfileChangeActions;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class AddressesRelationManager extends PersonSatelliteRelationManager
{
    protected static string $relationship = 'addresses';

    public function form(Schema $schema): Schema
    {
        return $schema->columns(2)->components([
            Select::make('type')->options(config('peopleos.people.address_types'))->required(),
            TextInput::make('country_code')->required()->default('IN')->length(2),
            TextInput::make('address_line_1')->required()->maxLength(255)->columnSpanFull(),
            TextInput::make('address_line_2')->maxLength(255)->columnSpanFull(),
            TextInput::make('city')->maxLength(255),
            TextInput::make('state_code')->maxLength(16),
            TextInput::make('postal_code')->maxLength(16),
            DatePicker::make('effective_from')->native(false),
            DatePicker::make('effective_to')->native(false)->afterOrEqual('effective_from'),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('type')->badge()->formatStateUsing(fn (string $state) => config("peopleos.people.address_types.{$state}", $state)),
                TextColumn::make('address_line_1')->description(fn ($record) => $record->address_line_2)->wrap(),
                TextColumn::make('city'),
                TextColumn::make('state_code')->label('State'),
                TextColumn::make('postal_code')->label('PIN'),
                TextColumn::make('effective_from')->date()->placeholder('—'),
                TextColumn::make('effective_to')->date()->placeholder('Current'),
            ])
            // Phase 12: every write goes through the People change action (authorization, validation, audit).
            ->headerActions([CreateAction::make()->using(fn (array $data) => ProfileChangeActions::run(fn () => app(ChangeAddressAction::class)->add($this->getOwnerRecord(), $data, auth()->user())))])
            ->recordActions([
                EditAction::make()->using(fn (Model $record, array $data) => ProfileChangeActions::run(fn () => app(ChangeAddressAction::class)->update($this->getOwnerRecord(), $record, $data, auth()->user()))),
                DeleteAction::make()->using(fn (Model $record) => ProfileChangeActions::run(fn () => app(ChangeAddressAction::class)->remove($this->getOwnerRecord(), $record, auth()->user()) ?? true)),
            ]);
    }
}
