<?php

namespace App\Filament\Resources\Workflows;

use App\Domain\Organisation\Enums\ActiveStatus;
use App\Domain\Workflow\Models\Workflow;
use App\Filament\RelationManagers\AuditHistoryRelationManager;
use App\Filament\Resources\Workflows\Pages\CreateWorkflow;
use App\Filament\Resources\Workflows\Pages\EditWorkflow;
use App\Filament\Resources\Workflows\Pages\ListWorkflows;
use App\Filament\Resources\Workflows\RelationManagers\InstancesRelationManager;
use App\Filament\Resources\Workflows\RelationManagers\VersionsRelationManager;
use App\Filament\Support\AuditReasonField;
use App\Filament\Support\RuleConditionsSchema;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Support\Str;
use UnitEnum;

class WorkflowResource extends Resource
{
    protected static ?string $model = Workflow::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowPathRoundedSquare;

    protected static string|UnitEnum|null $navigationGroup = 'Workflows';

    protected static ?int $navigationSort = 10;

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Workflow')
                ->columns(2)
                ->schema([
                    TextInput::make('name')->required()->maxLength(255)->live(onBlur: true)
                        ->afterStateUpdated(fn ($state, callable $set, string $operation) => $operation === 'create' ? $set('key', Str::slug($state, '_')) : null),
                    TextInput::make('key')->required()->maxLength(64)->regex('/^[a-z][a-z0-9_]*$/')->disabled(fn (string $operation) => $operation === 'edit')->dehydrated(),
                    Select::make('trigger_event')->options(config('peopleos.workflows.trigger_events'))->default('manual')->required()
                        ->helperText('Published workflows start automatically on this event; "manual" ones start from a record.'),
                    Select::make('subject_type')->label('Runs on')->options(config('peopleos.workflows.subject_types'))->placeholder('Any')
                        ->helperText('Restrict to one record type. Manual workflows on employees appear on the Employee 360.'),
                    Textarea::make('description')->rows(2)->columnSpanFull(),
                    Select::make('status')->options(ActiveStatus::class)->default(ActiveStatus::Active)->required(),
                    // HROne setup parity P5: the order of preference when only the first matching workflow starts.
                    TextInput::make('priority')->numeric()->integer()->minValue(1)->maxValue(999)->default(100)->required()
                        ->helperText('When only the first matching workflow of a trigger starts (Setup → Automation), lower numbers are tried first.'),
                ]),
            Section::make('Start only when')
                ->description('Optional employee-based conditions checked before an automatic start.')
                ->collapsed()
                ->schema([RuleConditionsSchema::repeater()->label('Start conditions')->minItems(0)->defaultItems(0)->statePath('start_conditions')]),
            AuditReasonField::make()->visibleOn('edit'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->searchable()->sortable(),
                TextColumn::make('key')->searchable(),
                TextColumn::make('trigger_event')->label('Trigger')->badge()->color('gray')->formatStateUsing(fn (string $state) => config("peopleos.workflows.trigger_events.{$state}", $state)),
                TextColumn::make('subject_type')->label('Runs on')->formatStateUsing(fn (?string $state) => $state ? config("peopleos.workflows.subject_types.{$state}", class_basename($state)) : 'Any'),
                TextColumn::make('published.version')->label('Published')->placeholder('Never')->formatStateUsing(fn ($state) => "v{$state}"),
                IconColumn::make('draft')->label('Draft')->boolean()->state(fn (Workflow $record) => $record->draft()->exists()),
                TextColumn::make('instances_count')->counts('instances')->label('Runs'),
                TextColumn::make('status')->badge(),
                TextColumn::make('priority')->sortable()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('trigger_event')->options(config('peopleos.workflows.trigger_events')),
                // HROne setup parity P5: workflows by request type.
                SelectFilter::make('subject_type')->label('Runs on')->options(config('peopleos.workflows.subject_types')),
                SelectFilter::make('status')->options(ActiveStatus::class),
            ])
            ->defaultSort('name')
            ->recordActions([EditAction::make()]);
    }

    public static function getRelations(): array
    {
        return [
            VersionsRelationManager::class,
            InstancesRelationManager::class,
            AuditHistoryRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListWorkflows::route('/'),
            'create' => CreateWorkflow::route('/create'),
            'edit' => EditWorkflow::route('/{record}/edit'),
        ];
    }
}
