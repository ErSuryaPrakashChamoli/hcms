<?php

namespace App\Filament\Resources\Employees\RelationManagers;

use App\Domain\Workflow\Models\WorkflowInstance;
use App\Filament\Resources\WorkflowInstances\WorkflowInstanceResource;
use Filament\Actions\ViewAction;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/** Workflow runs about this employee. Start new ones from the page header. */
class WorkflowsRelationManager extends RelationManager
{
    protected static string $relationship = 'workflowInstances';

    protected static ?string $title = 'Workflows';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return auth()->user()?->can('workflow.view') ?? false;
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('id')->label('#'),
                TextColumn::make('workflow.name')->label('Workflow'),
                TextColumn::make('status')->badge(),
                TextColumn::make('current_node_id')->label('At')->placeholder('—'),
                TextColumn::make('outcome')->badge()->color('gray')->placeholder('—'),
                TextColumn::make('started_at')->dateTime()->sortable(),
                TextColumn::make('completed_at')->dateTime()->placeholder('—'),
            ])
            ->defaultSort('id', 'desc')
            ->recordActions([ViewAction::make()->url(fn (WorkflowInstance $record) => WorkflowInstanceResource::getUrl('view', ['record' => $record]))]);
    }
}
