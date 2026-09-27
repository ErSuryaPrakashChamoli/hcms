<?php

namespace App\Filament\Resources\Dashboards;

use App\Domain\Analytics\Models\Dashboard;
use App\Domain\Analytics\Models\Report;
use App\Domain\Identity\Models\Role;
use App\Filament\Pages\DashboardViewer;
use App\Filament\RelationManagers\AuditHistoryRelationManager;
use App\Filament\Resources\Dashboards\Pages\CreateDashboard;
use App\Filament\Resources\Dashboards\Pages\EditDashboard;
use App\Filament\Resources\Dashboards\Pages\ListDashboards;
use App\Filament\Support\AuditReasonField;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use UnitEnum;

/** Dashboard builder (§85): role-specific boards made of KPI, trend, chart, table, leaderboard and alert widgets. */
class DashboardResource extends Resource
{
    protected static ?string $model = Dashboard::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedSquares2x2;

    protected static string|UnitEnum|null $navigationGroup = 'Analytics';

    protected static ?string $navigationLabel = 'Dashboard builder';

    protected static ?int $navigationSort = 30;

    public static function canAccess(): bool
    {
        return auth()->user()?->can('analytics.manage') ?? false;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Dashboard')->columns(3)->schema([
                TextInput::make('name')->required()->maxLength(255),
                TextInput::make('slug')->maxLength(64)->helperText('Leave empty to derive from the name'),
                Select::make('role_ids')->label('Visible to roles')->multiple()->placeholder('Everyone with analytics.view')->options(fn () => Role::query()->orderBy('name')->pluck('name', 'id')->all()),
                Toggle::make('is_default')->label('Default dashboard'),
                TextInput::make('sort_order')->numeric()->default(0),
                Select::make('status')->options(['active' => 'Active', 'draft' => 'Draft'])->default('active')->required(),
                Textarea::make('description')->rows(2)->columnSpanFull(),
            ]),
            Section::make('Widgets')->schema([
                Repeater::make('widgets')->hiddenLabel()->columns(4)->reorderable()->default([])->schema([
                    Select::make('type')->options(config('peopleos.analytics.widget_types'))->default('kpi')->required()->live(),
                    TextInput::make('title')->required()->maxLength(64),
                    Select::make('metric')->options(config('peopleos.analytics.metrics'))->visible(fn (Get $get) => in_array($get('type'), ['kpi', 'trend'], true))->required(fn (Get $get) => in_array($get('type'), ['kpi', 'trend'], true))
                        ->options(fn (Get $get) => $get('type') === 'trend' ? ['headcount' => 'Headcount', 'joiners' => 'Joiners', 'exits' => 'Exits', 'people_cost' => 'People cost', 'absenteeism' => 'Absenteeism %'] : config('peopleos.analytics.metrics')),
                    Select::make('report_id')->label('Report')->options(fn () => Report::query()->where('is_shared', true)->orderBy('name')->pluck('name', 'id')->all())->visible(fn (Get $get) => in_array($get('type'), ['chart', 'table', 'leaderboard'], true))->required(fn (Get $get) => in_array($get('type'), ['chart', 'table', 'leaderboard'], true)),
                    Select::make('size')->options(config('peopleos.analytics.widget_sizes'))->default('1')->required(),
                    TextInput::make('limit')->numeric()->minValue(1)->placeholder('5')->visible(fn (Get $get) => in_array($get('type'), ['leaderboard', 'table'], true)),
                ]),
            ]),
            AuditReasonField::make()->visibleOn('edit'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->searchable()->sortable(),
                TextColumn::make('slug'),
                TextColumn::make('audience')->label('Audience')->state(fn (Dashboard $record) => empty($record->role_ids) ? 'Everyone' : Role::query()->whereIn('id', $record->role_ids)->pluck('name')->implode(', ')),
                TextColumn::make('widgets')->label('Widgets')->state(fn (Dashboard $record) => count($record->widgets ?? [])),
                IconColumn::make('is_default')->label('Default')->boolean(),
                TextColumn::make('status')->badge()->color(fn (string $state) => $state === 'active' ? 'success' : 'gray'),
            ])
            ->defaultSort('sort_order')
            ->recordActions([
                Action::make('open')->label('Open')->icon('heroicon-m-eye')->url(fn (Dashboard $record) => DashboardViewer::getUrl(['dashboard' => $record->slug])),
                EditAction::make(),
            ]);
    }

    public static function getRelations(): array
    {
        return [AuditHistoryRelationManager::class];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListDashboards::route('/'),
            'create' => CreateDashboard::route('/create'),
            'edit' => EditDashboard::route('/{record}/edit'),
        ];
    }
}
