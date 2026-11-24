<?php

namespace App\Domain\Configuration\Applicability;

use App\Domain\Configuration\Models\ConfigurationOverride;
use App\Domain\Configuration\Overrides\ConfigurationOverrides;
use App\Domain\Identity\Scopes\AccessScope;
use App\Domain\Organisation\Models\Company;
use App\Domain\Organisation\Models\Establishment;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Dependency validation for applicability changes: a shared configuration may never point at something its companies
 * cannot use, nothing may keep pointing at it from a company it leaves, and people currently using it in a company keep
 * that company until they are moved.
 *
 * - Forward: every registered record it references (columns in the type's 'references', or its ReferencesProvider) must
 *   apply to every company it will apply to.
 * - Reverse: every registered record that references it must not reach a company it leaves.
 * - Usage: rows in the type's 'usages' (current positions, …) in a company it leaves block the change.
 */
final class ApplicabilityDependencies
{
    public function __construct(private readonly ConfigurationTypes $types) {}

    /**
     * @param  list<int>  $after
     * @param  list<int>  $removed
     * @param  array<int, string>  $names
     * @return array{0: list<string>, 1: list<string>} blockers, warnings
     */
    public function check(ConfigurationType $type, Model $record, array $after, array $removed, string $from, bool $includeFuture, array $names): array
    {
        $blockers = [];
        $warnings = [];
        $applicability = app(EntityApplicability::class);
        $label = strtolower($type->label);

        // Master relationships R3: a company with its own value of a link (a designation's grade at FynnEdge) is checked
        // against that value, not the shared one.
        $own = [];
        if ($type->supportsOverrides() && $record->exists && app(ConfigurationOverrides::class)->hasAny($type->key, (int) $record->getKey())) {
            foreach ($after as $companyId) {
                foreach (app(ConfigurationOverrides::class)->valuesFor($type->key, (int) $record->getKey(), (int) $companyId, $from) as $column => $value) {
                    if (isset($type->references[$column])) {
                        $own[(int) $companyId][$column] = $value;
                    }
                }
            }
        }
        foreach ($this->references($type, $record) as $reference) {
            $target = $this->find($reference['type'], $reference['id']);
            $targetType = $this->types->for($reference['type']);
            if ($target === null) {
                $warnings[] = "It points at a {$targetType->label} that no longer exists ({$reference['path']}).";

                continue;
            }
            $reach = $applicability->reach($target, $from);
            if ($reach === null) {
                continue;
            }
            $checked = array_values(array_filter($after, fn ($companyId) => ! array_key_exists($reference['path'], $own[(int) $companyId] ?? [])));
            $missing = array_values(array_diff($checked, $reach));
            if ($missing !== []) {
                $blockers[] = "It uses the {$targetType->label} \"{$targetType->describe($target)}\", which ".self::names($missing, $names).' cannot use. Make that '.strtolower($targetType->label).' apply there first, or choose other companies.';
            }
            if ($includeFuture) {
                $warnings[] = "The {$targetType->label} \"{$targetType->describe($target)}\" it uses does not include companies created later; add them there too when a company is created.";
            }
        }

        foreach ($own as $companyId => $values) {
            foreach ($values as $column => $value) {
                if (blank($value) || ($target = $this->find($type->references[$column], (int) $value)) === null) {
                    continue;
                }
                $targetType = $this->types->for($type->references[$column]);
                $reach = $applicability->reach($target, $from);
                if ($reach !== null && ! in_array((int) $companyId, $reach, true)) {
                    $blockers[] = 'Its own '.strtolower($targetType->label).' for '.($names[$companyId] ?? 'a company').", \"{$targetType->describe($target)}\", does not apply there. Change that company's value first, or choose other companies.";
                }
            }
        }
        // A location tied to an establishment stays with that establishment's company: shared further, people in another
        // company would be stamped with it and use its statutory registrations.
        if ($type->key === 'location' && filled($record->getAttribute('establishment_id'))) {
            $establishment = Establishment::query()->withoutGlobalScope(AccessScope::class)->find($record->getAttribute('establishment_id'));
            $others = $establishment === null ? [] : array_values(array_diff($after, [(int) $establishment->company_id]));
            if ($others !== []) {
                $owner = $names[(int) $establishment->company_id] ?? Company::query()->withoutGlobalScope(AccessScope::class)->whereKey($establishment->company_id)->value('name') ?? 'its company';
                $blockers[] = "Its establishment \"{$establishment->name}\" belongs to {$owner}, so ".self::names($others, $names).' cannot use this location. Remove the establishment, or keep the location to that company.';
            }
        }

        if ($removed !== []) {
            // A company's own value of another record's link counts as a use of this one there.
            foreach ($this->types->all() as $otherType) {
                $columns = array_keys(array_filter($otherType->references, fn (string $key, string $column) => $key === $type->key && ! str_contains($column, '.'), ARRAY_FILTER_USE_BOTH));
                if ($columns === [] || ! $otherType->supportsOverrides()) {
                    continue;
                }
                $rows = ConfigurationOverride::query()->where('configuration_type', $otherType->key)->whereIn('field', $columns)->whereIn('company_id', $removed)
                    ->where('status', ConfigurationOverride::ACTIVE)->where(fn ($q) => $q->whereNull('effective_to')->orWhere('effective_to', '>=', $from))->get();
                foreach ($rows as $row) {
                    if ((int) $row->value === (int) $record->getKey() && ($other = $this->find($otherType->key, (int) $row->configuration_id)) !== null) {
                        $blockers[] = "The {$otherType->label} \"{$otherType->describe($other)}\" uses this {$label} as its own value in ".($names[(int) $row->company_id] ?? 'a company').'. Change it there first.';
                    }
                }
            }
            foreach ($this->referencing($type, (int) $record->getKey()) as [$otherType, $other]) {
                $reach = $applicability->reach($other, $from) ?? $applicability->allCompanyIds();
                $conflict = array_values(array_intersect($removed, $reach));
                // Master relationships R3: a company that has its own value of every link pointing here does not use this
                // record there (FynnEdge's own grade, not the shared one).
                if ($conflict !== [] && $otherType->supportsOverrides()) {
                    $columns = array_keys(array_filter($otherType->references, fn (string $key, string $column) => $key === $type->key && ! str_contains($column, '.')
                        && (int) $other->getAttribute($column) === (int) $record->getKey(), ARRAY_FILTER_USE_BOTH));
                    $overrides = app(ConfigurationOverrides::class);
                    $conflict = array_values(array_filter($conflict, fn (int $companyId) => $columns === []
                        || array_diff($columns, array_keys($overrides->valuesFor($otherType->key, (int) $other->getKey(), $companyId, $from))) !== []));
                }
                if ($conflict !== []) {
                    $blockers[] = "The {$otherType->label} \"{$otherType->describe($other)}\" uses this {$label} in ".self::names($conflict, $names).'. Change it there first.';
                }
            }
            foreach ($type->usages as $usage) {
                $query = DB::table($usage['table'])->where($usage['column'], $record->getKey())->whereIn($usage['company'], $removed);
                if (isset($usage['current'])) {
                    $query->where(fn ($q) => $q->whereNull($usage['current'])->orWhere($usage['current'], '>=', $from));
                }
                if (isset($usage['tenant']) && $usage['tenant']) {
                    $query->where('tenant_id', $record->getAttribute('tenant_id'));
                }
                $counts = $query->selectRaw("{$usage['company']} as company_id, count(*) as aggregate")->groupBy($usage['company'])->pluck('aggregate', 'company_id');
                foreach ($counts as $companyId => $count) {
                    $blockers[] = "{$count} ".($usage['label'] ?? 'records').' in '.($names[(int) $companyId] ?? 'a company')." still use this {$label}. Move them first, or keep that company.";
                }
            }
        }

        return [array_values(array_unique($blockers)), array_values(array_unique($warnings))];
    }

    /** @return list<array{type: string, id: int, label: string, path: string}> */
    public function references(ConfigurationType $type, Model $record): array
    {
        $found = [];
        foreach ($type->references as $column => $targetKey) {
            if (! $this->types->has($targetKey)) {
                continue;
            }
            $value = $record->getAttribute($column);
            foreach (is_array($value) ? $value : [$value] as $id) {
                if (filled($id) && is_numeric($id)) {
                    $found[] = ['type' => $targetKey, 'id' => (int) $id, 'label' => $column, 'path' => $column];
                }
            }
        }
        if (($provider = $this->provider($type)) !== null) {
            foreach ($provider->references($record) as $reference) {
                if ($this->types->has($reference['type'])) {
                    $found[] = $reference;
                }
            }
        }

        return collect($found)->unique(fn (array $r) => $r['type'].':'.$r['id'])->values()->all();
    }

    /** @return list<array{0: ConfigurationType, 1: Model}> every registered record that points at this one */
    public function referencing(ConfigurationType $type, int $id): array
    {
        $found = [];
        foreach ($this->types->all() as $other) {
            foreach ($other->references as $column => $targetKey) {
                if ($targetKey !== $type->key || str_contains($column, '.')) {
                    continue;
                }
                $query = $other->model::query()->withoutGlobalScope(AccessScope::class);
                $casts = (new $other->model)->getCasts();
                in_array($casts[$column] ?? null, ['array', 'json', 'collection'], true)
                    ? $query->whereJsonContains($column, $id)
                    : $query->where($column, $id);
                foreach ($query->limit(50)->get() as $model) {
                    if (! ($other->key === $type->key && (int) $model->getKey() === $id)) {
                        $found[] = [$other, $model];
                    }
                }
            }
            if (($provider = $this->provider($other)) !== null) {
                foreach ($provider->referencing($type->key, $id) as $model) {
                    $found[] = [$other, $model];
                }
            }
        }

        return $found;
    }

    private function provider(ConfigurationType $type): ?ReferencesProvider
    {
        $class = config("peopleos.configuration_types.{$type->key}.references_provider");

        return is_string($class) && class_exists($class) ? app($class) : null;
    }

    private function find(string $typeKey, int $id): ?Model
    {
        $type = $this->types->for($typeKey);

        return $type->model::query()->withoutGlobalScope(AccessScope::class)->find($id);
    }

    /** @param  list<int>  $ids  @param  array<int, string>  $names */
    private static function names(array $ids, array $names): string
    {
        $labels = array_map(fn (int $id) => $names[$id] ?? (Company::query()->withoutGlobalScope(AccessScope::class)->find($id)?->name ?? "#{$id}"), $ids);

        return count($labels) === 1 ? $labels[0] : implode(', ', array_slice($labels, 0, -1)).' and '.end($labels);
    }
}
