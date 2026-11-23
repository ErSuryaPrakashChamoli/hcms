<?php

namespace App\Domain\Configuration\Cloning;

use App\Domain\Identity\Scopes\AccessScope;
use App\Domain\Organisation\Models\BusinessUnit;
use App\Domain\Organisation\Models\Department;
use App\Domain\Organisation\Models\Division;
use App\Domain\Organisation\Models\Location;
use App\Domain\Organisation\Models\Team;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Company-owned organisation units in rule conditions (location, business unit, division, department, parent department,
 * team): which company a unit belongs to (null = shared by every company), and its equivalent in another company,
 * suggested by the same name. Reads ignore the person's organisation scope (they decide what a rule means, not what the
 * person sees) but never leave the tenant.
 */
final class OrgUnitMapper
{
    /** @var array<string, class-string<Model>> rule field => unit model */
    public const UNITS = [
        'location_id' => Location::class,
        'business_unit_id' => BusinessUnit::class,
        'division_id' => Division::class,
        'department_id' => Department::class,
        'parent_department_id' => Department::class,
        'team_id' => Team::class,
    ];

    /** @var array<string, array<int, array{company_id: ?int, name: string}>> model class => id => unit */
    private array $units = [];

    public static function isUnit(string $field): bool
    {
        return array_key_exists($field, self::UNITS);
    }

    /** The unit's company: null when it is shared by every company; false when it no longer exists. */
    public function companyOf(string $field, int|string $id): int|null|false
    {
        $unit = $this->units($field)[(int) $id] ?? null;

        return $unit === null ? false : $unit['company_id'];
    }

    public function name(string $field, int|string $id): string
    {
        return $this->units($field)[(int) $id]['name'] ?? "#{$id}";
    }

    /** The destination company's unit of the same name (letters and spaces compared loosely), if there is exactly one. */
    public function suggest(string $field, int|string $id, int $companyId): ?int
    {
        $name = $this->normalise($this->units($field)[(int) $id]['name'] ?? '');
        if ($name === '') {
            return null;
        }
        $matches = array_keys(array_filter($this->units($field), fn (array $u) => $u['company_id'] === $companyId && $this->normalise($u['name']) === $name));

        return count($matches) === 1 ? $matches[0] : null;
    }

    /** @return array<int, string> the units a rule in this company may name: the company's own and the shared ones */
    public function options(string $field, int $companyId): array
    {
        return collect($this->units($field))
            ->filter(fn (array $u) => $u['company_id'] === null || $u['company_id'] === $companyId)
            ->map(fn (array $u) => $u['name'].($u['company_id'] === null ? ' (every company)' : ''))
            ->sort()->all();
    }

    /** @return array<int, array{company_id: ?int, name: string}> */
    private function units(string $field): array
    {
        $model = self::UNITS[$field] ?? null;
        if ($model === null) {
            return [];
        }

        return $this->units[$model] ??= $model::query()->withoutGlobalScope(AccessScope::class)->get(['id', 'company_id', 'name'])
            ->mapWithKeys(fn (Model $u) => [(int) $u->getKey() => ['company_id' => $u->getAttribute('company_id') === null ? null : (int) $u->getAttribute('company_id'), 'name' => (string) $u->getAttribute('name')]])
            ->all();
    }

    private function normalise(string $name): string
    {
        return Str::of($name)->lower()->replaceMatches('/[^a-z0-9]+/', ' ')->trim()->toString();
    }
}
