<?php

namespace App\Filament\Resources\MissionStatements;

use App\Domain\Engagement\Models\MissionStatement;
use App\Filament\Resources\MissionStatements\Pages\CreateMissionStatement;
use App\Filament\Resources\MissionStatements\Pages\EditMissionStatement;
use App\Filament\Resources\MissionStatements\Pages\ListMissionStatements;
use App\Filament\Support\AuditReasonField;
use App\Filament\Support\RuleConditionsSchema;
use App\Filament\Support\TenantUnique;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
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
use UnitEnum;

/**
 * HROne setup parity HP-9b (Engage → Mission statement): HROne's steps (Basic details · Advance details ·
 * Applicability · Review) as sections of one form. The statement an employee sees on Home is the most specific one
 * whose audience includes them (MissionStatements::forEmployee).
 */
class MissionStatementResource extends Resource
{
    protected static ?string $model = MissionStatement::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedFlag;

    protected static string|UnitEnum|null $navigationGroup = 'Engagement';

    protected static ?string $navigationLabel = 'Mission & values';

    protected static ?string $modelLabel = 'mission statement';

    protected static ?int $navigationSort = 60;

    /** Icons offered for a core value (heroicons already used across PeopleOS). */
    public const ICONS = [
        'heroicon-o-star' => 'Star', 'heroicon-o-heart' => 'Heart', 'heroicon-o-light-bulb' => 'Light bulb', 'heroicon-o-users' => 'People',
        'heroicon-o-shield-check' => 'Shield', 'heroicon-o-rocket-launch' => 'Rocket', 'heroicon-o-hand-thumb-up' => 'Thumbs up', 'heroicon-o-sparkles' => 'Sparkles',
        'heroicon-o-trophy' => 'Trophy', 'heroicon-o-globe-alt' => 'Globe', 'heroicon-o-academic-cap' => 'Learning', 'heroicon-o-scale' => 'Fairness',
    ];

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Basic details')->columns(2)->schema([
                TextInput::make('name')->label('Policy name')->required()->maxLength(120),
                TextInput::make('code')->required()->maxLength(40)->alphaDash()
                    ->unique(ignoreRecord: true, modifyRuleUsing: TenantUnique::rule()),
                Textarea::make('mission')->rows(3)->maxLength(2000)->columnSpanFull(),
                Textarea::make('vision')->rows(3)->maxLength(2000)->columnSpanFull(),
                Textarea::make('purpose')->rows(3)->maxLength(2000)->columnSpanFull(),
            ]),
            Section::make('Advance details')->description('The organisation’s core values, and how the statement is shown.')->schema([
                Repeater::make('core_values')->label('Core values')->defaultItems(0)->reorderable()->columns(3)->schema([
                    TextInput::make('title')->required()->maxLength(80),
                    Select::make('icon')->options(self::ICONS)->default('heroicon-o-star')->native(false),
                    TextInput::make('description')->maxLength(300),
                ])->itemLabel(fn (array $state) => $state['title'] ?? null),
                Toggle::make('show_on_home')->label('Show on employees’ Home')->default(true),
            ]),
            Section::make('Applicability')->description('Who sees this statement. Empty = everyone; when several apply, the most specific one is shown.')->columns(2)->schema([
                RuleConditionsSchema::repeater()->statePath('audience')->label('Applies to')->defaultItems(0)->minItems(0),
                DatePicker::make('effective_from')->label('In force from')->native(false)->helperText('Blank = at once.'),
                Select::make('status')->options(MissionStatement::STATUSES)->default('active')->required()->native(false),
            ]),
            AuditReasonField::make()->visibleOn('edit'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->searchable()->sortable()->description(fn (MissionStatement $record) => $record->code),
                TextColumn::make('mission')->limit(80)->placeholder('—')->wrap(),
                TextColumn::make('core_values')->label('Core values')->state(fn (MissionStatement $record) => count((array) $record->core_values))->placeholder('0'),
                TextColumn::make('audience')->label('Applies to')->state(fn (MissionStatement $record) => empty($record->audience) ? 'Everyone' : count($record->audience).' condition(s)'),
                IconColumn::make('show_on_home')->label('On Home')->boolean(),
                TextColumn::make('effective_from')->label('From')->date()->placeholder('At once'),
                TextColumn::make('status')->badge()->color(fn (string $state) => $state === 'active' ? 'success' : 'gray')->formatStateUsing(fn (string $state) => MissionStatement::STATUSES[$state] ?? $state),
            ])
            ->filters([
                SelectFilter::make('policy')->label('Policy name')->attribute('id')->options(fn () => MissionStatement::query()->orderBy('name')->pluck('name', 'id')->all()),
                SelectFilter::make('status')->options(MissionStatement::STATUSES),
            ])
            ->defaultSort('name')
            ->emptyStateHeading('No mission statement')
            ->emptyStateDescription('Add the organisation’s mission, vision and core values; employees see them on Home.')
            ->recordActions([EditAction::make()]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListMissionStatements::route('/'),
            'create' => CreateMissionStatement::route('/create'),
            'edit' => EditMissionStatement::route('/{record}/edit'),
        ];
    }
}
