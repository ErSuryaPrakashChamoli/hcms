<?php

namespace App\Domain\Organisation\Services;

use App\Domain\Organisation\Enums\ActiveStatus;
use App\Domain\Organisation\Exceptions\InvalidHierarchyException;
use App\Domain\Organisation\Models\Company;
use App\Domain\Organisation\Models\OrganisationNode;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Builds and rearranges the structural hierarchy. Containment rules come from
 * config('peopleos.organisation.node_types'), so a tenant's shape is configuration, not code.
 * Every mutation goes through Auditable models, so the designer leaves a full change history.
 */
final class OrganisationTree
{
    /** @return array<string, array{label: string, model: class-string<Model>, root: bool, children: list<string>}> */
    public function types(): array
    {
        return config('peopleos.organisation.node_types', []);
    }

    /** @return class-string<Model> */
    public function modelForType(string $type): string
    {
        return $this->types()[$type]['model'] ?? throw new InvalidHierarchyException("Unknown organisation node type [{$type}].");
    }

    public function typeKeyForClass(string $class): string
    {
        foreach ($this->types() as $key => $definition) {
            if ($definition['model'] === $class) {
                return $key;
            }
        }

        throw new InvalidHierarchyException("{$class} is not an organisation node type.");
    }

    public function typeFor(Model $unit): string
    {
        return $this->typeKeyForClass($unit::class);
    }

    /** @return list<string> */
    public function allowedChildTypes(?OrganisationNode $parent): array
    {
        if ($parent === null) {
            return array_keys(array_filter($this->types(), fn (array $t) => $t['root']));
        }

        return $this->types()[$parent->typeKey()]['children'] ?? [];
    }

    public function assertCanContain(?OrganisationNode $parent, string $childType): void
    {
        if (! in_array($childType, $this->allowedChildTypes($parent), true)) {
            $label = $this->types()[$childType]['label'] ?? $childType;
            $where = $parent ? 'beneath a '.$parent->typeLabel() : 'at the root';

            throw new InvalidHierarchyException("A {$label} cannot sit {$where}.");
        }
    }

    /**
     * Wrap an existing organisation master in a node under $parent.
     */
    public function attach(Model $unit, ?OrganisationNode $parent = null, ?string $reason = null): OrganisationNode
    {
        $type = $this->typeFor($unit);
        $this->assertCanContain($parent, $type);

        return DB::transaction(function () use ($unit, $parent, $reason) {
            $node = new OrganisationNode([
                'parent_id' => $parent?->getKey(),
                'nodeable_type' => $unit::class,
                'nodeable_id' => $unit->getKey(),
                'sort_order' => $this->nextSortOrder($parent),
                'depth' => $parent ? $parent->depth + 1 : 0,
                'status' => $unit->getAttribute('status') ?? ActiveStatus::Active,
            ]);
            $node->withAuditReason($reason)->save();

            $node->forceFill(['path' => OrganisationNode::pathFor($parent, $node->getKey())])->saveQuietly();

            return $node->setRelation('nodeable', $unit);
        });
    }

    /**
     * Create a new master of $type (inheriting the nearest company as owner) and attach it.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function createUnit(string $type, array $attributes, ?OrganisationNode $parent = null, ?string $reason = null): OrganisationNode
    {
        $this->assertCanContain($parent, $type);
        $model = $this->modelForType($type);

        return DB::transaction(function () use ($model, $attributes, $parent, $reason) {
            /** @var Model $unit */
            $unit = new $model($attributes);

            if ($unit->isFillable('company_id') && $unit->getAttribute('company_id') === null && $parent !== null) {
                $unit->setAttribute('company_id', $this->nearestCompanyId($parent));
            }

            $unit->withAuditReason($reason)->save();

            return $this->attach($unit, $parent, $reason);
        });
    }

    public function move(OrganisationNode $node, ?OrganisationNode $newParent, ?string $reason = null): OrganisationNode
    {
        if ($newParent !== null && ($newParent->is($node) || $node->isAncestorOf($newParent))) {
            throw new InvalidHierarchyException('A unit cannot be moved beneath itself or one of its descendants.');
        }

        $this->assertCanContain($newParent, $node->typeKey());

        return DB::transaction(function () use ($node, $newParent, $reason) {
            $oldPath = $node->path;

            $node->withAuditReason($reason)->update([
                'parent_id' => $newParent?->getKey(),
                'sort_order' => $this->nextSortOrder($newParent),
            ]);

            $this->rebuildPaths($node, $newParent, $oldPath);

            return $node->refresh();
        });
    }

    public function rename(OrganisationNode $node, string $name, ?string $reason = null): OrganisationNode
    {
        $node->nodeable->withAuditReason($reason)->update(['name' => $name]);

        return $node;
    }

    /**
     * Deactivating cascades to the subtree (a department cannot be live inside a dead division);
     * reactivating touches only the node itself.
     */
    public function setStatus(OrganisationNode $node, ActiveStatus $status, ?string $reason = null): OrganisationNode
    {
        return DB::transaction(function () use ($node, $status, $reason) {
            $targets = $status === ActiveStatus::Inactive
                ? $node->descendants()->with('nodeable')->get()->push($node)
                : Collection::make([$node]);

            foreach ($targets as $target) {
                $target->withAuditReason($reason)->update(['status' => $status]);
                $target->nodeable?->withAuditReason($reason)->update(['status' => $status]);
            }

            return $node->refresh();
        });
    }

    public function reorder(OrganisationNode $node, string $direction, ?string $reason = null): OrganisationNode
    {
        $siblings = OrganisationNode::query()
            ->where('parent_id', $node->parent_id)
            ->orderBy('sort_order')->orderBy('id')
            ->get();

        $index = $siblings->search(fn (OrganisationNode $s) => $s->is($node));
        $swapWith = $direction === 'up' ? $index - 1 : $index + 1;

        if ($index === false || $swapWith < 0 || $swapWith >= $siblings->count()) {
            return $node;
        }

        return DB::transaction(function () use ($siblings, $node, $index, $swapWith, $reason) {
            // Normalise to 1..n first so swaps are always meaningful.
            $siblings->each(fn (OrganisationNode $s, int $i) => $s->sort_order === $i + 1 || $s->forceFill(['sort_order' => $i + 1])->saveQuietly());

            $other = $siblings[$swapWith];
            $node->withAuditReason($reason)->update(['sort_order' => $swapWith + 1]);
            $other->withAuditReason($reason)->update(['sort_order' => $index + 1]);

            return $node->refresh();
        });
    }

    /** Remove a leaf node from the tree. The master record itself is untouched. */
    public function detach(OrganisationNode $node, ?string $reason = null): void
    {
        if ($node->children()->exists()) {
            throw new InvalidHierarchyException('Move or remove the child units first.');
        }

        $node->withAuditReason($reason)->delete();
    }

    /**
     * The whole tenant tree as nested nodes (children eager-built in memory, one query).
     * With $search, only matching nodes and their ancestors are returned, matches flagged.
     *
     * @return Collection<int, OrganisationNode>
     */
    public function tree(?string $search = null): Collection
    {
        $nodes = OrganisationNode::query()
            ->with('nodeable')
            ->orderBy('depth')->orderBy('sort_order')->orderBy('id')
            ->get();

        if (filled($search)) {
            $needle = mb_strtolower(trim($search));
            $keep = [];

            foreach ($nodes as $node) {
                $haystack = mb_strtolower($node->nodeable?->name.' '.$node->nodeable?->code);
                $node->setAttribute('matches_search', str_contains($haystack, $needle));

                if ($node->matches_search) {
                    foreach (array_filter(explode('/', (string) $node->path)) as $ancestorId) {
                        $keep[(int) $ancestorId] = true;
                    }
                }
            }

            $nodes = $nodes->filter(fn (OrganisationNode $n) => isset($keep[$n->getKey()]))->values();
        }

        $byParent = $nodes->groupBy(fn (OrganisationNode $n) => $n->parent_id ?? 0);

        foreach ($nodes as $node) {
            $node->setRelation('children', $byParent->get($node->getKey(), Collection::make()));
        }

        return $byParent->get(0, Collection::make());
    }

    /** @return array<int, string> node id => indented "Type: Name" label, for parent selectors */
    public function options(?OrganisationNode $exclude = null, ?string $childType = null): array
    {
        $options = [];

        $walk = function (Collection $nodes) use (&$walk, &$options, $exclude, $childType): void {
            foreach ($nodes as $node) {
                if ($exclude !== null && ($node->is($exclude) || $exclude->isAncestorOf($node))) {
                    continue;
                }

                if ($childType === null || in_array($childType, $this->allowedChildTypes($node), true)) {
                    $options[$node->getKey()] = str_repeat('— ', $node->depth).$node->typeLabel().': '.$node->nodeable?->name;
                }

                $walk($node->children);
            }
        };

        $walk($this->tree());

        return $options;
    }

    public function nearestCompanyId(OrganisationNode $node): ?int
    {
        for ($current = $node; $current !== null; $current = $current->parent) {
            if ($current->nodeable_type === Company::class) {
                return $current->nodeable_id;
            }

            if ($companyId = $current->nodeable?->getAttribute('company_id')) {
                return $companyId;
            }
        }

        return null;
    }

    private function nextSortOrder(?OrganisationNode $parent): int
    {
        return (int) OrganisationNode::query()->where('parent_id', $parent?->getKey())->max('sort_order') + 1;
    }

    private function rebuildPaths(OrganisationNode $node, ?OrganisationNode $newParent, ?string $oldPath): void
    {
        $newPath = OrganisationNode::pathFor($newParent, $node->getKey());
        $newDepth = $newParent ? $newParent->depth + 1 : 0;
        $node->forceFill(['path' => $newPath, 'depth' => $newDepth])->saveQuietly();

        if ($oldPath === null) {
            return;
        }

        OrganisationNode::query()
            ->where('path', 'like', $oldPath.'%')
            ->whereKeyNot($node->getKey())
            ->orderBy('depth')
            ->each(function (OrganisationNode $descendant) use ($oldPath, $newPath, $newDepth, $node) {
                $descendant->forceFill([
                    'path' => $newPath.substr($descendant->path, strlen($oldPath)),
                    'depth' => $newDepth + ($descendant->depth - $node->depth),
                ])->saveQuietly();
            });
    }
}
