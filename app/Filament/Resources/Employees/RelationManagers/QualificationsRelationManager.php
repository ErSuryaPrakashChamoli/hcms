<?php

namespace App\Filament\Resources\Employees\RelationManagers;

use App\Domain\Configuration\Services\OptionLists;
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
            // HROne parity MS-4: the study period, how it was studied and the highest qualification (one per person).
            TextInput::make('year_started')->label('Year of starting')->numeric()->minValue(1940)->maxValue((int) now()->addYear()->format('Y'))
                ->rule(fn (Get $get) => function (string $attribute, mixed $value, \Closure $fail) use ($get): void {
                    if (filled($value) && filled($get('year_of_completion')) && (int) $value > (int) $get('year_of_completion')) {
                        $fail('The year of starting is after the year of passing.');
                    }
                }),
            TextInput::make('year_of_completion')->label('Year of passing')->numeric()->minValue(1950)->maxValue((int) now()->addYears(6)->format('Y')),
            Select::make('mode')->label('Mode of study')->options(fn () => app(OptionLists::class)->options('qualification_modes')),
            TextInput::make('grade_or_score')->maxLength(32),
            Toggle::make('is_highest')->label('This is the highest qualification')->helperText('Marking it moves the mark from any other qualification.'),
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
                TextColumn::make('year_of_completion')->label('Year')->placeholder('—')
                    ->description(fn ($record) => $record->year_started ? 'from '.$record->year_started : null),
                TextColumn::make('mode')->label('Mode')->placeholder('—')->toggleable(isToggledHiddenByDefault: true)
                    ->formatStateUsing(fn (?string $state) => app(OptionLists::class)->label('qualification_modes', $state)),
                IconColumn::make('is_highest')->label('Highest')->boolean(),
                TextColumn::make('grade_or_score')->label('Grade')->placeholder('—'),
                IconColumn::make('is_verified')->label('Verified')->boolean(),
            ])
            ->headerActions([CreateAction::make()])
            ->recordActions([EditAction::make(), DeleteAction::make()]);
    }
}
