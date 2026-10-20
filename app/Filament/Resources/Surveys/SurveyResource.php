<?php

namespace App\Filament\Resources\Surveys;

use App\Domain\Engagement\Models\Survey;
use App\Domain\Engagement\Models\SurveyVersion;
use App\Filament\RelationManagers\AuditHistoryRelationManager;
use App\Filament\Resources\Surveys\Pages\CreateSurvey;
use App\Filament\Resources\Surveys\Pages\EditSurvey;
use App\Filament\Resources\Surveys\Pages\ListSurveys;
use App\Filament\Resources\Surveys\RelationManagers\VersionsRelationManager;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use UnitEnum;

/**
 * Phase 13: the survey catalogue. One canonical survey model for every type; content, audience,
 * privacy and dates live on versions (VersionsRelationManager), each prepared, approved by a second
 * person, published, opened, closed and archived. Responses are never listed here.
 */
class SurveyResource extends Resource
{
    protected static ?string $model = Survey::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChatBubbleBottomCenterText;

    protected static string|UnitEnum|null $navigationGroup = 'Engagement';

    protected static ?string $navigationLabel = 'Surveys';

    protected static ?int $navigationSort = 1;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Survey')->columns(2)->schema([
                TextInput::make('code')->required()->maxLength(32)->disabledOn('edit'),
                TextInput::make('name')->required()->maxLength(255),
                Select::make('survey_type')->options(config('peopleos.engagement.survey_types'))->default('engagement')->required()->disabledOn('edit'),
                Select::make('category')->options(config('peopleos.engagement.categories'))->default('engagement')->required(),
                Textarea::make('description')->rows(2)->columnSpanFull(),
            ]),
            Section::make('First version')->columns(2)->visibleOn('create')->schema([
                Select::make('anonymity_mode')->options(config('peopleos.engagement.anonymity_modes'))->default('anonymous')->required()
                    ->helperText('Anonymous: nobody can ever link answers to a person. This is fixed per version.'),
                DateTimePicker::make('closes_at')->native(false)->required()->default(now()->addWeeks(2)),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with('versions'))
            ->columns([
                TextColumn::make('code')->searchable()->sortable(),
                TextColumn::make('name')->searchable()->wrap(),
                TextColumn::make('survey_type')->label('Type')->badge()->color('gray')->formatStateUsing(fn (string $state) => config("peopleos.engagement.survey_types.{$state}", $state)),
                TextColumn::make('latest')->label('Latest version')->state(fn (Survey $record) => ($v = $record->versions->first()) ? 'v'.$v->version.' · '.config("peopleos.engagement.statuses.{$v->status}", $v->status) : '—')->badge()
                    ->color(fn (Survey $record) => self::statusColor($record->versions->first()?->status)),
                TextColumn::make('mode')->label('Responses')->state(fn (Survey $record) => ucfirst((string) $record->versions->first()?->anonymity_mode)),
                TextColumn::make('closes')->label('Closes')->state(fn (Survey $record) => $record->versions->first()?->closes_at?->toDateString() ?? '—'),
            ])
            ->defaultSort('id', 'desc')
            ->filters([SelectFilter::make('survey_type')->options(config('peopleos.engagement.survey_types'))])
            ->recordActions([EditAction::make()->label('Manage')]);
    }

    public static function statusColor(?string $status): string
    {
        return match ($status) {
            'open' => 'success', 'scheduled', 'approved' => 'info', 'in_review' => 'warning', 'closed' => 'primary', default => 'gray',
        };
    }

    public static function getRelations(): array
    {
        return [VersionsRelationManager::class, AuditHistoryRelationManager::class];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListSurveys::route('/'),
            'create' => CreateSurvey::route('/create'),
            'edit' => EditSurvey::route('/{record}/edit'),
        ];
    }

    /** @return class-string */
    public static function versionModel(): string
    {
        return SurveyVersion::class;
    }
}
