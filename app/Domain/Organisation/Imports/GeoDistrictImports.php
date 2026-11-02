<?php

namespace App\Domain\Organisation\Imports;

use App\Domain\Organisation\Enums\GeoPlaceKind;
use App\Domain\Organisation\Models\GeoPlace;
use Illuminate\Database\Eloquent\Model;

/** Data import DI-1 (Core → Districts): districts by code, each in a state of the list. */
final class GeoDistrictImports extends GeoPlaceImports
{
    public const TYPE = 'geo_districts';

    public static function fields(): array
    {
        return ['code' => ['Code', true], 'name' => ['Name', true], 'state_code' => ['State code', true], 'status' => ['Status', false]];
    }

    public function label(): string
    {
        return 'Districts upload';
    }

    protected function kind(): GeoPlaceKind
    {
        return GeoPlaceKind::District;
    }

    protected function aliases(): array
    {
        return ['district_code' => 'code', 'district_name' => 'name', 'district' => 'name', 'state' => 'state_code'];
    }

    protected function attributes(array $r, array &$context, ?Model $existing, array &$errors): array
    {
        $stateId = $this->stateId($r['state_code'] ?? null, $context, $errors);
        $country = $stateId === null ? null : GeoPlace::query()->whereKey($stateId)->value('country_code');

        return array_filter(['kind' => GeoPlaceKind::District->value, 'name' => $this->text($r['name'] ?? null, 120, 'Name', $errors), 'state_id' => $stateId,
            'country_code' => $country, 'status' => $this->status($r['status'] ?? null, $errors)], fn ($v) => $v !== null);
    }

    public function format(): array
    {
        return [
            'columns' => [
                ['Code', 'Yes', 'Your code for the district (up to 32 characters). A code on file updates that district.', 'BLR-U'],
                ['Name', 'Yes', 'The district name.', 'Bengaluru Urban'],
                ['State code', 'Yes', 'Code of its state (Organisation → Places, or the States upload).', 'KA'],
                $this->statusNote(),
            ],
            'notes' => [
                'Rows are matched on Code (any case) among districts. A new code adds a district; a code on file updates it; a row whose values are already on file is skipped.',
                'The state must be on the list first (States upload). A district takes its state\'s country.',
            ],
        ];
    }

    protected function examples(): array
    {
        $state = self::firstCode(GeoPlaceKind::State, 'KA');

        return [['BLR-U', 'Bengaluru Urban', $state, 'active'], ['MYS', 'Mysuru', $state, 'active']];
    }
}
