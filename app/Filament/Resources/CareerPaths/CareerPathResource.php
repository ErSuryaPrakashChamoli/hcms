<?php

namespace App\Filament\Resources\CareerPaths;

use App\Domain\Career\Models\CareerTrack;
use App\Domain\Career\Services\CareerArchitecture;
use App\Domain\Organisation\Enums\ActiveStatus;
use App\Domain\Organisation\Models\BusinessUnit;
use App\Domain\Organisation\Models\JobFamily;
use App\Domain\Performance\Models\CareerPath;
use App\Filament\RelationManagers\AuditHistoryRelationManager;
use App\Filament\Resources\CareerPaths\Pages\CreateCareerPath;
use App\Filament\Resources\CareerPaths\Pages\EditCareerPath;
use App\Filament\Resources\CareerPaths\Pages\ListCareerPaths;
use App\Filament\Resources\CareerPaths\RelationManagers\StepsRelationManager;
use App\Filament\Support\AuditReasonField;
use App\Filament\Support\TalentActions;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use UnitEnum;

/** Career ladders (§36). Phase 9: tracks, organisation scope, effective dates and immutable published versions. */
class CareerPathResource extends Resource
{
    protected static ?string $model = CareerPath::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowTrendingUp;

    protected static string|UnitEnum|null $navigationGroup = 'Talent';

    protected static ?string $navigationLabel = 'Career paths';

    protected static ?int $navigationSort = 20;

    public static function form(Schema $schema): Schema
    {
        return $schema->columns(2)->components([
            TextInput::make('name')->required()->maxLength(255),
            TextInput::make('code')->required()->maxLength(32)->alphaDash()->disabled(fn (string $operation) => $operation === 'edit')->dehydrated(),
            Select::make('job_family_id')->label('Job family')->options(fn () => JobFamily::query()->orderBy('name')->pluck('name', 'id')->all())->placeholder('Any'),
            Select::make('status')->options(ActiveStatus::class)->default(ActiveStatus::Active)->required(),
            Select::make('career_track_id')->label('Career track')->options(fn () => CareerTrack::query()->orderBy('name')->pluck('name', 'id')->all())->placeholder('None'),
            Select::make('business_unit_id')->label('Business unit')->options(fn () => BusinessUnit::query()->orderBy('name')->pluck('name', 'id')->all())->placeholder('Any'),
            Select::make('organisation_node_id')->label('Organisation unit')->options(fn () => TalentActions::nodeOptions())->searchable()->placeholder('Any'),
            DatePicker::make('effective_from')->native(false),
            DatePicker::make('effective_to')->native(false)->afterOrEqual('effective_from'),
            Textarea::make('description')->rows(2)->columnSpanFull(),
            AuditReasonField::make()->visibleOn('edit'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->searchable()->sortable(),
                TextColumn::make('code'),
                TextColumn::make('jobFamily.name')->label('Job family')->placeholder('Any'),
                TextColumn::make('ladder')->label('Ladder')->state(fn (CareerPath $record) => $record->steps()->with('designation')->get()->pluck('designation.name')->implode(' → '))->wrap(),
                TextColumn::make('track.name')->label('Track')->placeholder('—'),
                TextColumn::make('versions_max_version')->label('Published')->max('versions', 'version')->prefix('v')->placeholder('draft'),
                TextColumn::make('status')->badge(),
            ])
            ->recordActions([
                EditAction::make(),
                Action::make('publish')->label('Publish version')->icon(Heroicon::OutlinedRocketLaunch)->color('primary')
                    ->visible(fn () => auth()->user()->can('career.manage'))
                    ->schema([DatePicker::make('effective_from')->native(false)->default(now())])
                    ->action(fn (CareerPath $record, array $data) => TalentActions::run(fn () => app(CareerArchitecture::class)->publishPath($record, $data['effective_from'] ?? null, auth()->user()), fn ($v) => "Version {$v->version} published")),
            ]);
    }

    public static function getRelations(): array
    {
        return [StepsRelationManager::class, AuditHistoryRelationManager::class];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListCareerPaths::route('/'),
            'create' => CreateCareerPath::route('/create'),
            'edit' => EditCareerPath::route('/{record}/edit'),
        ];
    }
}
