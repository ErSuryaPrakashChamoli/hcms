<?php

namespace App\Filament\Resources\Employees\RelationManagers;

use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/** Career Passport seed (blueprint §36): skills with proficiency. */
class SkillsRelationManager extends PersonSatelliteRelationManager
{
    protected static string $relationship = 'personSkills';

    protected static ?string $title = 'Skills';

    public function form(Schema $schema): Schema
    {
        return $schema->columns(2)->components([
            Select::make('skill_id')->label('Skill')->relationship('skill', 'name')->searchable()->preload()->required(),
            Select::make('proficiency')->options(config('peopleos.people.skill_proficiencies')),
            TextInput::make('years_of_experience')->numeric()->minValue(0)->maxValue(60),
            DatePicker::make('last_used_on')->native(false),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('skill.name')->label('Skill'),
                TextColumn::make('skill.category')->label('Category')->badge()->color('gray')->placeholder('—'),
                TextColumn::make('proficiency')->badge()->formatStateUsing(fn (?string $state) => config("peopleos.people.skill_proficiencies.{$state}", $state))->placeholder('—'),
                TextColumn::make('years_of_experience')->label('Years')->placeholder('—'),
                TextColumn::make('last_used_on')->date()->placeholder('—'),
            ])
            ->headerActions([CreateAction::make()])
            ->recordActions([EditAction::make(), DeleteAction::make()]);
    }
}
