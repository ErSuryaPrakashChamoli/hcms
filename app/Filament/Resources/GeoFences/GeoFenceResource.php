<?php

namespace App\Filament\Resources\GeoFences;

use App\Domain\Attendance\Models\GeoFence;
use App\Domain\Attendance\Services\GeoLocations;
use App\Domain\Organisation\Models\Location;
use App\Filament\Resources\Employees\Pages\CreateEmployee;
use App\Filament\Resources\GeoFences\Pages\ManageGeoFences;
use App\Filament\Support\AuditReasonField;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use UnitEnum;

/**
 * HROne parity HP-1: geo work locations. A location for a work location covers everyone posted there; for an
 * employee, only them; for neither, everyone. Check-in policies decide whether being outside is flagged or refused.
 */
class GeoFenceResource extends Resource
{
    protected static ?string $model = GeoFence::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedMapPin;

    protected static string|UnitEnum|null $navigationGroup = 'Attendance';

    protected static ?string $navigationLabel = 'Geo work locations';

    protected static ?string $modelLabel = 'geo work location';

    protected static ?int $navigationSort = 70;

    public static function form(Schema $schema): Schema
    {
        return $schema->columns(2)->components([
            Section::make('Site')->columns(2)->schema([
                TextInput::make('name')->required()->maxLength(255),
                Select::make('status')->options(['active' => 'Active', 'inactive' => 'Inactive'])->default('active')->required()->native(false),
                TextInput::make('latitude')->numeric()->minValue(-90)->maxValue(90)->required(),
                TextInput::make('longitude')->numeric()->minValue(-180)->maxValue(180)->required(),
                Textarea::make('address')->rows(2)->maxLength(500)->columnSpanFull(),
            ]),
            Section::make('Boundary')->columns(2)->schema([
                // HROne setup parity: radius-based or polygon-based locations, optionally valid only for a period.
                Radio::make('shape')->label('Tracking')->options(GeoFence::SHAPES)->default('circle')->inline()->live()->required(),
                TextInput::make('radius_m')->label('Radius')->numeric()->minValue(20)->maxValue(20000)->default(200)->suffix('m')->required()
                    ->visible(fn (Get $get) => $get('shape') !== 'polygon'),
                Repeater::make('polygon')->label('Boundary points (in order around the site)')->columnSpanFull()->columns(2)->minItems(3)->maxItems(50)
                    ->schema([TextInput::make('latitude')->numeric()->minValue(-90)->maxValue(90)->required(), TextInput::make('longitude')->numeric()->minValue(-180)->maxValue(180)->required()])
                    ->visible(fn (Get $get) => $get('shape') === 'polygon')->addActionLabel('Add a point'),
            ]),
            Section::make('When and for whom')->columns(2)->description('Blank work location and employee: it applies to everyone.')->schema([
                DatePicker::make('valid_from')->label('Valid from')->native(false),
                DatePicker::make('valid_until')->label('Valid until')->native(false)->afterOrEqual('valid_from')->helperText('Blank: no end.'),
                Select::make('location_id')->label('For a work location')->options(fn () => Location::query()->orderBy('name')->pluck('name', 'id'))->placeholder('—')->native(false),
                Select::make('employee_id')->label('For one employee')->options(fn () => CreateEmployee::managerOptions())->searchable()->placeholder('—'),
            ]),
            AuditReasonField::make()->visibleOn('edit'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with(['location', 'employee.person']))
            ->columns([
                TextColumn::make('name')->searchable(),
                TextColumn::make('applies')->label('Applies to')->state(fn (GeoFence $r) => $r->employee?->display_name ?? $r->location?->name ?? 'Everyone'),
                TextColumn::make('latitude')->numeric(5),
                TextColumn::make('longitude')->numeric(5),
                TextColumn::make('radius_m')->label('Area')->state(fn (GeoFence $r) => $r->shape === 'polygon' ? count($r->points()).'-point polygon' : $r->radius_m.' m radius'),
                TextColumn::make('valid_until')->label('Valid')->state(fn (GeoFence $r) => $r->valid_from || $r->valid_until ? ($r->valid_from?->format('d M Y') ?? '…').' → '.($r->valid_until?->format('d M Y') ?? '…') : 'Always'),
                TextColumn::make('source')->badge()->color('gray'),
                TextColumn::make('status')->badge()->color(fn (string $state) => $state === 'active' ? 'success' : 'gray'),
            ])
            ->filters([SelectFilter::make('status')->options(['active' => 'Active', 'inactive' => 'Inactive'])])
            ->recordActions([
                EditAction::make()->using(function (GeoFence $record, array $data) {
                    app(GeoLocations::class)->assertPlace($data);
                    $record->withAuditReason(AuditReasonField::extract($data))->update($data);

                    return $record;
                }),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => ManageGeoFences::route('/')];
    }
}
