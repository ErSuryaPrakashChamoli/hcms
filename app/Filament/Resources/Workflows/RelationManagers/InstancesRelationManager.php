<?php

namespace App\Filament\Resources\Workflows\RelationManagers;

use App\Domain\Workflow\Enums\InstanceStatus;
use App\Domain\Workflow\Models\WorkflowInstance;
use App\Filament\Resources\WorkflowInstances\WorkflowInstanceResource;
use Filament\Actions\ViewAction;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class InstancesRelationManager extends RelationManager
{
    protected static string $relationship = 'instances';

    protected static ?string $title = 'Runs';

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('id')->label('#')->sortable(),
                TextColumn::make('subject_label')->label('Subject')->placeholder('—'),
                TextColumn::make('version.version')->label('Version')->formatStateUsing(fn ($state) => "v{$state}"),
                TextColumn::make('status')->badge(),
                TextColumn::make('current_node_id')->label('At')->placeholder('—'),
                TextColumn::make('outcome')->badge()->color('gray')->placeholder('—'),
                TextColumn::make('started_at')->dateTime()->sortable(),
            ])
            ->filters([SelectFilter::make('status')->options(InstanceStatus::class)])
            ->defaultSort('id', 'desc')
            ->recordActions([
                ViewAction::make()->url(fn (WorkflowInstance $record) => WorkflowInstanceResource::getUrl('view', ['record' => $record])),
            ]);
    }
}
