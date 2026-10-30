<?php

namespace App\Domain\Organisation\Services;

use App\Domain\Identity\Scopes\AccessScope;
use App\Domain\Organisation\Exceptions\InvalidHierarchyException;
use App\Domain\Organisation\Models\Department;
use App\Domain\Organisation\Models\OrganisationNode;

/**
 * HROne setup parity CA-2 (Organization → Sub department): a department may sit under one parent department. The
 * department form and the organisation designer stay in step: setting a parent moves the department's node under the
 * parent's node (when both are in the tree), and moving a department node in the designer sets or clears its parent.
 */
final class DepartmentHierarchy
{
    /** Refuses a parent that is the department itself or one of its sub-departments. */
    public function assertParent(Department $department, ?int $parentId): void
    {
        if ($parentId === null) {
            return;
        }
        $seen = [];
        $current = $parentId;
        while ($current !== null && ! isset($seen[$current])) {
            if ($department->exists && $current === (int) $department->getKey()) {
                throw new InvalidHierarchyException('A department cannot sit under itself or one of its sub-departments.');
            }
            $seen[$current] = true;
            $next = Department::query()->withoutGlobalScope(AccessScope::class)->whereKey($current)->value('parent_department_id');
            $current = $next === null ? null : (int) $next;
        }
    }

    /** After a parent change on the form: place the department's node under the parent department's node. */
    public function syncNode(Department $department): void
    {
        $parentId = $department->getAttributes()['parent_department_id'] ?? null;
        if ($parentId === null) {
            return;
        }
        $node = OrganisationNode::query()->where('nodeable_type', Department::class)->where('nodeable_id', $department->getKey())->first();
        $parentNode = OrganisationNode::query()->where('nodeable_type', Department::class)->where('nodeable_id', $parentId)->first();
        if ($node === null || $parentNode === null || (int) $node->parent_id === (int) $parentNode->getKey()) {
            return;
        }
        app(OrganisationTree::class)->move($node, $parentNode, 'Parent department changed on the department');
    }

    /** After a designer move: a department under a department takes it as parent; anywhere else it has none. */
    public function followNode(OrganisationNode $node, ?string $reason = null): void
    {
        if ($node->nodeable_type !== Department::class) {
            return;
        }
        $department = Department::query()->withoutGlobalScope(AccessScope::class)->find($node->nodeable_id);
        if ($department === null) {
            return;
        }
        $parent = $node->parent_id === null ? null : OrganisationNode::query()->find($node->parent_id);
        $parentId = $parent !== null && $parent->nodeable_type === Department::class ? (int) $parent->nodeable_id : null;
        if ((int) ($department->getAttributes()['parent_department_id'] ?? 0) !== (int) ($parentId ?? 0)) {
            $department->withAuditReason($reason ?? 'Moved in the organisation designer')->update(['parent_department_id' => $parentId]);
        }
    }
}
