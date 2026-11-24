<?php

namespace App\Filament\Resources\Locations\Schemas;

use App\Domain\Attendance\Services\CountryRegions;
use App\Domain\Organisation\Enums\ActiveStatus;
use App\Domain\Organisation\Enums\LocationType;
use App\Domain\Organisation\Exceptions\InvalidHierarchyException;
use App\Domain\Organisation\Models\Establishment;
use App\Domain\Organisation\Models\Location;
use App\Domain\Organisation\Services\LocationHierarchy;
use App\Filament\Support\AuditReasonField;
use App\Filament\Support\CustomFieldsSchema;
use App\Filament\Support\PlacementField;
use Closure;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;

class LocationForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Details')
                    ->columns(2)
                    ->schema([
                        Select::make('company_id')->relationship('company', 'name')->searchable()->preload()->required()
                            ->rules([fn (Get $get, ?Location $record): Closure => function (string $attribute, mixed $value, Closure $fail) use ($get, $record): void {
                                // A branch keeps its sub branches' company (a parent branch is checked on its own field).
                                if ($record?->exists && blank($get('parent_location_id')) && ($problem = self::hierarchyProblem($record, $value, null)) !== null) {
                                    $fail($problem);
                                }
                            }]),
                        TextInput::make('name')->required()->maxLength(255),
                        TextInput::make('code')->required()->maxLength(32)->alphaDash()->helperText('Short unique code.'),
                        Select::make('type')->options(LocationType::class)->default(LocationType::Office)->required(),
                        TextInput::make('address_line_1')->maxLength(255),
                        TextInput::make('address_line_2')->maxLength(255),
                        TextInput::make('city')->maxLength(255),
                        TextInput::make('state_code')->label('State / region code')->maxLength(16)
                            ->datalist(fn (Get $get) => array_map('strval', array_keys(app(CountryRegions::class)->active($get('country_code')))))
                            ->helperText('The code on the platform\'s region list, e.g. MH for Maharashtra (the name works too). It lets the holiday library suggest this location\'s regional holidays.'),
                        TextInput::make('postal_code')->maxLength(16),
                        TextInput::make('country_code')->required()->default('IN')->length(2)->live(onBlur: true),
                        TextInput::make('timezone')->placeholder('Asia/Kolkata')->maxLength(64),
                        Select::make('status')->options(ActiveStatus::class)->default(ActiveStatus::Active)->required(),
                    ]),
                // Data import DI-1: a sub branch sits inside a branch of its company; branches belong to a region and a business region.
                // Master relationships R4: these are the location's optional links, with its establishment.
                Section::make('Links')
                    ->description('Optional: set them now or later. In a company with more than one establishment, a location without one makes people there take the head office\'s for PF, ESI, PT and LWF.')
                    ->columns(2)
                    ->schema([
                        Select::make('establishment_id')->label('Establishment')->searchable()->placeholder('None')
                            ->options(fn (Get $get) => filled($get('company_id')) ? Establishment::query()->where('company_id', $get('company_id'))->orderBy('name')->pluck('name', 'id')->all() : [])
                            ->helperText('The establishment whose registrations (PF, ESI, PT, LWF) people here use.'),
                        Select::make('parent_location_id')->label('Parent branch')->placeholder('None (a branch)')->searchable()
                            ->options(fn (Get $get, ?Location $record) => Location::query()
                                ->when($get('company_id'), fn ($q, $company) => $q->where('company_id', $company))
                                ->when($record?->exists, fn ($q) => $q->whereKeyNot($record->getKey()))
                                ->orderBy('name')->pluck('name', 'id')->all())
                            ->helperText('Makes this a sub branch of a branch in the same company.')
                            ->rules([fn (Get $get, ?Location $record): Closure => function (string $attribute, mixed $value, Closure $fail) use ($get, $record): void {
                                if (filled($value) && ($problem = self::hierarchyProblem($record, $get('company_id'), (int) $value)) !== null) {
                                    $fail($problem);
                                }
                            }]),
                        Select::make('region_id')->relationship('region', 'name')->label('Region')->searchable()->preload(),
                        Select::make('business_region_id')->relationship('businessRegion', 'name')->label('Business region')->searchable()->preload(),
                        PlacementField::make('location'),
                    ]),
                Section::make('Effective dates')
                    ->description('Leave blank for open-ended. History is never overwritten.')
                    ->columns(2)
                    ->schema([
                        DatePicker::make('effective_from')->native(false),
                        DatePicker::make('effective_to')->native(false)->afterOrEqual('effective_from'),
                    ]),
                CustomFieldsSchema::formSection(Location::class),
                AuditReasonField::make()->visibleOn('edit'),
            ]);
    }

    /** Data import DI-1: what the location rules (LocationHierarchy) say about these values, before saving. */
    private static function hierarchyProblem(?Location $record, mixed $companyId, ?int $parentId): ?string
    {
        $probe = $record?->exists ? clone $record : new Location;
        $probe->fill(['company_id' => blank($companyId) ? null : (int) $companyId, 'parent_location_id' => $parentId]);
        try {
            app(LocationHierarchy::class)->assertValid($probe);
        } catch (InvalidHierarchyException $e) {
            return $e->getMessage();
        }

        return null;
    }
}
