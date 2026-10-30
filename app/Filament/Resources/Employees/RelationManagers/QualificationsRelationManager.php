<?php

namespace App\Filament\Resources\Employees\RelationManagers;

use App\Domain\People\Models\Qualification;
use App\Domain\People\Models\QualificationSpecialisation;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class QualificationsRelationManager extends PersonSatelliteRelationManager
{
    protected static string $relationship = 'qualifications';

    protected static string $profileSection = 'professional';

    protected static ?string $title = 'Education';

    public function form(Schema $schema): Schema
    {
        return $schema->columns(2)->components([
            // HROne setup parity CA-3: pick from the qualification masters when there are any; text stays for anything else.
            Select::make('qualification_id')->label('Qualification')->placeholder('Other (type it)')->searchable()->live()
                ->options(fn () => Qualification::query()->where('status', 'active')->orderBy('sort_order')->orderBy('name')->pluck('name', 'id')->all())
                ->visible(fn () => Qualification::query()->exists()),
            Select::make('specialisation_id')->label('Specialisation')->placeholder('Other (type it)')->searchable()
                ->options(fn (Get $get) => filled($get('qualification_id')) ? QualificationSpecialisation::query()->where('qualification_id', $get('qualification_id'))->where('status', 'active')->orderBy('name')->pluck('name', 'id')->all() : [])
                ->visible(fn (Get $get) => filled($get('qualification_id'))),
            TextInput::make('qualification')->label(fn () => Qualification::query()->exists() ? 'Qualification (if not in the list)' : 'Qualification')->maxLength(255)->placeholder('B.Tech, MBA, 12th…')
                ->required(fn (Get $get) => blank($get('qualification_id')))->hidden(fn (Get $get) => filled($get('qualification_id'))),
            TextInput::make('specialisation')->maxLength(255)->hidden(fn (Get $get) => filled($get('specialisation_id'))),
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
