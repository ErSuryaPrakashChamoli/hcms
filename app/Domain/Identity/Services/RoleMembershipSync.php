<?php

namespace App\Domain\Identity\Services;

use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Audit\Services\AuditRecorder;
use App\Domain\Configuration\Services\EmployeeRuleContext;
use App\Domain\Configuration\Services\RuleEngine;
use App\Domain\Employment\Models\Employee;
use App\Domain\Identity\Enums\UserStatus;
use App\Domain\Identity\Models\Role;
use App\Domain\Identity\Models\RoleMembershipRule;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Scopes\AccessScope;
use App\Domain\Lifecycle\Enums\LifecycleState;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * HROne parity SV-7: applies the role membership rules of the current tenant. The only writer of role_user rows tagged
 * source = rule, and it never touches any other row:
 *
 * - A rule grants its role (organisation-wide, no company limit) to every employed person (lifecycle isEmployed) with an
 *   active or invited account of this organisation who matches it today, unless they already hold the role organisation-
 *   wide by hand. It removes its grant from anyone who no longer matches (moved, left, account suspended), and a grant
 *   whose rule is gone, switched off, malformed or for a role no rule may grant (RoleGrants::ruleLocked) is removed too:
 *   fail closed. When two rules of a role match, the grant stays with one of them.
 * - Each grant and removal is audited ROLE_CHANGED on the person with the rule that caused it (one bulk operation per run).
 * - Runs for one employee (after a hire, move or exit), one role (after a rule changed) or everyone (nightly), with no
 *   access scope (every employee of the tenant is considered), serialised per tenant.
 */
final class RoleMembershipSync
{
    public function __construct(
        private readonly TenantContext $tenants,
        private readonly RuleEngine $engine,
        private readonly EmployeeRuleContext $context,
        private readonly RoleGrants $grants,
    ) {}

    /**
     * @param  list<int>|null  $employeeIds  only these employees (null = everyone)
     * @return array{granted: int, removed: int, reassigned: int}
     */
    public function run(?array $employeeIds = null, ?int $roleId = null): array
    {
        $tenantId = $this->tenants->id();
        $none = ['granted' => 0, 'removed' => 0, 'reassigned' => 0];
        if ($tenantId === null) {
            return $none;
        }

        return Cache::lock("role-membership-sync:{$tenantId}", 600)->block(120, fn () => AccessScope::withoutScoping(fn () => $this->apply($tenantId, $employeeIds, $roleId)));
    }

    /** Whether the tenant has any rule (cheap: listeners skip tenants without rules). */
    public function hasRules(): bool
    {
        return $this->tenants->id() !== null && RoleMembershipRule::query()->exists();
    }

    /**
     * The users the conditions match today (for a preview).
     *
     * @param  list<array<string, mixed>>  $conditions
     * @return list<int>
     */
    public function matchingUserIds(array $conditions, string $match): array
    {
        return AccessScope::withoutScoping(function () use ($conditions, $match) {
            $ids = [];
            foreach ($this->eligible(null) as $employee) {
                if ($this->engine->matches($conditions, $this->context->build($employee), $match)) {
                    $ids[] = (int) $employee->user_id;
                }
            }

            return array_values(array_unique($ids));
        });
    }

    /**
     * @param  list<int>|null  $employeeIds
     * @return array{granted: int, removed: int, reassigned: int}
     */
    private function apply(int $tenantId, ?array $employeeIds, ?int $roleId): array
    {
        $counts = ['granted' => 0, 'removed' => 0, 'reassigned' => 0];
        $allRules = RoleMembershipRule::query()->when($roleId !== null, fn ($q) => $q->where('role_id', $roleId))->orderBy('id')->get();
        $roles = Role::query()->whereIn('id', $allRules->pluck('role_id')->unique()->all())->get()->keyBy('id');
        $valid = $allRules->filter(fn (RoleMembershipRule $rule) => $this->usable($rule, $roles[$rule->role_id] ?? null))->groupBy('role_id');

        // Who should hold what: role id => user id => the first matching rule.
        $desired = [];
        if ($valid->isNotEmpty()) {
            foreach ($this->eligible($employeeIds) as $employee) {
                $context = $this->context->build($employee);
                foreach ($valid as $rid => $rules) {
                    foreach ($rules as $rule) {
                        if ($this->engine->matches((array) $rule->conditions, $context, (string) $rule->match)) {
                            $desired[(int) $rid][(int) $employee->user_id] ??= $rule;
                            break;
                        }
                    }
                }
            }
        }

        // The rule grants in reach of this run.
        $userIds = $employeeIds === null ? null : Employee::query()->withoutGlobalScope(AccessScope::class)->whereKey($employeeIds)->whereNotNull('user_id')->pluck('user_id')->map(fn ($id) => (int) $id)->all();
        $rows = DB::table('role_user')->join('roles', 'roles.id', '=', 'role_user.role_id')
            ->where('roles.tenant_id', $tenantId)->where('role_user.source', 'rule')
            ->when($roleId !== null, fn ($q) => $q->where('role_user.role_id', $roleId))
            ->when($userIds !== null, fn ($q) => $q->whereIn('role_user.user_id', $userIds))
            ->get(['role_user.id', 'role_user.role_id', 'role_user.user_id', 'role_user.membership_rule_id']);

        $plan = ['remove' => [], 'reassign' => [], 'grant' => []];
        foreach ($rows as $row) {
            $want = $desired[(int) $row->role_id][(int) $row->user_id] ?? null;
            if ($want === null) {
                $plan['remove'][] = $row;
            } elseif ((int) $row->membership_rule_id !== (int) $want->getKey()) {
                $plan['reassign'][] = [$row, $want];
            }
            unset($desired[(int) $row->role_id][(int) $row->user_id]);
        }
        $manual = DB::table('role_user')->whereIn('role_id', array_keys($desired))->whereNull('company_id')->where('source', 'manual')
            ->get(['role_id', 'user_id'])->map(fn ($r) => $r->role_id.':'.$r->user_id)->flip();
        foreach ($desired as $rid => $users) {
            foreach ($users as $uid => $rule) {
                if (! isset($manual[$rid.':'.$uid])) {
                    $plan['grant'][] = [$rid, $uid, $rule];
                }
            }
        }

        if ($plan['remove'] !== [] || $plan['reassign'] !== [] || $plan['grant'] !== []) {
            $counts = $this->execute($plan, $allRules->keyBy('id'), $tenantId);
        }
        if ($allRules->isNotEmpty()) {
            DB::table('role_membership_rules')->whereIn('id', $allRules->pluck('id')->all())->update(['last_synced_at' => now()]);
        }

        return $counts;
    }

    /**
     * @param  array{remove: list<object>, reassign: list<array{0: object, 1: RoleMembershipRule}>, grant: list<array{0: int, 1: int, 2: RoleMembershipRule}>}  $plan
     * @param  Collection<int, RoleMembershipRule>  $rules
     * @return array{granted: int, removed: int, reassigned: int}
     */
    private function execute(array $plan, Collection $rules, int $tenantId): array
    {
        $counts = ['granted' => 0, 'removed' => 0, 'reassigned' => 0];
        $userIds = array_unique([...array_map(fn ($r) => (int) $r->user_id, $plan['remove']), ...array_map(fn ($p) => (int) $p[0]->user_id, $plan['reassign']), ...array_map(fn ($g) => $g[1], $plan['grant'])]);
        $users = User::query()->where('tenant_id', $tenantId)->whereKey($userIds)->get()->keyBy('id');
        $slugs = Role::query()->pluck('slug', 'id');
        $audit = app(AuditRecorder::class);
        $record = function (int $userId, array $metadata, string $reason) use ($users, $audit): void {
            if (($user = $users[$userId] ?? null) !== null) {
                $audit->record(AuditAction::RoleChanged, 'identity', $user, [], $reason, metadata: $metadata + ['via' => 'membership_rule']);
            }
        };

        $audit->operation('identity', 'Role membership rules applied', function () use ($plan, $rules, $slugs, $record, &$counts) {
            DB::transaction(function () use ($plan, $rules, $slugs, $record, &$counts): void {
                foreach ($plan['remove'] as $row) {
                    DB::table('role_user')->where('id', $row->id)->where('source', 'rule')->delete();
                    $rule = $rules[(int) $row->membership_rule_id] ?? null;
                    $record((int) $row->user_id, ['granted' => [], 'removed' => [$slugs[$row->role_id] ?? (string) $row->role_id], 'rule_id' => $rule?->getKey(), 'rule' => $rule?->name],
                        $rule === null ? 'Role membership rule no longer in force' : "Role membership rule \"{$rule->name}\" no longer applies");
                    $counts['removed']++;
                }
                foreach ($plan['reassign'] as [$row, $rule]) {
                    DB::table('role_user')->where('id', $row->id)->where('source', 'rule')->update(['membership_rule_id' => $rule->getKey(), 'updated_at' => now()]);
                    $record((int) $row->user_id, ['granted' => [], 'removed' => [], 'kept' => [$slugs[$row->role_id] ?? (string) $row->role_id], 'rule_id' => $rule->getKey(), 'rule' => $rule->name],
                        "Role kept through membership rule \"{$rule->name}\"");
                    $counts['reassigned']++;
                }
                foreach ($plan['grant'] as [$roleId, $userId, $rule]) {
                    DB::table('role_user')->insert(['role_id' => $roleId, 'user_id' => $userId, 'company_id' => null, 'source' => 'rule',
                        'membership_rule_id' => $rule->getKey(), 'created_at' => now(), 'updated_at' => now()]);
                    $record($userId, ['granted' => [$slugs[$roleId] ?? (string) $roleId], 'removed' => [], 'rule_id' => $rule->getKey(), 'rule' => $rule->name],
                        "Role membership rule \"{$rule->name}\"");
                    $counts['granted']++;
                }
            });

            return $counts['granted'] + $counts['removed'] + $counts['reassigned'];
        }, 'Role membership rules', 'role_user');

        return $counts;
    }

    /** A rule that may grant now: active, well formed, for an existing role of this tenant that no rule is barred from. */
    private function usable(RoleMembershipRule $rule, ?Role $role): bool
    {
        return $rule->isActive() && $role !== null && (int) $role->tenant_id === (int) $rule->tenant_id
            && RoleMembershipRules::problems($rule->conditions, $rule->getAttributes()['match'] ?? null) === []
            && ! $this->grants->ruleLocked($role);
    }

    /**
     * Employed people with an account of this tenant that can sign in (active or invited, not an operator).
     *
     * @param  list<int>|null  $employeeIds
     * @return iterable<Employee>
     */
    private function eligible(?array $employeeIds): iterable
    {
        $employed = array_map(fn (LifecycleState $s) => $s->value, array_filter(LifecycleState::cases(), fn (LifecycleState $s) => $s->isEmployed()));
        $accounts = User::forCurrentTenant()->where('is_platform_admin', false)->whereIn('status', [UserStatus::Active->value, UserStatus::Invited->value])->select('id');

        return Employee::query()->withoutGlobalScope(AccessScope::class)->with('person')
            ->whereIn('lifecycle_state', $employed)->whereIn('user_id', $accounts)
            ->when($employeeIds !== null, fn ($q) => $q->whereKey($employeeIds))
            ->orderBy('id')->lazy(500);
    }
}
