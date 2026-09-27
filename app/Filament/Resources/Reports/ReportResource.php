<?php

namespace App\Filament\Resources\Reports;

use App\Domain\Analytics\Models\Report;
use App\Domain\Analytics\Services\DatasetRegistry;
use App\Filament\RelationManagers\AuditHistoryRelationManager;
use App\Filament\Resources\Reports\Pages\CreateReport;
use App\Filament\Resources\Reports\Pages\EditReport;
use App\Filament\Resources\Reports\Pages\ListReports;
use App\Filament\Resources\Reports\Pages\ViewReport;
use App\Filament\Resources\Reports\RelationManagers\RunsRelationManager;
use App\Filament\Resources\Reports\RelationManagers\SchedulesRelationManager;
use App\Filament\Support\ReportBuilderSchema;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/** Report builder (§84). */
class ReportResource extends Resource
{
    protected static ?string $model = Report::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTableCells;

    protected static string|UnitEnum|null $navigationGroup = 'Analytics';

    protected static ?string $navigationLabel = 'Reports';

    protected static ?int $navigationSort = 20;

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery()->with('owner')->withCount(['schedules', 'runs']);
        $user = auth()->user();

        return $user->can('analytics.manage') ? $query : $query->where(fn (Builder $q) => $q->where('is_shared', true)->orWhere('owner_id', $user->id));
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Report')->columns(3)->schema([
                TextInput::make('name')->required()->maxLength(255)->columnSpan(2),
                Toggle::make('is_shared')->label('Shared with everyone who can see the dataset')->inline(false)->visible(fn () => auth()->user()->can('analytics.manage')),
                Textarea::make('description')->rows(2)->columnSpanFull(),
            ]),
            ...ReportBuilderSchema::make(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->searchable()->sortable()->description(fn (Report $record) => $record->description),
                TextColumn::make('dataset')->badge()->color('gray')->formatStateUsing(fn (string $state) => app(DatasetRegistry::class)->get($state)->label()),
                TextColumn::make('visualization')->label('Shows as')->state(fn (Report $record) => config('peopleos.analytics.visualizations.'.$record->def('visualization.type', 'table'))),
                IconColumn::make('is_shared')->label('Shared')->boolean(),
                TextColumn::make('owner.name')->label('Owner')->placeholder('—'),
                TextColumn::make('schedules_count')->label('Schedules'),
                TextColumn::make('runs_count')->label('Runs'),
            ])
            ->defaultSort('name')
            ->filters([SelectFilter::make('dataset')->options(fn () => app(DatasetRegistry::class)->options())])
            ->recordActions([ViewAction::make()->label('Run'), EditAction::make()]);
    }

    public static function getRelations(): array
    {
        return [SchedulesRelationManager::class, RunsRelationManager::class, AuditHistoryRelationManager::class];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListReports::route('/'),
            'create' => CreateReport::route('/create'),
            'view' => ViewReport::route('/{record}'),
            'edit' => EditReport::route('/{record}/edit'),
        ];
    }
}
