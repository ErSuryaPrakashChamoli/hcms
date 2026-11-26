<?php

namespace App\Domain\Organisation\Imports;

use App\Domain\Employment\Imports\EmployeeImport;
use App\Domain\Employment\Imports\EmployeeImportRow;
use App\Domain\Employment\Imports\RowImports;
use App\Domain\Employment\Imports\Workbooks\ImportReferences;
use App\Domain\Employment\Imports\Workbooks\WorkbookImports;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Scopes\AccessScope;
use App\Domain\Organisation\Exceptions\InvalidHierarchyException;
use App\Domain\Organisation\Models\Company;
use App\Domain\Organisation\Models\Department;
use App\Domain\Organisation\Models\OrganisationNode;
use App\Domain\Organisation\Services\OrganisationTree;
use App\Support\Imports\ReferenceGuide;
use Illuminate\Database\Eloquent\Model;
use RuntimeException;

/**
 * Master uploads (Core → Organisation structure): where each unit sits in the organisation tree (Organisation →
 * Organisation designer): company → business unit → division → department → team, and locations. One row per unit:
 * its type and name (names first: its code, or "Name (CODE)", works too) and its parent's. Names are matched among
 * records of the type the row gives (ImportReferences::resolve), so a department and a team may share a name. A unit
 * not in the tree yet is placed (OrganisationTree::attach); one already placed moves (OrganisationTree::move, which also
 * keeps a department's parent department in step); one already under that parent is skipped. The tree's own rules
 * apply (which types may sit under which, no loops). Units are created by their own uploads (Companies, Business units,
 * Divisions, Departments, Teams, Branches) first.
 */
final class OrganisationTreeImports extends RowImports
{
    public const TYPE = 'organisation_tree';

    public static function fields(): array
    {
        return ['unit_type' => ['Unit type', true], 'unit_code' => ['Unit', true], 'parent_type' => ['Parent type', false], 'parent_code' => ['Parent', false]];
    }

    public function label(): string
    {
        return 'Organisation structure upload';
    }

    protected function module(): string
    {
        return 'organisation';
    }

    protected function entityType(): string
    {
        return OrganisationNode::class;
    }

    protected function aliases(): array
    {
        return ['type' => 'unit_type', 'unit' => 'unit_code', 'code' => 'unit_code', 'parent' => 'parent_code', 'parent_unit_code' => 'parent_code', 'parent_unit_type' => 'parent_type'];
    }

    protected function prepare(EmployeeImport $import): array
    {
        return ['seen' => [], 'placed' => []];
    }

    protected function check(array $r, array &$context, EmployeeImportRow $row, EmployeeImport $import): array
    {
        [$unit, $node, $parent, $parentNode, $errors] = $this->resolve($r, $context, $row);
        if ($errors !== []) {
            return ['action' => 'error', 'errors' => $errors];
        }
        $key = $this->key($unit);
        if (isset($context['seen'][$key])) {
            return ['action' => 'error', 'errors' => ["The same unit is in row {$context['seen'][$key]}."]];
        }
        $context['seen'][$key] = $row->row_number;
        $context['placed'][$key] = $r['unit_type'];
        $same = $node !== null && (int) ($node->parent_id ?? 0) === (int) ($parentNode?->getKey() ?? 0) && ($parent === null || $parentNode !== null);

        return match (true) {
            $same => ['action' => 'skip', 'match' => ['id' => $node->getKey(), 'reason' => 'Already sits there.']],
            $node === null => ['action' => 'create', 'match' => ['unit' => $this->label2($unit)]],
            default => ['action' => 'update', 'match' => ['id' => $node->getKey(), 'unit' => $this->label2($unit)]],
        };
    }

    protected function apply(array $r, EmployeeImportRow $row, EmployeeImport $import, User $user): string
    {
        $context = ['seen' => [], 'placed' => []];
        [$unit, $node, $parent, $parentNode, $errors] = $this->resolve($r, $context, $row, running: true);
        if ($errors !== []) {
            throw new RuntimeException(implode(' ', $errors));
        }
        $tree = app(OrganisationTree::class);
        $reason = $this->label()." #{$import->getKey()} ({$import->original_name})";
        try {
            if ($node === null) {
                $tree->attach($unit, $parentNode, $reason);

                return 'Placed '.$this->label2($unit).($parent ? ' under '.$this->label2($parent) : ' at the top');
            }
            if ((int) ($node->parent_id ?? 0) === (int) ($parentNode?->getKey() ?? 0)) {
                return 'Already sits there';
            }
            $tree->move($node, $parentNode, $reason);
        } catch (InvalidHierarchyException $e) {
            throw new RuntimeException($e->getMessage());
        }

        return 'Moved '.$this->label2($unit).($parent ? ' under '.$this->label2($parent) : ' to the top');
    }

    /**
     * The unit and its node, the parent and its node, and what is wrong. While the file is checked, a parent placed by
     * an earlier row of the same file counts as placed.
     *
     * @return array{0: ?Model, 1: ?OrganisationNode, 2: ?Model, 3: ?OrganisationNode, 4: list<string>}
     */
    private function resolve(array $r, array &$context, EmployeeImportRow $row, bool $running = false): array
    {
        $tree = app(OrganisationTree::class);
        $errors = [];
        $unitType = $this->type($r['unit_type'] ?? null, 'Unit type', $errors);
        $parentType = isset($r['parent_code']) ? $this->type($r['parent_type'] ?? null, 'Parent type', $errors) : null;
        if (isset($r['parent_code']) && ! isset($r['parent_type'])) {
            $errors[] = 'Parent type is required with a parent.';
        }
        if ($errors !== []) {
            return [null, null, null, null, $errors];
        }
        $unit = $this->unit($unitType, (string) $r['unit_code'], 'Unit', $row, $errors);
        $parent = isset($r['parent_code']) ? $this->unit((string) $parentType, (string) $r['parent_code'], 'Parent', $row, $errors) : null;
        if ($errors !== [] || $unit === null) {
            return [null, null, null, null, $errors];
        }
        $node = $this->nodeOf($unit);
        $parentNode = $parent === null ? null : $this->nodeOf($parent);
        if ($parent !== null && $parentNode === null && ! (! $running && isset($context['placed'][$this->key($parent)]))) {
            $errors[] = "The parent {$this->label2($parent)} is not in the organisation tree yet: list its own row above this one.";
        }
        if ($parent !== null && $parent->is($unit)) {
            $errors[] = 'A unit cannot sit under itself.';
        }
        $allowed = $parent === null
            ? array_keys(array_filter($tree->types(), fn (array $t) => $t['root']))
            : ($tree->types()[$parentType]['children'] ?? []);
        if (! in_array($unitType, $allowed, true)) {
            $label = $tree->types()[$unitType]['label'] ?? $unitType;
            $errors[] = "A {$label} cannot sit ".($parent === null ? 'at the top: give its parent.' : 'under a '.($tree->types()[$parentType]['label'] ?? $parentType).'.');
        }
        if ($node !== null && $parentNode !== null && ($parentNode->is($node) || $node->isAncestorOf($parentNode))) {
            $errors[] = 'A unit cannot be moved beneath itself or one of its own units.';
        }

        return [$unit, $node, $parent, $parentNode, $errors];
    }

    /** A node type by key or label (company, business unit, division, department, team, location). @param list<string> $errors */
    private function type(?string $value, string $what, array &$errors): ?string
    {
        $types = app(OrganisationTree::class)->types();
        $wanted = str_replace([' ', '-'], '_', mb_strtolower(trim((string) $value)));
        foreach ($types as $key => $type) {
            if ($wanted === $key || $wanted === str_replace([' ', '-'], '_', mb_strtolower($type['label'])) || ($wanted === 'branch' && $key === 'location')) {
                return $key;
            }
        }
        $errors[] = $value === null || trim($value) === '' ? "{$what} is required." : "{$what} '{$value}' is not one of: ".implode(', ', array_keys($types)).'.';

        return null;
    }

    /** A unit by its code, or its exact name (or "Name (CODE)") through the name resolver. @param list<string> $errors */
    private function unit(string $type, string $value, string $what, EmployeeImportRow $row, array &$errors): ?Model
    {
        $class = app(OrganisationTree::class)->modelForType($type);
        $found = $class::query()->withoutGlobalScope(AccessScope::class)->whereRaw('lower(code) = ?', [mb_strtolower(trim($value))])->first();
        if ($found !== null) {
            return $found;
        }
        $resolution = app(ImportReferences::class)->resolve($class, $value, null, null, (int) ($row->data[WorkbookImports::ROW_COLUMN] ?? $row->row_number + 1), $what);
        if ($resolution !== null && $resolution->ok() && (int) $resolution->id > 0) {
            return $class::query()->withoutGlobalScope(AccessScope::class)->find($resolution->id);
        }
        $label = app(OrganisationTree::class)->types()[$type]['label'] ?? $type;
        $errors[] = $resolution !== null && ImportReferences::needsReview($resolution) && $resolution->error() !== null
            ? $resolution->error() : "{$what} {$label} '{$value}' does not exist (add it with its own upload first).";

        return null;
    }

    private function nodeOf(Model $unit): ?OrganisationNode
    {
        return OrganisationNode::query()->where('nodeable_type', $unit::class)->where('nodeable_id', $unit->getKey())->first();
    }

    private function key(Model $unit): string
    {
        return $unit::class.'|'.$unit->getKey();
    }

    private function label2(Model $unit): string
    {
        return trim(($unit->getAttribute('name') ?? '').' ('.($unit->getAttribute('code') ?? '#'.$unit->getKey()).')');
    }

    public function format(): array
    {
        $types = implode(', ', array_keys(app(OrganisationTree::class)->types()));

        return [
            'columns' => [
                ['Unit type', 'Yes', "One of: {$types} (branch = location).", 'department'],
                ['Unit', 'Yes', ReferenceGuide::name('unit', null, 'It is looked for among units of its Unit type.'), 'Engineering'],
                ['Parent type', 'No', 'The type of the unit it sits under. Blank with a blank Parent: the top of the tree (companies only).', 'business_unit'],
                ['Parent', 'No', ReferenceGuide::name('parent unit', null, 'It is looked for among units of its Parent type.'), 'Technology'],
            ],
            'notes' => [
                'One row per unit. A unit not in the tree yet is placed; one already placed moves under the new parent; one already there is skipped.',
                'List parents before the units under them. The tree\'s rules apply: a team sits under a department, division, business unit, location or company, and so on.',
                'The units themselves are added by their own uploads (Companies, Business units, Divisions, Departments, Teams, Branches).',
                ReferenceGuide::NOTE,
            ],
        ];
    }

    protected function examples(): array
    {
        // The organisation's own company and a department of it, so the sample file runs as it is: the company at the
        // top of the tree, the department under it (already there: skipped).
        $company = Company::query()->withoutGlobalScope(AccessScope::class)->orderBy('id')->value('name') ?? 'Markedge Technologies';
        $department = Department::query()->withoutGlobalScope(AccessScope::class)->orderBy('id')->value('name') ?? 'Engineering';

        return [['company', $company, '', ''], ['department', $department, 'company', $company]];
    }
}
