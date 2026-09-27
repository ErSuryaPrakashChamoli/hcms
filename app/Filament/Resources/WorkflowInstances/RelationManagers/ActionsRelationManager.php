<?php

namespace App\Filament\Resources\WorkflowInstances\RelationManagers;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class ActionsRelationManager extends RelationManager
{
    protected static string $relationship = 'actions';

    protected static ?string $title = 'Run log';

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('created_at')->label('When')->dateTime('d M Y, H:i:s'),
                TextColumn::make('node_id')->label('Node')->placeholder('—'),
                TextColumn::make('action')->badge()->color('gray'),
                TextColumn::make('actor.name')->label('By')->placeholder('System'),
                TextColumn::make('payload')->state(fn ($record) => $record->payload ? json_encode($record->payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null)->placeholder('—')->wrap()->limit(160),
            ])
            ->defaultSort('id')
            ->paginated([25, 50, 100]);
    }
}
