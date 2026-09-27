<?php

namespace App\Filament\Resources\Workflows\RelationManagers;

use App\Domain\Configuration\Enums\VersionStatus;
use App\Domain\Workflow\Models\WorkflowVersion;
use App\Domain\Workflow\Services\WorkflowDefinition;
use App\Filament\Support\WorkflowBuilderSchema;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/** The builder lives here: edit the draft's nodes and edges; published versions are read-only. */
class VersionsRelationManager extends RelationManager
{
    protected static string $relationship = 'versions';

    protected static ?string $title = 'Versions & builder';

    public function form(Schema $schema): Schema
    {
        return $schema->components(WorkflowBuilderSchema::components());
    }

    public function infolist(Schema $schema): Schema
    {
        return $schema->components([
            TextEntry::make('flow')->hiddenLabel()->state(fn (WorkflowVersion $record) => WorkflowBuilderSchema::describe($record->definition ?? []))->listWithLineBreaks(),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('version')->formatStateUsing(fn ($state) => "v{$state}")->sortable(),
                TextColumn::make('status')->badge(),
                TextColumn::make('nodes')->state(fn (WorkflowVersion $record) => count($record->definition['nodes'] ?? [])),
                TextColumn::make('flow')->label('Flow')->state(fn (WorkflowVersion $record) => WorkflowBuilderSchema::describe($record->definition ?? []))->listWithLineBreaks()->limitList(4)->expandableLimitedList(),
                TextColumn::make('publisher.name')->label('Published by')->placeholder('—')->toggleable(),
                TextColumn::make('published_at')->dateTime()->placeholder('—')->toggleable(),
            ])
            ->defaultSort('version', 'desc')
            ->recordActions([
                EditAction::make()->label('Open builder')->modalWidth('7xl')
                    ->visible(fn (WorkflowVersion $record) => $record->status === VersionStatus::Draft),
                Action::make('validate')->label('Check')->icon('heroicon-m-check-badge')
                    ->action(function (WorkflowVersion $record) {
                        $errors = app(WorkflowDefinition::class)->validate($record->definition ?? []);
                        $errors === []
                            ? Notification::make()->success()->title('Definition is valid')->send()
                            : Notification::make()->danger()->title('Problems found')->body(implode("\n", $errors))->persistent()->send();
                    }),
            ]);
    }
}
