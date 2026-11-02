<?php

namespace App\Filament\Resources\GeoPlaces;

use App\Domain\Attendance\Services\CountryRegions;
use App\Domain\Organisation\Enums\ActiveStatus;
use App\Domain\Organisation\Enums\GeoPlaceKind;
use App\Domain\Organisation\Models\GeoPlace;
use App\Filament\Resources\GeoPlaces\Pages\ManageGeoPlaces;
use App\Filament\Support\AuditReasonField;
use App\Filament\Support\TenantUnique;
use App\Support\Geography\Countries;
use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Validation\Rules\Unique;
use UnitEnum;

/**
 * Data import DI-1 (Core → States, Districts, Cities): the organisation's own list of places on one screen. A state may
 * name its region on the platform's ISO list; a district sits in a state; a city in a state and optionally a district.
 */
class GeoPlaceResource extends Resource
{
    protected static ?string $model = GeoPlace::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedMap;

    protected static string|UnitEnum|null $navigationGroup = 'Organisation';

    protected static ?string $navigationLabel = 'Places';

    protected static ?int $navigationSort = 24;

    protected static ?string $recordTitleAttribute = 'name';

    protected static ?string $modelLabel = 'place';

    protected static ?string $pluralModelLabel = 'places';

    public static function form(Schema $schema): Schema
    {
        $is = fn (GeoPlaceKind ...$kinds) => fn (Get $get) => in_array($get('kind') instanceof GeoPlaceKind ? $get('kind')->value : $get('kind'), array_map(fn (GeoPlaceKind $k) => $k->value, $kinds), true);

        return $schema->components([
            Section::make('Place')->columns(2)->schema([
                Select::make('kind')->options(GeoPlaceKind::class)->default(GeoPlaceKind::State)->required()->live()->disabledOn('edit')
                    ->afterStateUpdated(function (Set $set) {
                        $set('state_id', null);
                        $set('district_id', null);
                    }),
                TextInput::make('code')->required()->maxLength(32)->alphaDash()
                    ->unique(ignoreRecord: true, modifyRuleUsing: fn (Unique $rule, Get $get) => TenantUnique::rule()($rule)->where('kind', $get('kind') instanceof GeoPlaceKind ? $get('kind')->value : $get('kind')))
                    ->helperText('Unique among places of the same kind; uploads use it.'),
                TextInput::make('name')->required()->maxLength(120),
                Select::make('status')->options(ActiveStatus::class)->default(ActiveStatus::Active)->required(),
            ]),
            Section::make('Where it is')->columns(2)->schema([
                Select::make('country_code')->label('Country')->options(fn () => Countries::all())->searchable()->default('IN')->required()->live()
                    ->visible($is(GeoPlaceKind::State)),
                Select::make('region_code')->label('ISO region')->placeholder('—')->searchable()
                    ->options(fn (Get $get) => app(CountryRegions::class)->active($get('country_code')))
                    ->helperText('The state on the platform\'s ISO 3166-2 list, so holidays and statutory rules can find it.')
                    ->visible($is(GeoPlaceKind::State)),
                Select::make('state_id')->label('State')->required()->searchable()->live()
                    ->options(fn () => GeoPlace::query()->where('kind', GeoPlaceKind::State->value)->orderBy('name')->pluck('name', 'id')->all())
                    ->afterStateUpdated(fn (Set $set) => $set('district_id', null))
                    ->visible($is(GeoPlaceKind::District, GeoPlaceKind::City)),
                Select::make('district_id')->label('District')->placeholder('—')->searchable()
                    ->options(fn (Get $get) => GeoPlace::query()->where('kind', GeoPlaceKind::District->value)->where('state_id', $get('state_id'))->orderBy('name')->pluck('name', 'id')->all())
                    ->visible($is(GeoPlaceKind::City)),
            ]),
            AuditReasonField::make()->visibleOn('edit'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with(['state', 'district']))
            ->columns([
                TextColumn::make('name')->searchable()->sortable(),
                TextColumn::make('code')->searchable()->sortable(),
                TextColumn::make('kind')->badge()->color('gray'),
                TextColumn::make('state.name')->label('State')->placeholder('—'),
                TextColumn::make('district.name')->label('District')->placeholder('—')->toggleable(),
                TextColumn::make('country_code')->label('Country')->toggleable(),
                TextColumn::make('region_code')->label('ISO region')->placeholder('—')->toggleable(),
                TextColumn::make('status')->badge(),
            ])
            ->filters([
                SelectFilter::make('kind')->options(GeoPlaceKind::class),
                SelectFilter::make('state_id')->label('State')->options(fn () => GeoPlace::query()->where('kind', GeoPlaceKind::State->value)->orderBy('name')->pluck('name', 'id')->all()),
                SelectFilter::make('status')->options(ActiveStatus::class),
            ])
            ->defaultSort('name')
            ->recordActions([
                EditAction::make()->using(function (GeoPlace $record, array $data) {
                    $record->withAuditReason(AuditReasonField::extract($data))->update($data);

                    return $record;
                }),
                DeleteAction::make()->hidden(fn (GeoPlace $record) => GeoPlace::query()->where('state_id', $record->getKey())->orWhere('district_id', $record->getKey())->exists()),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => ManageGeoPlaces::route('/')];
    }
}
