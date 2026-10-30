<?php

namespace App\Filament\Resources\Dashboards;

use App\Domain\Analytics\Models\Dashboard;
use App\Domain\Analytics\Models\Report;
use App\Domain\Analytics\Services\AnalyticsDefaults;
use App\Domain\Analytics\Services\DashboardDeliveries;
use App\Domain\Analytics\Services\Dashboards;
use App\Domain\Analytics\Services\DashboardViews;
use App\Domain\Identity\Models\Role;
use App\Domain\Identity\Services\AccessScopeGroups;
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
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use RuntimeException;
use UnitEnum;

/**
 * Dashboard builder (§85): role-specific boards made of KPI, trend, chart, table, leaderboard and alert widgets.
 *
 * HROne parity CA-10 (Setup → Access rights → CXO access): a board can be a shared view — added from a preset, limited to
 * part of the organisation, shared with named people besides roles, e-mailed as a link, with a payroll refresh day; the
 * list clones and inactivates views. None of it grants a figure: viewers keep their own rights and access scope.
 */
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
                Select::make('status')->options(Dashboard::STATUSES)->default('active')->required(),
                Textarea::make('description')->rows(2)->columnSpanFull(),
            ]),
            // HROne parity CA-10 (CXO access): the view's slice, its named viewers and the payroll refresh day.
            Section::make('Shared view')->description('Who else the board is listed for, the part of the organisation it shows, and when its payroll figures move. Every figure still follows each viewer\'s own rights and data access: a view only ever narrows it.')->columns(2)->schema([
                Select::make('preset')->label('Made from the preset')->options(fn () => array_map(fn ($p) => $p['label'], AnalyticsDefaults::PRESETS))->disabled()->dehydrated(false)
                    ->visible(fn (?Dashboard $record) => filled($record?->getAttributes()['preset'] ?? null)),
                Select::make('applicability_dimension')->label('Applicable on')->live()
                    ->options(fn () => array_intersect_key(AccessScopeGroups::LABELS, AccessScopeGroups::UNITS))->placeholder('Whole organisation (Enterprise)')
                    ->disabled(fn (?Dashboard $record) => in_array($record?->getAttributes()['preset'] ?? null, Dashboards::ENTERPRISE_ONLY, true))
                    ->helperText(fn (?Dashboard $record) => in_array($record?->getAttributes()['preset'] ?? null, Dashboards::ENTERPRISE_ONLY, true) ? 'This view covers the whole organisation only.' : 'The view shows only this part of the organisation, within each viewer\'s own data access.'),
                Select::make('applicability_ids')->label('Values')->multiple()->searchable()
                    ->options(fn (Get $get) => filled($get('applicability_dimension')) ? AccessScopeGroups::unitOptions((string) $get('applicability_dimension')) : [])
                    ->visible(fn (Get $get) => filled($get('applicability_dimension')))->required(fn (Get $get) => filled($get('applicability_dimension'))),
                Select::make('viewer_user_ids')->label('Share view with (named people)')->multiple()->searchable()->options(fn () => AccessScopeGroups::userOptions())
                    ->helperText('Listed for them besides the roles above. They still need the right to open dashboards and the rights behind each figure.')->columnSpanFull(),
                TextInput::make('refresh_day')->label('Payroll figures move on day … of each month')->numeric()->minValue(1)->maxValue(28)
                    ->helperText('Blank: live (the latest finalized payroll at once). With a day, payroll figures stay on the payroll finalized before that day until the next one.'),
            ]),
            Section::make('E-mail')->description('A notice with the view\'s link, never figures or attachments: each person sees the numbers by opening it under their own access.')->columns(2)->schema([
                Select::make('email_user_ids')->label('Recipients')->multiple()->searchable()->options(fn () => AccessScopeGroups::userOptions())->columnSpanFull()
                    ->helperText('People who cannot open the view are skipped.'),
                Select::make('email_frequency')->label('Send')->options(Dashboard::EMAIL_FREQUENCIES)->placeholder('Never')->live(),
                Select::make('email_day_of_week')->label('On')->options(DashboardViews::DAYS_OF_WEEK)->default(1)->visible(fn (Get $get) => $get('email_frequency') === 'weekly'),
                Select::make('email_day_of_month')->label('On day')->options(array_combine(range(1, 28), range(1, 28)))->default(1)->visible(fn (Get $get) => $get('email_frequency') === 'monthly'),
                TextInput::make('email_time')->label('At (24-hour)')->default('07:00')->regex('/^([01]\d|2[0-3]):[0-5]\d$/')->visible(fn (Get $get) => filled($get('email_frequency'))),
            ]),
            Section::make('Widgets')->schema([
                Repeater::make('widgets')->hiddenLabel()->columns(4)->reorderable()->default([])->schema([
                    Select::make('type')->options(config('peopleos.analytics.widget_types'))->default('kpi')->required()->live(),
                    TextInput::make('title')->required()->maxLength(64),
                    Select::make('metric')->options(config('peopleos.analytics.metrics'))->visible(fn (Get $get) => in_array($get('type'), ['kpi', 'trend'], true))->required(fn (Get $get) => in_array($get('type'), ['kpi', 'trend'], true))
                        ->options(fn (Get $get) => $get('type') === 'trend' ? ['headcount' => 'Headcount', 'joiners' => 'Joiners', 'exits' => 'Exits', 'people_cost' => 'People cost', 'absenteeism' => 'Absenteeism %'] : config('peopleos.analytics.metrics')),
                    Select::make('report_id')->label('Report')->options(fn () => Report::query()->where('is_shared', true)->orderBy('name')->pluck('name', 'id')->all())->visible(fn (Get $get) => in_array($get('type'), ['chart', 'table', 'leaderboard'], true))->required(fn (Get $get) => in_array($get('type'), ['chart', 'table', 'leaderboard'], true)),
                    Select::make('survey_kind')->label('Surveys')->options(config('peopleos.analytics.survey_widget_kinds'))->default('survey')->visible(fn (Get $get) => $get('type') === 'survey'),
                    Select::make('size')->options(config('peopleos.analytics.widget_sizes'))->default('1')->required(),
                    TextInput::make('limit')->numeric()->minValue(1)->placeholder('5')->visible(fn (Get $get) => in_array($get('type'), ['leaderboard', 'table'], true)),
                    Toggle::make('suppress')->label('Hide groups below the privacy threshold')->visible(fn (Get $get) => in_array($get('type'), ['chart', 'table'], true)),
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
                TextColumn::make('preset')->label('View')->placeholder('—')->formatStateUsing(fn (?string $state) => AnalyticsDefaults::PRESETS[$state]['label'] ?? $state),
                TextColumn::make('audience')->label('Audience')->state(function (Dashboard $record) {
                    $named = count($record->namedViewers() ?? []);
                    $roles = empty($record->role_ids) ? null : Role::query()->whereIn('id', $record->role_ids)->pluck('name')->implode(', ');

                    return match (true) {
                        $roles === null && $record->namedViewers() === null => 'Everyone',
                        $roles === null => "Named people ({$named})",
                        $named > 0 => "{$roles} + {$named} named",
                        default => $roles,
                    };
                }),
                TextColumn::make('applies_to')->label('Applicable on')->state(fn (Dashboard $record) => app(DashboardViews::class)->describe($record))->wrap(),
                TextColumn::make('widgets')->label('Widgets')->state(fn (Dashboard $record) => count($record->widgets ?? [])),
                TextColumn::make('email_frequency')->label('E-mail')->placeholder('—')->formatStateUsing(fn (?string $state) => Dashboard::EMAIL_FREQUENCIES[$state] ?? $state),
                IconColumn::make('is_default')->label('Default')->boolean(),
                TextColumn::make('status')->badge()->color(fn (string $state) => $state === 'active' ? 'success' : 'gray'),
            ])
            ->defaultSort('sort_order')
            ->recordActions([
                Action::make('open')->label('Open')->icon('heroicon-m-eye')->url(fn (Dashboard $record) => DashboardViewer::getUrl(['dashboard' => $record->slug])),
                EditAction::make(),
                // HROne parity CA-10: the view list's Clone and Inactivate, and sending the e-mail notice now.
                Action::make('clone')->label('Clone')->icon('heroicon-m-document-duplicate')->color('gray')->requiresConfirmation()
                    ->modalDescription('Makes a draft copy (widgets, audience, organisation filter, e-mail and refresh day). Nobody but analytics managers sees it until it is switched on.')
                    ->action(function (Dashboard $record) {
                        $copy = app(DashboardViews::class)->clone($record, auth()->user());
                        Notification::make()->success()->title("Copied as \"{$copy->name}\" (draft)")->send();
                    }),
                Action::make('inactivate')->label('Inactivate')->icon('heroicon-m-pause-circle')->color('gray')->requiresConfirmation()
                    ->visible(fn (Dashboard $record) => $record->status === 'active' && ! $record->is_default)
                    ->action(fn (Dashboard $record) => self::changeStatus($record, 'inactive')),
                Action::make('activate')->label('Switch on')->icon('heroicon-m-play-circle')->color('gray')->requiresConfirmation()
                    ->visible(fn (Dashboard $record) => $record->status !== 'active')
                    ->action(fn (Dashboard $record) => self::changeStatus($record, 'active')),
                Action::make('sendNow')->label('Send now')->icon('heroicon-m-paper-airplane')->color('gray')->requiresConfirmation()
                    ->modalDescription('Sends the view\'s link to its recipients now (never figures). People who cannot open it are skipped.')
                    ->visible(fn (Dashboard $record) => $record->status === 'active' && ($record->emailRecipients() ?? []) !== [])
                    ->action(function (Dashboard $record) {
                        $result = app(DashboardDeliveries::class)->deliver($record, auth()->user());
                        Notification::make()->success()->title(count($result['sent']).' notified'.($result['skipped'] === [] ? '' : ', '.count($result['skipped']).' skipped (cannot open the view)'))->send();
                    }),
            ]);
    }

    private static function changeStatus(Dashboard $record, string $status): void
    {
        try {
            app(DashboardViews::class)->setStatus($record, $status, auth()->user());
            Notification::make()->success()->title($status === 'active' ? 'Switched on' : 'Inactivated')->send();
        } catch (RuntimeException $e) {
            Notification::make()->danger()->title($e->getMessage())->send();
        }
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
