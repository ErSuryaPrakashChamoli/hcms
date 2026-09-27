<?php

namespace App\Filament\Resources\WorkflowInstances;

use App\Domain\Workflow\Enums\InstanceStatus;
use App\Domain\Workflow\Models\WorkflowInstance;
use App\Filament\Resources\WorkflowInstances\Pages\ListWorkflowInstances;
use App\Filament\Resources\WorkflowInstances\Pages\ViewWorkflowInstance;
use App\Filament\Resources\WorkflowInstances\RelationManagers\ActionsRelationManager;
use App\Filament\Resources\WorkflowInstances\RelationManagers\TasksRelationManager;
use App\Filament\Support\WorkflowBuilderSchema;
use BackedEnum;
use Filament\Actions\ViewAction;
use Filament\Infolists\Components\KeyValueEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Support\Arr;
use UnitEnum;

class WorkflowInstanceResource extends Resource
{
    protected static ?string $model = WorkflowInstance::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedPlayCircle;

    protected static string|UnitEnum|null $navigationGroup = 'Workflows';

    protected static ?string $navigationLabel = 'Runs';

    protected static ?string $modelLabel = 'workflow run';

    protected static ?int $navigationSort = 20;

    public static function canCreate(): bool
    {
        return false;
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Run')
                ->columns(4)
                ->schema([
                    TextEntry::make('workflow.name')->label('Workflow'),
                    TextEntry::make('version.version')->label('Version')->formatStateUsing(fn ($state) => "v{$state}"),
                    TextEntry::make('status')->badge(),
                    TextEntry::make('outcome')->badge()->color('gray')->placeholder('—'),
                    TextEntry::make('subject_label')->label('Subject')->placeholder('—'),
                    TextEntry::make('current_node_id')->label('Current node')->placeholder('—'),
                    TextEntry::make('starter.name')->label('Started by')->placeholder('System'),
                    TextEntry::make('started_at')->dateTime(),
                    TextEntry::make('wake_at')->dateTime()->placeholder('—'),
                    TextEntry::make('completed_at')->dateTime()->placeholder('—'),
                    TextEntry::make('error')->placeholder('—')->color('danger')->columnSpan(2),
                ]),
            Section::make('Flow')
                ->collapsed()
                ->schema([
                    TextEntry::make('flow')->hiddenLabel()->state(fn (WorkflowInstance $record) => WorkflowBuilderSchema::describe($record->version->definition ?? []))->listWithLineBreaks(),
                ]),
            Section::make('Context')
                ->collapsed()
                ->schema([
                    KeyValueEntry::make('context')->hiddenLabel()->state(fn (WorkflowInstance $record) => collect(Arr::dot($record->context ?? []))->map(fn ($v) => is_scalar($v) || $v === null ? (string) $v : json_encode($v))->all()),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('id')->label('#')->sortable(),
                TextColumn::make('workflow.name')->label('Workflow')->searchable()->sortable(),
                TextColumn::make('subject_label')->label('Subject')->searchable()->placeholder('—'),
                TextColumn::make('status')->badge(),
                TextColumn::make('current_node_id')->label('At')->placeholder('—'),
                TextColumn::make('outcome')->badge()->color('gray')->placeholder('—'),
                TextColumn::make('starter.name')->label('By')->placeholder('System')->toggleable(),
                TextColumn::make('started_at')->dateTime()->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')->options(InstanceStatus::class)->multiple(),
                SelectFilter::make('workflow')->relationship('workflow', 'name')->preload(),
            ])
            ->defaultSort('id', 'desc')
            ->recordActions([ViewAction::make()]);
    }

    public static function getRelations(): array
    {
        return [TasksRelationManager::class, ActionsRelationManager::class];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListWorkflowInstances::route('/'),
            'view' => ViewWorkflowInstance::route('/{record}'),
        ];
    }
}
