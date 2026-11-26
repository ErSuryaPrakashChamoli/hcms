<?php

namespace App\Filament\Resources\Locations\Schemas;

use App\Domain\Attendance\Services\CountryRegions;
use App\Domain\Configuration\Applicability\ApplicabilityMode;
use App\Domain\Configuration\Applicability\EntityApplicability;
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
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Model;

class LocationForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Details')
                    ->columns(2)
                    ->schema([
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
                // The company is chosen in "Applies to" only: an establishment or parent branch picked here fixes it (they
                // are labelled with their company and set "Applies to" to it); editing keeps the location's own company.
                Section::make('Links')
                    ->description('Optional: set them now or later. In a company with more than one establishment, a location without one makes people there take the head office\'s for PF, ESI, PT and LWF.')
                    ->columns(2)
                    ->schema([
                        Select::make('establishment_id')->label('Establishment')->searchable()->placeholder('None')
                            ->options(fn (?Location $record) => self::labelled(Establishment::query()
                                ->when($record?->exists, fn ($q) => $q->where('company_id', $record->company_id))
                                ->orderBy('name')->get(['id', 'name', 'company_id'])))
                            ->helperText('The establishment whose registrations (PF, ESI, PT, LWF) people here use. The location belongs to its company.')
                            ->live()
                            ->afterStateUpdated(fn (mixed $state, Set $set, string $operation) => $operation === 'create' && filled($state)
                                ? self::appliesToCompanyOf(Establishment::query()->whereKey((int) $state)->value('company_id'), $set) : null)
                            ->rules([fn (Get $get, ?Location $record): Closure => function (string $attribute, mixed $value, Closure $fail) use ($get, $record): void {
                                $probe = $record?->exists ? clone $record : new Location;
                                $probe->fill(['company_id' => self::companyOf($record, $value, $get('parent_location_id')), 'establishment_id' => blank($value) ? null : (int) $value]);
                                try {
                                    app(LocationHierarchy::class)->assertEstablishment($probe);
                                } catch (InvalidHierarchyException $e) {
                                    $fail($e->getMessage());
                                }
                            }]),
                        Select::make('parent_location_id')->label('Parent branch')->placeholder('None (a branch)')->searchable()
                            ->options(fn (Get $get, ?Location $record) => self::labelled(Location::query()
                                ->when(self::companyOf($record, $get('establishment_id'), null), fn ($q, $company) => $q->where('company_id', $company))
                                ->when($record?->exists, fn ($q) => $q->whereKeyNot($record->getKey()))
                                ->orderBy('name')->get(['id', 'name', 'company_id'])))
                            ->helperText('Makes this a sub branch of a branch in the same company.')
                            ->live()
                            ->afterStateUpdated(fn (mixed $state, Get $get, Set $set, string $operation) => $operation === 'create' && filled($state) && blank($get('establishment_id'))
                                ? self::appliesToCompanyOf(Location::query()->whereKey((int) $state)->value('company_id'), $set) : null)
                            ->rules([fn (Get $get, ?Location $record): Closure => function (string $attribute, mixed $value, Closure $fail) use ($get, $record): void {
                                if (filled($value) && ($problem = self::hierarchyProblem($record, self::companyOf($record, $get('establishment_id'), $value), (int) $value)) !== null) {
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

    /**
     * The location's company while the form is filled in: its own when editing (only "Applies to…" moves it); when
     * creating, its establishment's company, else its parent branch's (else "Applies to" decides, after this step).
     */
    private static function companyOf(?Location $record, mixed $establishmentId, mixed $parentId): ?int
    {
        if ($record?->exists) {
            return (int) $record->company_id;
        }
        $company = filled($establishmentId) ? Establishment::query()->whereKey((int) $establishmentId)->value('company_id') : null;
        $company ??= filled($parentId) ? Location::query()->whereKey((int) $parentId)->value('company_id') : null;

        return $company === null ? null : (int) $company;
    }

    /** A new location sitting in a company's establishment or branch applies to that company: "Applies to" says so. */
    private static function appliesToCompanyOf(mixed $companyId, Set $set): void
    {
        if ($companyId === null) {
            return;
        }
        $set('entity_applicability.mode', ApplicabilityMode::Single->value);
        $set('entity_applicability.company', (int) $companyId);
    }

    /**
     * Picker options named with their company when the organisation has several, so the right one is picked without a
     * separate company field.
     *
     * @param  iterable<Model>  $records
     * @return array<int, string>
     */
    private static function labelled(iterable $records): array
    {
        $names = app(EntityApplicability::class)->companyNames();
        $options = [];
        foreach ($records as $record) {
            $company = $names[(int) $record->getAttribute('company_id')] ?? null;
            $options[(int) $record->getKey()] = (string) $record->getAttribute('name').(count($names) > 1 && $company !== null ? " · {$company}" : '');
        }

        return $options;
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
