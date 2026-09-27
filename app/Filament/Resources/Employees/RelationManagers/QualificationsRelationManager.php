<?php

namespace App\Filament\Resources\Employees\RelationManagers;

use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class QualificationsRelationManager extends PersonSatelliteRelationManager
{
    protected static string $relationship = 'qualifications';

    protected static ?string $title = 'Education';

    public function form(Schema $schema): Schema
    {
        return $schema->columns(2)->components([
            TextInput::make('qualification')->required()->maxLength(255)->placeholder('B.Tech, MBA, 12th…'),
            TextInput::make('specialisation')->maxLength(255),
            TextInput::make('institution')->maxLength(255),
            TextInput::make('board_or_university')->maxLength(255),
            TextInput::make('year_of_completion')->numeric()->minValue(1950)->maxValue((int) now()->addYears(6)->format('Y')),
            TextInput::make('grade_or_score')->maxLength(32),
            Toggle::make('is_verified')->label('Verified'),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('qualification')->description(fn ($record) => $record->specialisation),
                TextColumn::make('institution')->placeholder('—'),
                TextColumn::make('board_or_university')->label('Board / University')->placeholder('—')->toggleable(),
                TextColumn::make('year_of_completion')->label('Year')->placeholder('—'),
                TextColumn::make('grade_or_score')->label('Grade')->placeholder('—'),
                IconColumn::make('is_verified')->label('Verified')->boolean(),
            ])
            ->headerActions([CreateAction::make()])
            ->recordActions([EditAction::make(), DeleteAction::make()]);
    }
}
