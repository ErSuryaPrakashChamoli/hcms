<?php

namespace App\Filament\Resources\IntegrationSystems\RelationManagers;

use App\Domain\Integration\Models\IntegrationMapping;
use App\Domain\Integration\Services\IntegrationMappings;
use App\Domain\Integration\Support\LinkableEntities;
use App\Filament\Support\ServiceDeskActions;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use RuntimeException;

/** Phase 14: external values → PeopleOS records (without a mapping the PeopleOS code is used). */
class MappingsRelationManager extends RelationManager
{
    protected static string $relationship = 'mappings';

    protected static ?string $title = 'Mappings';

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('dimension')->badge()->color('gray'),
                TextColumn::make('external_value')->searchable(),
                TextColumn::make('peopleos_id')->label('PeopleOS record')->state(fn (IntegrationMapping $record) => app(LinkableEntities::class)->find($record->peopleos_type, $record->peopleos_id)?->getAttribute('code') ?? '#'.$record->peopleos_id),
                TextColumn::make('status')->badge(),
            ])
            ->headerActions([
                Action::make('map')->label('Add mapping')->icon('heroicon-m-plus')->visible(fn () => auth()->user()->can('integration.manage'))
                    ->schema([
                        Select::make('dimension')->options(collect(LinkableEntities::TYPES)->except(['employee', 'person'])->keys()->mapWithKeys(fn ($k) => [$k => ucfirst(str_replace('_', ' ', $k))])->all())->required()->live(),
                        TextInput::make('external_value')->required()->maxLength(191),
                        TextInput::make('peopleos_code')->label('PeopleOS code')->required(),
                    ])
                    ->action(fn (array $data) => ServiceDeskActions::run(function () use ($data) {
                        $target = app(LinkableEntities::class)->findByCodeOrId($data['dimension'], $data['peopleos_code']) ?? throw new RuntimeException('No PeopleOS record has that code.');

                        return app(IntegrationMappings::class)->map($this->getOwnerRecord(), $data['dimension'], $data['external_value'], $target);
                    }, 'Mapping added')),
            ]);
    }
}
