<?php

namespace App\Domain\Configuration\Applicability;

use App\Domain\Identity\Scopes\AccessScope;
use App\Domain\Organisation\Models\Company;
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
            $missing = array_values(array_diff($after, $reach));
            if ($missing !== []) {
                $blockers[] = "It uses the {$targetType->label} \"{$targetType->describe($target)}\", which ".self::names($missing, $names).' cannot use. Make that '.strtolower($targetType->label).' apply there first, or choose other companies.';
            }
            if ($includeFuture) {
                $warnings[] = "The {$targetType->label} \"{$targetType->describe($target)}\" it uses does not include companies created later; add them there too when a company is created.";
            }
        }

        if ($removed !== []) {
            foreach ($this->referencing($type, (int) $record->getKey()) as [$otherType, $other]) {
                $reach = $applicability->reach($other, $from) ?? $applicability->allCompanyIds();
                $conflict = array_values(array_intersect($removed, $reach));
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
