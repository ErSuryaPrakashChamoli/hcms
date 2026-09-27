<?php

namespace App\Filament\Resources\CareerPaths\RelationManagers;

use App\Domain\Organisation\Models\Designation;
use App\Domain\People\Models\Skill;
use App\Domain\Performance\Models\CareerPathStep;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class StepsRelationManager extends RelationManager
{
    protected static string $relationship = 'steps';

    protected static ?string $title = 'Steps';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return auth()->user()?->can('performance.manage') ?? false;
    }

    public function form(Schema $schema): Schema
    {
        return $schema->columns(2)->components([
            Select::make('designation_id')->label('Designation')->required()->searchable()->options(fn () => Designation::query()->orderBy('name')->pluck('name', 'id')->all()),
            TextInput::make('sort_order')->numeric()->default(fn () => ($this->getOwnerRecord()->steps()->max('sort_order') ?? 0) + 10),
            TextInput::make('typical_years')->label('Typical years at this step')->numeric()->minValue(0),
            Repeater::make('required_skills')->label('Required skills')->columnSpanFull()->columns(2)->default([])->schema([
                Select::make('skill_id')->label('Skill')->required()->searchable()->options(fn () => Skill::query()->orderBy('name')->pluck('name', 'id')->all()),
                Select::make('proficiency')->options(config('peopleos.people.skill_proficiencies'))->default('intermediate')->required(),
            ]),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with('designation'))
            ->columns([
                TextColumn::make('sort_order')->label('#'),
                TextColumn::make('designation.name')->label('Designation'),
                TextColumn::make('typical_years')->label('Years')->placeholder('—'),
                TextColumn::make('required_skills')->label('Skills')->state(fn (CareerPathStep $record) => Skill::query()->whereIn('id', collect($record->required_skills ?? [])->pluck('skill_id'))->pluck('name', 'id')->map(fn ($n, $id) => $n.' ('.collect($record->required_skills)->firstWhere('skill_id', $id)['proficiency'].')')->implode(', '))->placeholder('—')->wrap(),
            ])
            ->defaultSort('sort_order')
            ->headerActions([CreateAction::make()->label('Add step')])
            ->recordActions([EditAction::make(), DeleteAction::make()]);
    }
}
