<?php

namespace App\Filament\Resources\Courses\RelationManagers;

use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class ModulesRelationManager extends RelationManager
{
    protected static string $relationship = 'modules';

    protected static ?string $title = 'Modules';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return auth()->user()?->can('learning.manage') ?? false;
    }

    public function form(Schema $schema): Schema
    {
        return $schema->columns(2)->components([
            TextInput::make('title')->required()->maxLength(255),
            Select::make('type')->options(collect(config('peopleos.learning.module_types'))->except('assessment')->all())->default('text')->required()->live(),
            TextInput::make('url')->url()->maxLength(255)->visible(fn (Get $get) => in_array($get('type'), ['video', 'document', 'link'], true)),
            TextInput::make('duration_minutes')->numeric()->minValue(0)->suffix('min'),
            TextInput::make('sort_order')->numeric()->default(fn () => ($this->getOwnerRecord()->modules()->max('sort_order') ?? 0) + 10),
            Textarea::make('content')->rows(6)->columnSpanFull()->visible(fn (Get $get) => $get('type') === 'text'),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('sort_order')->label('#'),
                TextColumn::make('title'),
                TextColumn::make('type')->badge()->color('gray'),
                TextColumn::make('duration_minutes')->label('Duration')->suffix(' min')->placeholder('—'),
                TextColumn::make('url')->limit(40)->placeholder('—'),
            ])
            ->defaultSort('sort_order')
            ->headerActions([CreateAction::make()->label('Add module')])
            ->recordActions([EditAction::make(), DeleteAction::make()]);
    }
}
