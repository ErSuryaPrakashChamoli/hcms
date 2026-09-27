<?php

namespace App\Filament\Resources\WorkflowInstances\RelationManagers;

use App\Domain\Workflow\Models\WorkflowTask;
use App\Filament\Support\TaskActions;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class TasksRelationManager extends RelationManager
{
    protected static string $relationship = 'tasks';

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('node_id')->label('Node'),
                TextColumn::make('type')->badge()->color('gray'),
                TextColumn::make('title')->wrap(),
                TextColumn::make('assignee')->label('Assigned to')->state(fn (WorkflowTask $record) => $record->assigneeLabel()),
                TextColumn::make('sequence')->label('Seq')->toggleable(),
                TextColumn::make('status')->badge(),
                TextColumn::make('decision')->placeholder('—')->toggleable(),
                TextColumn::make('note')->placeholder('—')->wrap()->toggleable(),
                TextColumn::make('due_at')->dateTime()->placeholder('—')->color(fn (WorkflowTask $record) => $record->isOverdue() ? 'danger' : null),
                TextColumn::make('escalation_level')->label('Esc.')->toggleable(),
                TextColumn::make('completer.name')->label('By')->placeholder('—')->toggleable(),
                TextColumn::make('completed_at')->dateTime()->placeholder('—')->toggleable(),
            ])
            ->recordActions(TaskActions::forTable());
    }
}
