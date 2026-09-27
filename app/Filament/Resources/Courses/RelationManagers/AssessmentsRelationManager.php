<?php

namespace App\Filament\Resources\Courses\RelationManagers;

use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/** One assessment per course; answers are indexes into the options list. */
class AssessmentsRelationManager extends RelationManager
{
    protected static string $relationship = 'assessments';

    protected static ?string $title = 'Assessment';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return auth()->user()?->can('learning.manage') ?? false;
    }

    public function form(Schema $schema): Schema
    {
        return $schema->columns(3)->components([
            TextInput::make('title')->required()->maxLength(255),
            TextInput::make('passing_score')->numeric()->minValue(0)->maxValue(100)->default(70)->suffix('%')->required(),
            TextInput::make('time_limit_minutes')->numeric()->minValue(1)->suffix('min')->placeholder('None'),
            Toggle::make('shuffle')->label('Shuffle questions'),
            Repeater::make('questions')->columnSpanFull()->minItems(1)->columns(4)->schema([
                TextInput::make('question')->required()->columnSpan(2),
                TagsInput::make('options')->required()->placeholder('Type an option and press enter'),
                TextInput::make('answer')->label('Correct option # (0-based)')->numeric()->minValue(0)->required(),
                TextInput::make('marks')->numeric()->minValue(1)->default(1),
            ]),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('title'),
                TextColumn::make('questions')->label('Questions')->state(fn ($record) => count($record->questions ?? [])),
                TextColumn::make('passing_score')->suffix('%'),
                TextColumn::make('time_limit_minutes')->label('Time limit')->suffix(' min')->placeholder('—'),
            ])
            ->headerActions([CreateAction::make()->label('Add assessment')->visible(fn () => $this->getOwnerRecord()->assessments()->doesntExist())])
            ->recordActions([EditAction::make(), DeleteAction::make()]);
    }
}
