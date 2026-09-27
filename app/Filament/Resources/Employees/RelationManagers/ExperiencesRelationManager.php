<?php

namespace App\Filament\Resources\Employees\RelationManagers;

use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class ExperiencesRelationManager extends PersonSatelliteRelationManager
{
    protected static string $relationship = 'experiences';

    protected static ?string $title = 'Previous employment';

    public function form(Schema $schema): Schema
    {
        return $schema->columns(2)->components([
            TextInput::make('employer')->required()->maxLength(255),
            TextInput::make('designation')->maxLength(255),
            DatePicker::make('from_date')->native(false)->required(),
            DatePicker::make('to_date')->native(false)->afterOrEqual('from_date'),
            TextInput::make('location')->maxLength(255),
            TextInput::make('reason_for_leaving')->maxLength(255),
            Textarea::make('responsibilities')->rows(3)->columnSpanFull(),
            Toggle::make('is_verified')->label('Verified'),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('employer')->description(fn ($record) => $record->designation),
                TextColumn::make('from_date')->date(),
                TextColumn::make('to_date')->date()->placeholder('—'),
                TextColumn::make('location')->placeholder('—'),
                IconColumn::make('is_verified')->label('Verified')->boolean(),
            ])
            ->headerActions([CreateAction::make()])
            ->recordActions([EditAction::make(), DeleteAction::make()]);
    }
}
