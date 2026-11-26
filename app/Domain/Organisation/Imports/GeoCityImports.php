<?php

namespace App\Domain\Organisation\Imports;

use App\Domain\Organisation\Enums\GeoPlaceKind;
use App\Domain\Organisation\Models\GeoPlace;
use App\Support\Imports\ReferenceGuide;
use Illuminate\Database\Eloquent\Model;

/** Data import DI-1 (Core → Cities): cities by code, each in a state of the list and optionally one of its districts (each by its name or code). */
final class GeoCityImports extends GeoPlaceImports
{
    public const TYPE = 'geo_cities';

    public static function fields(): array
    {
        return ['code' => ['Code', true], 'name' => ['Name', true], 'state_code' => ['State', true], 'district_code' => ['District', false], 'status' => ['Status', false]];
    }

    public function label(): string
    {
        return 'Cities upload';
    }

    protected function kind(): GeoPlaceKind
    {
        return GeoPlaceKind::City;
    }

    protected function aliases(): array
    {
        return ['city_code' => 'code', 'city_name' => 'name', 'city' => 'name', 'state' => 'state_code', 'district' => 'district_code'];
    }

    protected function attributes(array $r, array &$context, ?Model $existing, array &$errors): array
    {
        $stateId = $this->stateId($r['state_code'] ?? null, $context, $errors);
        $districtId = $this->placeId(GeoPlaceKind::District, $r['district_code'] ?? null, $context, 'District', $errors);
        $state = $stateId ?? ($existing !== null ? (int) $existing->state_id : null);
        if ($districtId !== null && $districtId > 0 && $state !== null && $state > 0 && (int) GeoPlace::query()->whereKey($districtId)->value('state_id') !== $state) {
            $errors[] = "District '{$r['district_code']}' is not in the city's state.";
        }
        if ($districtId === null && $stateId !== null && $existing?->district_id !== null && (int) GeoPlace::query()->whereKey($existing->district_id)->value('state_id') !== $stateId) {
            $errors[] = 'The city moves to another state: give its district in that state (District).';
        }
        $country = $stateId === null ? null : GeoPlace::query()->whereKey($stateId)->value('country_code');

        return array_filter(['kind' => GeoPlaceKind::City->value, 'name' => $this->text($r['name'] ?? null, 120, 'Name', $errors), 'state_id' => $stateId, 'district_id' => $districtId,
            'country_code' => $country, 'status' => $this->status($r['status'] ?? null, $errors)], fn ($v) => $v !== null);
    }

    public function format(): array
    {
        return [
            'columns' => [
                ['Code', 'Yes', 'Your code for the city (up to 32 characters). A code on file updates that city.', 'BLR'],
                ['Name', 'Yes', 'The city name.', 'Bengaluru'],
                ['State', 'Yes', ReferenceGuide::name('state', 'Organisation → Places, or the States upload'), 'Karnataka'],
                ['District', 'No', ReferenceGuide::name('district', 'Districts upload', 'It must be in the same state.'), 'Bengaluru Urban'],
                $this->statusNote(),
            ],
            'notes' => [
                'Rows are matched on Code (any case) among cities. A new code adds a city; a code on file updates it; a row whose values are already on file is skipped.',
                'The state (and district) must be on the list first. A blank District keeps the district on file.',
                ReferenceGuide::NOTE,
            ],
        ];
    }

    protected function examples(): array
    {
        $state = self::firstName(GeoPlaceKind::State, 'Karnataka');
        $district = GeoPlace::query()->where('kind', GeoPlaceKind::District->value)->whereIn('state_id', GeoPlace::query()->where('kind', GeoPlaceKind::State->value)->whereRaw('lower(name) = ?', [mb_strtolower($state)])->select('id'))->orderBy('id')->value('name') ?? '';

        return [['BLR', 'Bengaluru', $state, $district, 'active'], ['YLK', 'Yelahanka', $state, $district, 'active']];
    }
}
