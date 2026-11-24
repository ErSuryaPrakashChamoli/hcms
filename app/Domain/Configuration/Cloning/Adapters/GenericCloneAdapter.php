<?php

namespace App\Domain\Configuration\Cloning\Adapters;

use App\Domain\Configuration\Applicability\ApplicabilityDependencies;
use App\Domain\Configuration\Applicability\ApplicabilitySelection;
use App\Domain\Configuration\Applicability\ConfigurationType;
use App\Domain\Configuration\Applicability\ConfigurationTypes;
use App\Domain\Configuration\Applicability\EntityApplicability;
use App\Domain\Configuration\Applicability\SharedConfigurationGuard;
use App\Domain\Configuration\Cloning\ApplicabilityKind;
use App\Domain\Configuration\Cloning\CloneAdapter;
use App\Domain\Configuration\Cloning\ClonePlan;
use App\Domain\Configuration\Cloning\Conflict;
use App\Domain\Configuration\Cloning\DependencyStrategy;
use App\Domain\Configuration\Cloning\Destination;
use App\Domain\Configuration\Cloning\Materialised;
use App\Domain\Configuration\Cloning\OrgUnitMapper;
use App\Domain\Configuration\Cloning\Reference;
use App\Domain\Configuration\Cloning\ReferenceKind;
use App\Domain\Configuration\Cloning\Snapshot;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Scopes\AccessScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * The clone adapter of every configuration type registered in config('peopleos.configuration_types') with a 'clone' key
 * and no adapter of its own (configuration applicability, cloning engine): the record and its owned child rows
 * ('clone_children') are copied INACTIVE under a new code, limited to the destination company.
 *
 * - Identity: the new name and code chosen in the wizard; a company-owned record belongs to the destination.
 * - Applicability: a tenant-wide copy gets explicit applicability "current entity only" (the destination); a company-owned
 *   copy is owned by the destination. Rule conditions ('rule_columns') lose their company conditions (applicability now
 *   says which company) and have company units mapped to the destination's units of the same name, or dropped with a note.
 * - References: shared records are reused when the destination may use them (else the clone is blocked until they apply
 *   there); a company unit of another company is replaced by the destination's unit of the same name, or left empty with
 *   a note.
 * - Never copied: ids, timestamps, versions and defaults, and every table in 'usages' / 'clone_excluded' (transactions).
 */
final class GenericCloneAdapter implements CloneAdapter, DescribesColumns
{
    /** Columns a copy never takes from its source. */
    private const NEVER = ['id', 'tenant_id', 'created_at', 'updated_at', 'deleted_at', 'created_by', 'updated_by', 'current_version_id', 'is_default', 'is_system', 'published_at', 'published_by', 'archived_at'];

    private readonly ConfigurationType $type;

    public function __construct(
        private readonly string $key,
        private readonly ConfigurationTypes $types,
        private readonly EntityApplicability $applicability,
        private readonly ApplicabilityDependencies $dependencies,
        private readonly OrgUnitMapper $units,
    ) {
        $this->type = $types->for($key);
    }

    public function key(): string
    {
        return $this->key;
    }

    public function label(): string
    {
        return $this->type->label;
    }

    public function model(): string
    {
        return $this->type->model;
    }

    public function nameColumn(): string
    {
        return $this->type->nameColumn;
    }

    public function codeColumn(): string
    {
        return $this->type->codeColumn ?? 'code';
    }

    public function createPermission(): string
    {
        return $this->type->permission;
    }

    public function applicability(): ApplicabilityKind
    {
        return ApplicabilityKind::EntityApplicability;
    }

    public function find(int $id): ?Model
    {
        return $this->type->model::query()->withoutGlobalScope(AccessScope::class)->find($id);
    }

    public function sourceOptions(Model $source): array
    {
        return [];
    }

    public function snapshot(Model $source, array $options = []): Snapshot
    {
        $identity = [$this->type->nameColumn => $source->getAttribute($this->type->nameColumn), $this->codeColumn() => $source->getAttribute($this->codeColumn())];
        if ($this->type->ownerColumn !== null) {
            $identity[$this->type->ownerColumn] = $source->getAttribute($this->type->ownerColumn);
        }
        $children = [];
        foreach ($this->childSpecs() as $relation => $spec) {
            $children[$relation] = $source->{$relation}()->withoutGlobalScope(AccessScope::class)->reorder()->orderBy('id')->get()
                ->map(fn (Model $child) => ['_source_id' => (int) $child->getKey()] + self::attributes($child, [$spec['foreign']]))->all();
        }

        return new Snapshot($this->key, (int) $source->getKey(), $this->type->describe($source), $identity,
            ['attributes' => self::attributes($source, [$this->type->nameColumn, $this->codeColumn(), ...array_filter([$this->type->ownerColumn]), ...$this->excludedColumns()]), 'children' => $children],
            ['companies' => $this->applicability->reach($source)]);
    }

    public function references(Snapshot $snapshot): array
    {
        $source = $this->find($snapshot->sourceId);
        if ($source === null) {
            return [];
        }
        $references = [];
        foreach ($this->dependencies->references($this->type, $source) as $found) {
            $targetType = $this->types->for($found['type']);
            $target = $targetType->model::query()->withoutGlobalScope(AccessScope::class)->find($found['id']);
            $owned = $targetType->companyOwned();
            $kind = $owned ? ReferenceKind::CompanyUnit : ReferenceKind::SharedMaster;
            $references[] = new Reference(
                path: $found['path'], label: $targetType->label, kind: $kind, type: $found['type'], value: $found['id'],
                targetId: $target === null ? null : (int) $target->getKey(), targetLabel: $target === null ? null : $targetType->describe($target),
                strategy: $owned ? DependencyStrategy::Map : DependencyStrategy::Reuse,
                problem: $target === null ? 'it no longer exists; the copy leaves it empty' : null,
            );
        }

        return $references;
    }

    public function impact(Snapshot $snapshot, Destination $destination, ClonePlan $plan): ?array
    {
        return null;
    }

    public function conflicts(Snapshot $snapshot, Destination $destination, ClonePlan $plan): array
    {
        $conflicts = [];
        foreach ($plan->references as $reference) {
            if ($reference->targetId === null) {
                continue;
            }
            $targetType = $this->types->for($reference->type);
            $target = $targetType->model::query()->withoutGlobalScope(AccessScope::class)->find($reference->targetId);
            if ($target === null || $this->applicability->appliesTo($target, $destination->companyId)) {
                continue;
            }
            if ($targetType->companyOwned()) {
                $mapped = $this->mapUnit($reference->type, $reference->targetId, $destination->companyId);
                $mappedRecord = $mapped === null ? null : $targetType->model::query()->withoutGlobalScope(AccessScope::class)->find($mapped);
                $conflicts[] = Conflict::warning('unit_mapped', $mappedRecord !== null
                    ? "{$targetType->label} \"{$reference->targetLabel}\" belongs to another company; the copy uses the destination's own \"{$targetType->describe($mappedRecord)}\"."
                    : "{$targetType->label} \"{$reference->targetLabel}\" belongs to another company and the destination has none of that name; the copy leaves it empty.", $destination->companyId);
            } else {
                $conflicts[] = Conflict::blocker('reference_not_applicable', "It uses the {$targetType->label} \"{$reference->targetLabel}\", which the destination company cannot use. Make it apply there first.", $destination->companyId);
            }
        }
        foreach ($this->ruleColumns() as $column) {
            foreach ((array) ($snapshot->content['attributes'][$column] ?? []) as $condition) {
                $condition = is_string($condition) ? json_decode($condition, true) : $condition;
                if (is_array($condition) && OrgUnitMapper::isUnit((string) ($condition['field'] ?? '')) && $this->mapConditionUnits($condition, $destination->companyId) === null) {
                    $conflicts[] = Conflict::warning('condition_dropped', 'A condition on '.Str::of((string) $condition['field'])->beforeLast('_id')->replace('_', ' ').' names units the destination does not have; the copy drops it, so check whom the copy reaches before activating it.', $destination->companyId);
                }
            }
        }

        return $conflicts;
    }

    public function codeTaken(string $code): bool
    {
        $query = $this->type->model::query()->withoutGlobalScope(AccessScope::class);
        // Quoted by the grammar: some types' code column is `key`, a reserved word in MySQL (SQLite let it through).
        $column = $query->getQuery()->getGrammar()->wrap($query->getModel()->qualifyColumn($this->codeColumn()));

        return $query->whereRaw("LOWER({$column}) = ?", [Str::lower($code)])->exists();
    }

    public function lookAlikes(Snapshot $snapshot, Destination $destination): array
    {
        return $this->type->model::query()->withoutGlobalScope(AccessScope::class)->where($this->type->nameColumn, $destination->name)
            ->limit(5)->get()->map(fn (Model $m) => $this->type->describe($m))->all();
    }

    public function materialise(Snapshot $snapshot, Destination $destination, ClonePlan $plan, User $actor): Materialised
    {
        return SharedConfigurationGuard::suspend(function () use ($snapshot, $destination) {
            $attributes = (array) $snapshot->content['attributes'];
            foreach ($this->type->references as $column => $targetKey) {
                if (array_key_exists($column, $attributes) && filled($attributes[$column])) {
                    $attributes[$column] = $this->referenceFor($targetKey, (int) $attributes[$column], $destination->companyId);
                }
            }
            foreach ($this->ruleColumns() as $column) {
                if (array_key_exists($column, $attributes)) {
                    $attributes[$column] = $this->boundConditions($attributes[$column], $destination->companyId);
                }
            }
            $copy = new ($this->type->model);
            $copy->setRawAttributes($attributes);
            $copy->setAttribute($this->type->nameColumn, $destination->name);
            $copy->setAttribute($this->codeColumn(), $destination->code);
            if ($this->type->ownerColumn !== null) {
                $copy->setAttribute($this->type->ownerColumn, $destination->companyId);
            }
            if (($status = $this->inactiveStatus($copy)) !== null) {
                $copy->setAttribute('status', $status);
            }
            $copy->save();

            $children = [];
            foreach ($this->childSpecs() as $relation => $spec) {
                $ids = [];
                foreach ((array) ($snapshot->content['children'][$relation] ?? []) as $row) {
                    $sourceId = (int) $row['_source_id'];
                    unset($row['_source_id']);
                    if (isset($spec['parent']) && filled($row[$spec['parent']] ?? null)) {
                        $row[$spec['parent']] = $ids[(int) $row[$spec['parent']]] ?? null;
                    }
                    /** @var HasMany $relationship */
                    $relationship = $copy->{$relation}();
                    $child = $relationship->getRelated()->newInstance();
                    $child->setRawAttributes($row);
                    $child->setAttribute($spec['foreign'], $copy->getKey());
                    $child->save();
                    $ids[$sourceId] = (int) $child->getKey();
                    $children[] = ['adapter' => "{$this->key}.{$relation}", 'source_id' => $sourceId, 'target_id' => (int) $child->getKey()];
                }
            }

            // A tenant-wide copy applies to its destination only; a company-owned copy already belongs to it.
            if ($this->type->ownerColumn === null) {
                $this->applicability->apply($copy, ApplicabilitySelection::single($destination->companyId), null, 'Cloned for this company');
            }

            return new Materialised($copy, $children);
        });
    }

    public function excluded(): array
    {
        return array_values(array_unique([...array_column($this->type->usages, 'table'), ...(array) config("peopleos.configuration_types.{$this->key}.clone_excluded", [])]));
    }

    /** @return array<string, array{foreign: string, parent?: string}> relation => spec */
    private function childSpecs(): array
    {
        return (array) config("peopleos.configuration_types.{$this->key}.clone_children", []);
    }

    /** @return list<string> */
    private function ruleColumns(): array
    {
        return (array) config("peopleos.configuration_types.{$this->key}.rule_columns", []);
    }

    /** @return list<string> */
    private function excludedColumns(): array
    {
        return (array) config("peopleos.configuration_types.{$this->key}.clone_exclude", []);
    }

    /** @param  list<string>  $without  @return array<string, mixed> raw column values, deterministic */
    private static function attributes(Model $model, array $without): array
    {
        $values = array_diff_key($model->getAttributes(), array_flip([...self::NEVER, ...$without]));
        ksort($values);

        return $values;
    }

    private function inactiveStatus(Model $copy): ?string
    {
        if (! array_key_exists('status', $copy->getAttributes())) {
            return null;
        }
        $cast = $copy->getCasts()['status'] ?? null;
        if (is_string($cast) && enum_exists($cast)) {
            foreach (['inactive', 'draft'] as $value) {
                if ($cast::tryFrom($value) !== null) {
                    return $value;
                }
            }

            return null;
        }

        return 'inactive';
    }

    private function referenceFor(string $targetKey, int $id, int $companyId): ?int
    {
        if (! $this->types->has($targetKey)) {
            return $id;
        }
        $targetType = $this->types->for($targetKey);
        $target = $targetType->model::query()->withoutGlobalScope(AccessScope::class)->find($id);
        if ($target === null) {
            return null;
        }
        if ($this->applicability->appliesTo($target, $companyId)) {
            return $id;
        }

        return $targetType->companyOwned() ? $this->mapUnit($targetKey, $id, $companyId) : $id;
    }

    /** The destination company's own record of the same name (a company-owned unit), or null. */
    private function mapUnit(string $targetKey, int $id, int $companyId): ?int
    {
        $field = $this->unitField($targetKey);
        if (OrgUnitMapper::isUnit($field)) {
            return $this->units->suggest($field, $id, $companyId);
        }
        $type = $this->types->for($targetKey);
        $source = $type->model::query()->withoutGlobalScope(AccessScope::class)->find($id);
        if ($source === null || $type->ownerColumn === null) {
            return null;
        }
        $match = $type->model::query()->withoutGlobalScope(AccessScope::class)->where($type->ownerColumn, $companyId)
            ->where($type->nameColumn, $source->getAttribute($type->nameColumn))->value($source->getKeyName());

        return $match === null ? null : (int) $match;
    }

    private function unitField(string $targetKey): string
    {
        return $targetKey.'_id';
    }

    /** Conditions without company conditions, with units mapped to the destination's (null = a unit could not be mapped). */
    private function mapConditionUnits(array $condition, int $companyId): ?array
    {
        $field = (string) $condition['field'];
        $mapped = [];
        foreach ((array) ($condition['value'] ?? []) as $id) {
            $owner = $this->units->companyOf($field, $id);
            if ($owner === null || $owner === $companyId) {
                $mapped[] = $id;

                continue;
            }
            $target = $this->units->suggest($field, $id, $companyId);
            if ($target === null) {
                return null;
            }
            $mapped[] = $target;
        }

        return ['field' => $field, 'operator' => in_array($condition['operator'] ?? '', ['equals', 'in'], true) ? 'in' : (string) $condition['operator'], 'value' => $mapped];
    }

    private function boundConditions(mixed $raw, int $companyId): ?string
    {
        $conditions = is_string($raw) ? json_decode($raw, true) : $raw;
        if (! is_array($conditions)) {
            return is_string($raw) ? $raw : null;
        }
        $out = [];
        foreach ($conditions as $condition) {
            $field = (string) ($condition['field'] ?? '');
            if ($field === 'company_id' || $field === 'employee_id') {
                continue;
            }
            if (OrgUnitMapper::isUnit($field)) {
                if (($condition = $this->mapConditionUnits($condition, $companyId)) === null) {
                    continue;
                }
            }
            $out[] = $condition;
        }

        return json_encode(array_values($out));
    }
}
