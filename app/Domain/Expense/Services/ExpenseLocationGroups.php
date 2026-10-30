<?php

namespace App\Domain\Expense\Services;

use App\Domain\Expense\Exceptions\ExpenseRuleViolation;
use App\Domain\Expense\Models\ExpenseLocationGroup;
use App\Domain\Expense\Models\ExpenseLocationGroupPlace;
use App\Domain\Organisation\Enums\ActiveStatus;
use Illuminate\Support\Facades\DB;

/**
 * HROne setup parity EX-A (Expense → Location group): saves a group with the places it covers — whole states, or cities
 * of a state — and resolves a place to its group. A place belongs to one group; a city is matched before its state (so
 * Tier 1 can hold Mumbai while Tier 2 holds the rest of Maharashtra); a place in no active group has no group, and
 * takes the limits set for every other place.
 */
final class ExpenseLocationGroups
{
    /**
     * @param  array<string, mixed>  $data  name, code, sort_order, status
     * @param  list<array{country_code?: ?string, state_code?: ?string, cities?: list<string>|null}>  $places  one entry per state; no cities = the whole state
     */
    public function save(?ExpenseLocationGroup $group, array $data, array $places, ?string $reason = null): ExpenseLocationGroup
    {
        $name = trim((string) ($data['name'] ?? $group?->name ?? ''));
        if ($name === '' || mb_strlen($name) > 120) {
            throw new ExpenseRuleViolation('Give the location group a name (at most 120 characters).');
        }
        $code = strtoupper(trim((string) ($data['code'] ?? $group?->code ?? '')));
        if (! preg_match('/^[A-Z0-9_-]{1,32}$/', $code)) {
            throw new ExpenseRuleViolation('The code may use letters, numbers, dashes and underscores (at most 32).');
        }
        if (ExpenseLocationGroup::query()->where('code', $code)->when($group, fn ($q) => $q->whereKeyNot($group->id))->exists()) {
            throw new ExpenseRuleViolation("Another location group already uses the code {$code}.");
        }
        $status = $data['status'] ?? $group?->status ?? ActiveStatus::Active;
        $status = $status instanceof ActiveStatus ? $status : (ActiveStatus::tryFrom((string) $status) ?? throw new ExpenseRuleViolation('Choose active or inactive.'));
        $rows = $this->placeRows($places);

        foreach ($rows as $key => $row) {
            $owner = ExpenseLocationGroupPlace::query()->with('group')
                ->where('country_code', $row['country_code'])->where('state_code', $row['state_code'])->where('city_key', ExpenseLocationGroupPlace::key($row['city']))
                ->when($group, fn ($q) => $q->where('expense_location_group_id', '!=', $group->id))->first();
            if ($owner !== null) {
                throw new ExpenseRuleViolation(self::placeLabel($row).' is already in the location group '.($owner->group?->name ?? '#'.$owner->expense_location_group_id).': a place belongs to one group.');
            }
        }

        return DB::transaction(function () use ($group, $name, $code, $status, $data, $rows, $reason) {
            $group ??= new ExpenseLocationGroup;
            $group->fill(['name' => $name, 'code' => $code, 'status' => $status, 'sort_order' => max(0, (int) ($data['sort_order'] ?? $group->sort_order ?? 0))]);
            if (! $group->exists || $group->isDirty()) {
                $group->withAuditReason($reason)->save();
            }
            $existing = $group->places()->get()->keyBy(fn (ExpenseLocationGroupPlace $p) => self::rowKey($p->country_code, $p->state_code, $p->city));
            foreach ($existing->diffKeys($rows) as $place) {
                $place->withAuditReason($reason)->delete();
            }
            foreach (array_diff_key($rows, $existing->all()) as $row) {
                ExpenseLocationGroupPlace::query()->create($row + ['expense_location_group_id' => $group->id]);
            }

            return $group->refresh();
        });
    }

    /**
     * A group's places one entry per state, in the shape save() takes (for the form).
     *
     * @return list<array{country_code: string, state_code: string, cities: list<string>}>
     */
    public function placesFor(ExpenseLocationGroup $group): array
    {
        $out = [];
        foreach ($group->places()->get() as $place) {
            $key = $place->country_code.'|'.$place->state_code;
            $out[$key] ??= ['country_code' => $place->country_code, 'state_code' => $place->state_code, 'cities' => []];
            if ($place->city !== null) {
                $out[$key]['cities'][] = $place->city;
            }
        }

        return array_values($out);
    }

    /**
     * The active location group a place is in: the city's own entry first, else its state's. Without a state, a city is
     * matched only when every group holding a city of that name is the same one.
     */
    public function resolve(?string $city, ?string $state, ?string $country = 'IN'): ?ExpenseLocationGroup
    {
        $country = strtoupper(trim((string) $country)) ?: 'IN';
        $state = trim((string) $state);
        $state = $country === 'IN' ? strtoupper($state) : mb_strtolower($state);
        $cityKey = ExpenseLocationGroupPlace::key($city);
        $places = ExpenseLocationGroupPlace::query()->with('group')->where('country_code', $country)
            ->whereHas('group', fn ($q) => $q->where('status', ActiveStatus::Active))->get()
            ->filter(fn (ExpenseLocationGroupPlace $p) => $state === '' || ($country === 'IN' ? $p->state_code : mb_strtolower($p->state_code)) === $state);

        if ($cityKey !== '') {
            $cities = $places->filter(fn (ExpenseLocationGroupPlace $p) => $p->city_key === $cityKey);
            if ($cities->pluck('expense_location_group_id')->unique()->count() === 1) {
                return $cities->first()->group;
            }
            if ($cities->isNotEmpty()) {
                return null;
            }
        }
        if ($state === '') {
            return null;
        }

        return $places->first(fn (ExpenseLocationGroupPlace $p) => $p->city_key === '')?->group;
    }

    /** @return array<string, array{country_code: string, state_code: string, city: ?string}> */
    private function placeRows(array $places): array
    {
        $states = (array) config('peopleos.compliance.states', []);
        $rows = [];
        foreach ($places as $entry) {
            $country = strtoupper(trim((string) ($entry['country_code'] ?? 'IN'))) ?: 'IN';
            if (! preg_match('/^[A-Z]{2}$/', $country)) {
                throw new ExpenseRuleViolation('A country is its two-letter code, e.g. IN.');
            }
            $state = trim((string) ($entry['state_code'] ?? ''));
            if ($country === 'IN') {
                $state = strtoupper($state);
                if (! array_key_exists($state, $states)) {
                    throw new ExpenseRuleViolation('Choose an Indian state or union territory for every row.');
                }
            } elseif ($state === '' || mb_strlen($state) > 64) {
                throw new ExpenseRuleViolation('Name the state of every row (at most 64 characters).');
            }
            $cities = array_values(array_filter(array_map(fn ($c) => trim((string) $c), (array) ($entry['cities'] ?? [])), fn ($c) => $c !== ''));
            foreach ($cities === [] ? [null] : $cities as $city) {
                if ($city !== null && mb_strlen($city) > 120) {
                    throw new ExpenseRuleViolation('A city name can be at most 120 characters.');
                }
                $rows[self::rowKey($country, $state, $city)] ??= ['country_code' => $country, 'state_code' => $state, 'city' => $city];
            }
        }

        return $rows;
    }

    private static function rowKey(string $country, string $state, ?string $city): string
    {
        return strtoupper($country).'|'.($country === 'IN' ? strtoupper($state) : mb_strtolower($state)).'|'.ExpenseLocationGroupPlace::key($city);
    }

    private static function placeLabel(array $row): string
    {
        $state = $row['country_code'] === 'IN' ? (config('peopleos.compliance.states')[$row['state_code']] ?? $row['state_code']) : $row['state_code'];

        return $row['city'] !== null ? "{$row['city']} ({$state})" : "{$state} (whole state)";
    }
}
