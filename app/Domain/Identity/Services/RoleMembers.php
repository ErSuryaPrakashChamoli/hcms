<?php

namespace App\Domain\Identity\Services;

use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Audit\Services\AuditRecorder;
use App\Domain\Identity\Enums\UserStatus;
use App\Domain\Identity\Models\Role;
use App\Domain\Identity\Models\RoleMembershipRule;
use App\Domain\Identity\Models\User;
use App\Domain\Organisation\Models\Company;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * HROne setup parity (Automation → Role, employee view): who holds a role, changed from the role itself. The same rules
 * as the user form: `user.assign_roles`, the delegation ceiling (RoleGrants) for adding, users of this organisation
 * only, and every change audited as ROLE_CHANGED on the person (with the reason).
 */
final class RoleMembers
{
    public function __construct(private readonly RoleGrants $grants, private readonly TenantContext $tenants) {}

    public function add(Role $role, User $user, ?int $companyId, ?User $actor, ?string $reason = null): void
    {
        $this->assertMayChange($role, $user, $actor);
        $this->grants->assertCanAssign($actor, [$role]);
        if ($user->isPlatformAdmin() || ! in_array($user->status, [UserStatus::Active, UserStatus::Invited], true)) {
            throw new RuntimeException('Only active or invited users of this organisation can be given a role.');
        }
        if ($companyId !== null && ! Company::query()->whereKey($companyId)->exists()) {
            throw new RuntimeException('Choose a company of this organisation.');
        }

        DB::transaction(function () use ($role, $user, $companyId, $actor, $reason): void {
            $existing = DB::table('role_user')->where('role_id', $role->getKey())->where('user_id', $user->getKey())
                ->when($companyId === null, fn ($q) => $q->whereNull('company_id'), fn ($q) => $q->where('company_id', $companyId))->get(['id', 'source']);
            if ($existing->contains(fn ($row) => $row->source !== 'rule')) {
                throw new RuntimeException("{$user->name} already holds this role".($companyId === null ? '.' : ' for that company.'));
            }
            if ($existing->isNotEmpty()) {
                // HROne parity SV-7: held through a membership rule; given by hand now, it no longer depends on the rule.
                DB::table('role_user')->whereIn('id', $existing->pluck('id')->all())->update(['source' => 'manual', 'membership_rule_id' => null, 'updated_at' => now()]);
                $user->flushPermissionCache();
                app(AuditRecorder::class)->record(AuditAction::RoleChanged, 'identity', $user, [], $reason, actor: $actor, metadata: [
                    'granted' => [$role->slug], 'removed' => [], 'via' => 'role_members', 'was' => 'membership_rule',
                ]);

                return;
            }
            $user->roles()->attach($role->getKey(), ['company_id' => $companyId]);
            $user->flushPermissionCache();
            // Audited as the user form does (ROLE_CHANGED on the person), with the company when the grant is limited to one.
            app(AuditRecorder::class)->record(AuditAction::RoleChanged, 'identity', $user, [], $reason, actor: $actor, metadata: array_filter([
                'granted' => [$role->slug], 'removed' => [], 'company_id' => $companyId, 'via' => 'role_members',
            ], fn ($v) => $v !== null));
        });
    }

    public function remove(Role $role, User $user, ?User $actor, ?string $reason = null): void
    {
        $this->assertMayChange($role, $user, $actor);

        DB::transaction(function () use ($role, $user, $actor, $reason): void {
            // HROne parity SV-7: only grants given by hand are removed here; a grant made by a membership rule follows the rule.
            $rows = DB::table('role_user')->where('role_id', $role->getKey())->where('user_id', $user->getKey())->get(['id', 'source', 'membership_rule_id']);
            $manual = $rows->filter(fn ($row) => $row->source !== 'rule');
            if ($manual->isEmpty() && $rows->isNotEmpty()) {
                $rule = RoleMembershipRule::query()->find($rows->first()->membership_rule_id);
                throw new RuntimeException("{$user->name} holds this role through the membership rule".($rule ? " \"{$rule->name}\"" : '').': change the rule or switch it off instead.');
            }
            $before = $user->roles()->pluck('roles.id')->map(fn ($id) => (int) $id)->all();
            DB::table('role_user')->whereIn('id', $manual->pluck('id')->all())->delete();
            if ($rows->count() > $manual->count()) {
                // The hand-given grant is gone; the person keeps the role through its membership rule (said so in the audit).
                $user->flushPermissionCache();
                app(AuditRecorder::class)->record(AuditAction::RoleChanged, 'identity', $user, [], $reason, actor: $actor, metadata: [
                    'granted' => [], 'removed' => [$role->slug], 'via' => 'role_members', 'still_held_by' => 'membership_rule',
                ]);

                return;
            }
            $this->grants->recordChange($user, $before, $actor, $reason);
            $user->flushPermissionCache();
        });
    }

    /**
     * HROne parity SV-7: the user form's roles are the grants given by hand. Saving the form sets those (roles left out are
     * removed for every company, as before; roles added are given organisation-wide) and never touches a grant made by a
     * membership rule. A role the person holds only through a rule, ticked on the form, becomes a hand-given grant. Who may
     * give which role (RoleGrants) is checked by the form; the change is audited by the page (RoleGrants::recordChange),
     * a rule grant taken over by hand here.
     *
     * @param  list<int|string>  $roleIds
     */
    public function syncManual(User $user, array $roleIds, ?User $actor = null, ?string $reason = null): void
    {
        $roleIds = array_values(array_unique(array_map('intval', $roleIds)));
        $rows = DB::table('role_user')->where('user_id', $user->getKey())->get(['id', 'role_id', 'company_id', 'source']);
        $manual = $rows->filter(fn ($row) => $row->source !== 'rule');
        DB::table('role_user')->whereIn('id', $manual->reject(fn ($row) => in_array((int) $row->role_id, $roleIds, true))->pluck('id')->all())->delete();

        $heldByHand = $manual->pluck('role_id')->map(fn ($id) => (int) $id)->all();
        $taken = [];
        foreach (array_diff($roleIds, $heldByHand) as $roleId) {
            $ruleRow = $rows->first(fn ($row) => $row->source === 'rule' && (int) $row->role_id === $roleId && $row->company_id === null);
            if ($ruleRow !== null) {
                DB::table('role_user')->where('id', $ruleRow->id)->update(['source' => 'manual', 'membership_rule_id' => null, 'updated_at' => now()]);
                $taken[] = $roleId;

                continue;
            }
            $user->roles()->attach($roleId, ['company_id' => null]);
        }
        $user->flushPermissionCache();
        $user->unsetRelation('roles');
        if ($taken !== []) {
            app(AuditRecorder::class)->record(AuditAction::RoleChanged, 'identity', $user, [], $reason, actor: $actor, metadata: [
                'granted' => Role::query()->whereIn('id', $taken)->pluck('slug')->all(), 'removed' => [], 'via' => 'user_form', 'was' => 'membership_rule',
            ]);
        }
    }

    /** @return list<int> the role ids the user holds by hand (any company) */
    public function manualRoleIds(User $user): array
    {
        return DB::table('role_user')->where('user_id', $user->getKey())->where('source', '!=', 'rule')->pluck('role_id')->map(fn ($id) => (int) $id)->unique()->values()->all();
    }

    /** @return array<int, string> role name => the rule that grants it, for the roles the user holds through a rule */
    public function ruleGrants(User $user): array
    {
        return DB::table('role_user')->join('roles', 'roles.id', '=', 'role_user.role_id')
            ->leftJoin('role_membership_rules', 'role_membership_rules.id', '=', 'role_user.membership_rule_id')
            ->where('role_user.user_id', $user->getKey())->where('role_user.source', 'rule')->where('roles.tenant_id', $this->tenants->id() ?? 0)
            ->orderBy('roles.name')->get(['roles.name as role_name', 'role_membership_rules.name as rule_name'])
            ->mapWithKeys(fn ($row) => [(string) $row->role_name => (string) ($row->rule_name ?? 'a rule no longer in force')])->all();
    }

    private function assertMayChange(Role $role, User $user, ?User $actor): void
    {
        if ($actor !== null && ! $actor->hasPermission('user.assign_roles')) {
            throw new RuntimeException('You cannot change who holds roles.');
        }
        $tenantId = $this->tenants->id();
        if ($tenantId === null || (int) $role->tenant_id !== $tenantId || (int) $user->tenant_id !== $tenantId) {
            throw new RuntimeException('This user belongs to another organisation.');
        }
    }
}
