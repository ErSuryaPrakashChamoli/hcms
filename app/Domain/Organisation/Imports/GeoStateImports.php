<?php

namespace App\Domain\Organisation\Imports;

use App\Domain\Attendance\Services\CountryRegions;
use App\Domain\Organisation\Enums\GeoPlaceKind;
use Illuminate\Database\Eloquent\Model;

/** Data import DI-1 (Core → States): states by code, each optionally tied to its region on the platform's ISO list. */
final class GeoStateImports extends GeoPlaceImports
{
    public const TYPE = 'geo_states';

    public static function fields(): array
    {
        return ['code' => ['Code', true], 'name' => ['Name', true], 'country_code' => ['Country code', false], 'region_code' => ['ISO region code', false], 'status' => ['Status', false]];
    }

    public function label(): string
    {
        return 'States upload';
    }

    protected function kind(): GeoPlaceKind
    {
        return GeoPlaceKind::State;
    }

    protected function aliases(): array
    {
        return ['state_code' => 'code', 'state_name' => 'name', 'state' => 'name', 'country' => 'country_code', 'iso_code' => 'region_code'];
    }

    protected function attributes(array $r, array &$context, ?Model $existing, array &$errors): array
    {
        $country = $this->country($r['country_code'] ?? null, $errors) ?? ($existing === null ? 'IN' : null);
        $region = $this->text($r['region_code'] ?? null, 32, 'ISO region code', $errors);
        if ($region !== null) {
            $matched = app(CountryRegions::class)->match($country ?? $existing?->country_code, $region);
            if ($matched === null) {
                $errors[] = "ISO region code '{$region}' is not an active region of ".($country ?? $existing?->country_code)." on the platform's list.";
            }
            $region = $matched;
        }

        return array_filter(['kind' => GeoPlaceKind::State->value, 'name' => $this->text($r['name'] ?? null, 120, 'Name', $errors), 'country_code' => $country,
            'region_code' => $region, 'status' => $this->status($r['status'] ?? null, $errors)], fn ($v) => $v !== null);
    }

    public function format(): array
    {
        return [
            'columns' => [
                ['Code', 'Yes', 'Your code for the state (up to 32 characters). A code on file updates that state.', 'KA'],
                ['Name', 'Yes', 'The state name.', 'Karnataka'],
                ['Country code', 'No', 'Two-letter ISO country code. Blank for a new state is IN.', 'IN'],
                ['ISO region code', 'No', 'The state on the platform\'s ISO 3166-2 region list (KA or IN-KA; the name works too), so holidays and statutory rules can find it.', 'KA'],
                $this->statusNote(),
            ],
            'notes' => [
                'Rows are matched on Code (any case) among states. A new code adds a state; a code on file updates it; a row whose values are already on file is skipped.',
                'Blank cells keep what is on file. Districts and cities name their state by this code.',
            ],
        ];
    }

    protected function examples(): array
    {
        return [['KA', 'Karnataka', 'IN', 'KA', 'active'], ['MH', 'Maharashtra', 'IN', 'MH', 'active']];
    }
}
