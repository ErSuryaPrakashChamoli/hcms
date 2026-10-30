<?php

namespace App\Domain\Identity\Services;

use App\Domain\Employment\Models\Employee;
use App\Domain\Identity\Models\Role;
use App\Domain\Identity\Models\RoleMembershipRule;
use App\Domain\Identity\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * HROne parity SV-7 (Automation → Role, "Select role organization wise"): role membership rules are written only here.
 *
 * - `user.assign_roles` and a reason for every change; the rule is audited (Auditable) with the reason.
 * - The delegation ceiling applies to rule authors (RoleGrants::canAssignByRule): never Tenant Super Admin, Account Owner
 *   or a role with a platform permission; below the unrestricted, only roles whose permissions the author holds — checked
 *   when a rule is written, changed or switched on. Switching a rule off or deleting it only removes what it granted.
 * - Conditions are the policy rule fields (peopleos.rules.fields) and must be well formed: a rule with no condition, an
 *   unknown field or operator, or a malformed value is refused here and matches nobody if stored anyway.
 * - Every change is applied at once (RoleMembershipSync for the role).
 */
final class RoleMembershipRules
{
    public function __construct(private readonly RoleGrants $grants, private readonly RoleMembershipSync $sync, private readonly TenantContext $tenants) {}

    /**
     * @param  array{name: string, conditions: list<array<string, mixed>>, match?: string, status?: string, description?: ?string}  $data
     */
    public function save(Role $role, ?RoleMembershipRule $rule, array $data, ?User $actor, string $reason): RoleMembershipRule
    {
        $this->assertMay($actor, $reason);
        $tenantId = $this->tenants->id();
        if ($tenantId === null || (int) $role->tenant_id !== $tenantId || ($rule !== null && ((int) $rule->tenant_id !== $tenantId || (int) $rule->role_id !== (int) $role->getKey()))) {
            throw new RuntimeException('This role belongs to another organisation.');
        }
        $this->assertMayAuthor($actor, $role);

        $name = trim((string) ($data['name'] ?? ''));
        $match = (string) ($data['match'] ?? 'all');
        $status = (string) ($data['status'] ?? 'active');
        $conditions = array_values((array) ($data['conditions'] ?? []));
        if ($name === '' || mb_strlen($name) > 120) {
            throw new RuntimeException('Give the rule a name (at most 120 characters).');
        }
        if (! array_key_exists($status, RoleMembershipRule::STATUSES)) {
            throw new RuntimeException('Choose the rule\'s status.');
        }
        $conditions = self::normalise($conditions);
        if (($problem = self::problems($conditions, $match)[0] ?? null) !== null) {
            throw new RuntimeException($problem);
        }
        $this->assertValuesVisible($conditions);

        $rule ??= new RoleMembershipRule(['role_id' => $role->getKey(), 'created_by' => $actor?->getKey()]);
        $rule->fill(['name' => $name, 'conditions' => $conditions, 'match' => $match, 'status' => $status,
            'description' => filled($data['description'] ?? null) ? (string) $data['description'] : null, 'updated_by' => $actor?->getKey()])
            ->withAuditReason($reason)->save();

        $this->sync->run(roleId: (int) $role->getKey());

        return $rule->refresh();
    }

    /** Switch a rule on (the author's ceiling applies, as for a new rule) or off (its grants are removed). */
    public function setStatus(RoleMembershipRule $rule, string $status, ?User $actor, string $reason): void
    {
        $this->assertMay($actor, $reason);
        if (! array_key_exists($status, RoleMembershipRule::STATUSES)) {
            throw new RuntimeException('Choose the rule\'s status.');
        }
        $role = Role::query()->findOrFail($rule->role_id);
        if ($status === 'active') {
            $this->assertMayAuthor($actor, $role);
            if (($problem = self::problems((array) $rule->conditions, (string) $rule->match)[0] ?? null) !== null) {
                throw new RuntimeException($problem);
            }
        }
        $rule->forceFill(['status' => $status, 'updated_by' => $actor?->getKey()])->withAuditReason($reason)->save();
        $this->sync->run(roleId: (int) $role->getKey());
    }

    /** Delete a rule: switched off first (its grants removed, each audited with the rule), then deleted. */
    public function delete(RoleMembershipRule $rule, ?User $actor, string $reason): void
    {
        $this->assertMay($actor, $reason);
        $roleId = (int) $rule->role_id;
        if ($rule->isActive()) {
            $rule->forceFill(['status' => 'inactive', 'updated_by' => $actor?->getKey()])->withAuditReason($reason)->save();
            $this->sync->run(roleId: $roleId);
        }
        DB::transaction(fn () => $rule->withAuditReason($reason)->delete());
        // Anything still tagged with the deleted rule (none expected) is removed by the next run.
        $this->sync->run(roleId: $roleId);
    }

    /**
     * How many people the conditions match now (employed, with an account of this organisation that can sign in).
     *
     * @param  list<array<string, mixed>>  $conditions
     */
    public function preview(array $conditions, string $match = 'all'): int
    {
        $conditions = self::normalise($conditions);
        if (self::problems($conditions, $match) !== []) {
            return 0;
        }

        return count($this->sync->matchingUserIds($conditions, $match));
    }

    /** Whether $actor may write (or switch on) a rule for $role; refuses with the reason. */
    public function assertMayAuthor(?User $actor, Role $role): void
    {
        if ($this->grants->ruleLocked($role)) {
            throw new RuntimeException("No membership rule may grant the role \"{$role->name}\": give it to people one by one.");
        }
        if (! $this->grants->canAssignByRule($actor, $role)) {
            throw new RuntimeException("You cannot write a membership rule for the role \"{$role->name}\": it grants permissions you do not hold yourself.");
        }
    }

    /**
     * Form state (a repeater of field / operator / value) as stored conditions.
     *
     * @param  list<mixed>  $conditions
     * @return list<array<string, mixed>>
     */
    public static function normalise(array $conditions): array
    {
        return array_values(array_map(function ($c) {
            if (! is_array($c)) {
                return $c;
            }
            $out = ['field' => $c['field'] ?? null, 'operator' => $c['operator'] ?? null];
            if (array_key_exists('value', $c) && ! in_array($c['operator'] ?? null, ['is_empty', 'is_not_empty'], true)) {
                $out['value'] = $c['value'];
            }

            return $out;
        }, $conditions));
    }

    /**
     * Why stored conditions cannot be applied (empty when they can). Fail closed: anything not understood is a problem.
     *
     * @return list<string>
     */
    public static function problems(mixed $conditions, mixed $match = 'all'): array
    {
        $fields = (array) config('peopleos.rules.fields', []);
        $operators = (array) config('peopleos.rules.operators', []);
        if (! in_array($match, ['all', 'any'], true)) {
            return ['Choose whether every condition or any condition must match.'];
        }
        if (! is_array($conditions) || $conditions === [] || ! array_is_list($conditions)) {
            return ['A rule needs at least one condition (a rule for everyone is not allowed).'];
        }
        foreach ($conditions as $c) {
            if (! is_array($c) || ! is_string($c['field'] ?? null) || ! array_key_exists($c['field'], $fields)) {
                return ['A condition names a field that does not exist.'];
            }
            $operator = $c['operator'] ?? null;
            if (! is_string($operator) || ! array_key_exists($operator, $operators)) {
                return ['A condition has an operator that does not exist.'];
            }
            if (in_array($operator, ['is_empty', 'is_not_empty'], true)) {
                continue;
            }
            $value = $c['value'] ?? null;
            $list = in_array($operator, ['in', 'not_in'], true);
            $values = $list ? $value : [$value];
            if (! is_array($values) || $values === [] || ($list && ! array_is_list($values))) {
                return ['A condition has no value.'];
            }
            foreach ($values as $v) {
                if (! is_scalar($v) || $v === '' || (in_array($fields[$c['field']]['type'] ?? null, ['model', 'employee'], true) && ! ctype_digit((string) $v))) {
                    return ['A condition has a value that is not valid.'];
                }
            }
        }

        return [];
    }

    private function assertMay(?User $actor, string $reason): void
    {
        if ($actor !== null && ! $actor->hasPermission('user.assign_roles')) {
            throw new RuntimeException('You cannot change who holds roles.');
        }
        if (trim($reason) === '') {
            throw new RuntimeException('Give the reason for the change.');
        }
    }

    /**
     * Ids in model and employee conditions must be ones the person writing the rule can see (their access scope applies).
     *
     * @param  list<array<string, mixed>>  $conditions
     */
    private function assertValuesVisible(array $conditions): void
    {
        $fields = (array) config('peopleos.rules.fields', []);
        foreach ($conditions as $c) {
            $definition = $fields[$c['field']] ?? [];
            if (! array_key_exists('value', $c) || ! in_array($definition['type'] ?? null, ['model', 'employee'], true)) {
                continue;
            }
            $ids = array_values(array_unique(array_map('intval', (array) $c['value'])));
            $query = ($definition['type'] === 'employee' ? Employee::class : $definition['model'])::query();
            if ($query->whereKey($ids)->count() !== count($ids)) {
                throw new RuntimeException('A condition names something you cannot see.');
            }
        }
    }
}
