<?php

use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Audit\Models\AuditEvent;
use App\Domain\Employment\Actions\AssignPositionAction;
use App\Domain\Employment\Actions\HireEmployeeAction;
use App\Domain\Employment\Models\Employee;
use App\Domain\Identity\Enums\UserStatus;
use App\Domain\Identity\Jobs\SyncRoleMemberships;
use App\Domain\Identity\Models\Role;
use App\Domain\Identity\Models\RoleMembershipRule;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Models\UserAccessScope;
use App\Domain\Identity\Services\AccessScopes;
use App\Domain\Identity\Services\PermissionRegistry;
use App\Domain\Identity\Services\RoleGrants;
use App\Domain\Identity\Services\RoleMembers;
use App\Domain\Identity\Services\RoleMembershipRules;
use App\Domain\Identity\Services\RoleMembershipSync;
use App\Domain\Lifecycle\Enums\LifecycleState;
use App\Domain\Organisation\Models\Company;
use App\Domain\Organisation\Models\Department;
use App\Domain\Organisation\Models\Team;
use App\Domain\Platform\Actions\ProvisionTenantAction;
use App\Filament\Resources\Roles\Pages\EditRole;
use App\Filament\Resources\Roles\RelationManagers\MembershipRulesRelationManager;
use App\Filament\Resources\Roles\RelationManagers\MembersRelationManager;
use App\Filament\Resources\Roles\RoleResource;
use App\Filament\Resources\Users\Pages\EditUser;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

/*
 | HROne parity SV-7 (AU-1, AR-1): role membership by organisation rule. A rule grants and removes only the role_user rows
 | it made itself (source = rule), each audited with the rule; hand-given grants are never touched; the delegation ceiling
 | applies to rule authors and locked roles are never granted; malformed rules match nobody; tenants stay apart; no rule =
 | exactly today's role assignments.
 */

beforeEach(function () {
    $this->travelTo('2026-11-10 10:00:00');
    $this->tenant = provisionTenant('Rules A');
    $this->otherTenant = provisionTenant('Rules B');
    actAsTenant($this->tenant);
    Filament::getPanel('admin')->boot();
    $this->admin = tenantUser($this->tenant, ['*']);
    $this->actingAs($this->admin);

    $this->company = Company::factory()->create(['name' => 'Alpha']);
    $this->finance = Department::factory()->create(['company_id' => $this->company->id, 'name' => 'Finance']);
    $this->ops = Department::factory()->create(['company_id' => $this->company->id, 'name' => 'Ops']);
    $this->approver = Role::factory()->create(['name' => 'Finance approver', 'slug' => 'finance-approver']);
    $this->approver->permissions()->sync(app(PermissionRegistry::class)->idsMatching(['expense.view', 'expense.approve']));

    $this->fin1 = sv7Hire('Farah', $this->finance);
    $this->fin2 = sv7Hire('Fred', $this->finance);
    $this->op1 = sv7Hire('Olga', $this->ops);
});

function sv7Hire(string $first, Department $department, ?User $user = null): Employee
{
    $user ??= tenantUser(test()->tenant, [], ['name' => $first.' Test']);

    return app(HireEmployeeAction::class)->handle(['first_name' => $first, 'last_name' => 'Test'], ['joining_date' => '2025-01-01', 'user_id' => $user->id],
        ['company_id' => $department->company_id, 'department_id' => $department->id])->load('user');
}

function sv7Rule(Role $role, array $conditions, ?User $actor = null, string $name = 'Finance team', string $status = 'active'): RoleMembershipRule
{
    return app(RoleMembershipRules::class)->save($role, null, ['name' => $name, 'conditions' => $conditions, 'status' => $status], $actor ?? test()->admin, 'Finance approvers by department');
}

/** @return array<int, string> user id => source, for a role */
function sv7Holders(Role $role): array
{
    return DB::table('role_user')->where('role_id', $role->id)->orderBy('user_id')->pluck('source', 'user_id')->map(fn ($s) => (string) $s)->all();
}

function sv7Department(Department $department): array
{
    return [['field' => 'department_id', 'operator' => 'in', 'value' => [(string) $department->id]]];
}

it('changes nothing and queues nothing while a tenant has no rules', function () {
    $before = DB::table('role_user')->orderBy('id')->get()->map(fn ($r) => (array) $r)->all();
    Queue::fake();
    sv7Hire('Nina', $this->finance);
    app(AssignPositionAction::class)->handle($this->op1, ['department_id' => $this->finance->id], 'transfer', '2026-11-10', 'Move');
    Queue::assertNotPushed(SyncRoleMemberships::class);

    $this->artisan('peopleos:access:role-rules')->assertSuccessful();
    $after = DB::table('role_user')->orderBy('id')->get()->map(fn ($r) => (array) $r)->all();
    expect(array_slice($after, 0, count($before)))->toBe($before)
        ->and(collect($after)->pluck('source')->unique()->values()->all())->toBe(['manual'])
        ->and(collect($after)->pluck('membership_rule_id')->filter()->all())->toBe([])
        ->and(app(RoleMembershipSync::class)->run())->toBe(['granted' => 0, 'removed' => 0, 'reassigned' => 0]);
});

it('grants the role to everyone the rule matches, audited with the rule, and takes it back when they no longer match', function () {
    $rule = sv7Rule($this->approver, sv7Department($this->finance));
    expect(sv7Holders($this->approver))->toBe([$this->fin1->user_id => 'rule', $this->fin2->user_id => 'rule'])
        ->and(DB::table('role_user')->where('role_id', $this->approver->id)->pluck('membership_rule_id')->unique()->all())->toBe([$rule->id])
        ->and($this->fin1->user->fresh()->hasPermission('expense.approve'))->toBeTrue()
        ->and($this->op1->user->fresh()->hasPermission('expense.approve'))->toBeFalse();
    $audit = AuditEvent::query()->withoutGlobalScopes()->where('tenant_id', $this->tenant->id)->where('action', AuditAction::RoleChanged->value)
        ->where('entity_id', $this->fin1->user_id)->latest('id')->first();
    expect($audit->metadata)->toMatchArray(['granted' => ['finance-approver'], 'via' => 'membership_rule', 'rule_id' => $rule->id, 'rule' => 'Finance team'])
        ->and($audit->reason)->toBe('Role membership rule "Finance team"');

    // Moves apply at once (queued after the change commits): out of Finance loses it, into Finance gains it.
    app(AssignPositionAction::class)->handle($this->fin2, ['department_id' => $this->ops->id], 'transfer', '2026-11-10', 'Moved to Ops');
    app(AssignPositionAction::class)->handle($this->op1, ['department_id' => $this->finance->id], 'transfer', '2026-11-10', 'Moved to Finance');
    expect(sv7Holders($this->approver))->toBe([$this->fin1->user_id => 'rule', $this->op1->user_id => 'rule']);
    // A new joiner who matches is given it.
    $joiner = sv7Hire('Jaya', $this->finance);
    expect(sv7Holders($this->approver))->toHaveKey($joiner->user_id);

    // Leaving, or an account that can no longer sign in, ends the grant (the nightly run catches what no event announced).
    forceLifecycle($this->fin1, LifecycleState::Exited);
    $joiner->user->forceFill(['status' => UserStatus::Suspended])->save();
    $this->artisan('peopleos:access:role-rules')->assertSuccessful();
    expect(sv7Holders($this->approver))->toBe([$this->op1->user_id => 'rule']);
    $removal = AuditEvent::query()->withoutGlobalScopes()->where('tenant_id', $this->tenant->id)->where('action', AuditAction::RoleChanged->value)
        ->where('entity_id', $this->fin1->user_id)->latest('id')->first();
    expect($removal->metadata)->toMatchArray(['removed' => ['finance-approver'], 'rule_id' => $rule->id]);

    // Idempotent: a second run changes nothing.
    expect(app(RoleMembershipSync::class)->run())->toBe(['granted' => 0, 'removed' => 0, 'reassigned' => 0]);
});

it('never touches a role given by hand', function () {
    $members = app(RoleMembers::class);
    $members->add($this->approver, $this->fin1->user, null, $this->admin, 'Given by hand');
    $otherRole = Role::query()->where('slug', 'hr-executive')->firstOrFail();
    $members->add($otherRole, $this->fin2->user, null, $this->admin, 'Other role');

    $rule = sv7Rule($this->approver, sv7Department($this->finance));
    expect(sv7Holders($this->approver))->toBe([$this->fin1->user_id => 'manual', $this->fin2->user_id => 'rule'])
        ->and(sv7Holders($otherRole))->toBe([$this->fin2->user_id => 'manual']);

    // Switching the rule off removes only what it gave.
    app(RoleMembershipRules::class)->setStatus($rule, 'inactive', $this->admin, 'Pause');
    expect(sv7Holders($this->approver))->toBe([$this->fin1->user_id => 'manual']);
    app(RoleMembershipRules::class)->setStatus($rule, 'active', $this->admin, 'Resume');

    // Removing by hand cannot take away a rule's grant; giving it by hand makes it the person's own.
    expect(fn () => $members->remove($this->approver, $this->fin2->user, $this->admin, 'Try'))->toThrow(RuntimeException::class, 'membership rule "Finance team"');
    $members->add($this->approver, $this->fin2->user, null, $this->admin, 'Keep it');
    expect(sv7Holders($this->approver))->toBe([$this->fin1->user_id => 'manual', $this->fin2->user_id => 'manual']);
    app(RoleMembershipRules::class)->setStatus($rule, 'inactive', $this->admin, 'Pause again');
    expect(sv7Holders($this->approver))->toBe([$this->fin1->user_id => 'manual', $this->fin2->user_id => 'manual']);

    // The user form saves the hand-given roles only: a rule grant survives a save that does not list it.
    app(RoleMembershipRules::class)->setStatus($rule, 'active', $this->admin, 'Again');
    $members->remove($this->approver, $this->fin2->user, $this->admin, 'By hand no more');
    expect(sv7Holders($this->approver))->not->toHaveKey($this->fin2->user_id);
    app(RoleMembershipSync::class)->run();
    expect(sv7Holders($this->approver)[$this->fin2->user_id])->toBe('rule');
    $members->syncManual($this->fin2->user, [$otherRole->id], $this->admin);
    expect(sv7Holders($this->approver)[$this->fin2->user_id])->toBe('rule')
        ->and($members->manualRoleIds($this->fin2->user))->toBe([$otherRole->id])
        ->and($members->ruleGrants($this->fin2->user))->toBe(['Finance approver' => 'Finance team']);
});

it('applies the delegation ceiling to rule authors and never grants a locked role', function () {
    $limited = tenantUser($this->tenant, ['user.assign_roles', 'user.view', 'role.view', 'expense.view']);
    expect(fn () => sv7Rule($this->approver, sv7Department($this->finance), $limited))->toThrow(RuntimeException::class, 'permissions you do not hold');
    // A system role a limited administrator may give by hand is not one they may give by rule.
    $payroll = Role::query()->where('slug', 'payroll-admin')->firstOrFail();
    expect(app(RoleGrants::class)->canAssign($limited, $payroll))->toBeTrue()
        ->and(app(RoleGrants::class)->canAssignByRule($limited, $payroll))->toBeFalse();
    expect(fn () => sv7Rule($payroll, sv7Department($this->finance), $limited))->toThrow(RuntimeException::class, 'permissions you do not hold');

    // Locked roles, whoever writes the rule.
    $platformish = Role::factory()->create(['name' => 'Platform-ish', 'slug' => 'platformish']);
    $platformish->permissions()->sync(app(PermissionRegistry::class)->idsMatching(['tenant.view']));
    foreach (['tenant-super-admin', 'account-owner'] as $slug) {
        $role = Role::query()->where('slug', $slug)->firstOrFail();
        expect(fn () => sv7Rule($role, sv7Department($this->finance)))->toThrow(RuntimeException::class, 'No membership rule may grant');
    }
    expect(fn () => sv7Rule($platformish, sv7Department($this->finance)))->toThrow(RuntimeException::class, 'No membership rule may grant');

    // A rule written in the database anyway grants nothing, and a grant tagged with it is removed.
    $superAdmin = Role::query()->where('slug', 'tenant-super-admin')->firstOrFail();
    $forged = RoleMembershipRule::query()->create(['role_id' => $superAdmin->id, 'name' => 'Forged', 'conditions' => sv7Department($this->finance)]);
    DB::table('role_user')->insert(['role_id' => $superAdmin->id, 'user_id' => $this->op1->user_id, 'source' => 'rule', 'membership_rule_id' => $forged->id, 'created_at' => now(), 'updated_at' => now()]);
    app(RoleMembershipSync::class)->run();
    expect(sv7Holders($superAdmin))->not->toHaveKey($this->fin1->user_id)->not->toHaveKey($this->op1->user_id);

    // Deactivating or deleting needs only the right to change role holders (it removes access).
    $rule = sv7Rule($this->approver, sv7Department($this->finance));
    app(RoleMembershipRules::class)->setStatus($rule, 'inactive', $limited, 'Narrowing is allowed');
    expect(sv7Holders($this->approver))->toBe([])
        ->and(fn () => app(RoleMembershipRules::class)->setStatus($rule, 'active', $limited, 'Widening is not'))->toThrow(RuntimeException::class, 'permissions you do not hold');
    $nobody = tenantUser($this->tenant, ['role.view']);
    expect(fn () => app(RoleMembershipRules::class)->delete($rule, $nobody, 'x'))->toThrow(RuntimeException::class, 'cannot change who holds roles');
});

it('refuses malformed rules, and a malformed rule stored anyway matches nobody', function () {
    $rules = app(RoleMembershipRules::class);
    foreach ([
        'no condition' => [[], 'at least one condition'],
        'unknown field' => [[['field' => 'salary', 'operator' => 'equals', 'value' => '1']], 'field that does not exist'],
        'unknown operator' => [[['field' => 'department_id', 'operator' => 'like', 'value' => '1']], 'operator that does not exist'],
        'no value' => [[['field' => 'department_id', 'operator' => 'in', 'value' => []]], 'no value'],
        'bad id' => [[['field' => 'department_id', 'operator' => 'equals', 'value' => 'abc']], 'not valid'],
    ] as $label => [$conditions, $message]) {
        expect(fn () => sv7Rule($this->approver, $conditions))->toThrow(RuntimeException::class, $message);
    }
    expect(fn () => $rules->save($this->approver, null, ['name' => 'X', 'conditions' => sv7Department($this->finance), 'match' => 'some'], $this->admin, 'r'))
        ->toThrow(RuntimeException::class, 'every condition or any');

    // Stored anyway: an unknown field with "is none of" would match everyone in the rule engine; here it matches nobody.
    foreach ([
        [['field' => 'salary', 'operator' => 'not_in', 'value' => ['1']]],
        ['not' => 'a list'],
        [],
    ] as $conditions) {
        DB::table('role_membership_rules')->insert(['tenant_id' => $this->tenant->id, 'role_id' => $this->approver->id, 'name' => 'Forged', 'conditions' => json_encode($conditions),
            'match' => 'all', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
    }
    DB::table('role_membership_rules')->insert(['tenant_id' => $this->tenant->id, 'role_id' => $this->approver->id, 'name' => 'Bad match', 'conditions' => json_encode(sv7Department($this->finance)),
        'match' => 'most', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
    expect(app(RoleMembershipSync::class)->run())->toBe(['granted' => 0, 'removed' => 0, 'reassigned' => 0])
        ->and(sv7Holders($this->approver))->toBe([])
        ->and($rules->preview([['field' => 'salary', 'operator' => 'not_in', 'value' => ['1']]]))->toBe(0)
        ->and($rules->preview(sv7Department($this->finance)))->toBe(2);
});

it('hands a grant over between rules of a role and removes it with the last rule', function () {
    $byDepartment = sv7Rule($this->approver, sv7Department($this->finance));
    $byName = sv7Rule($this->approver, [['field' => 'employee_id', 'operator' => 'in', 'value' => [(string) $this->fin1->id]]], name: 'Farah');
    expect(sv7Holders($this->approver))->toBe([$this->fin1->user_id => 'rule', $this->fin2->user_id => 'rule']);

    app(RoleMembershipRules::class)->delete($byDepartment, $this->admin, 'Department rule retired');
    expect(sv7Holders($this->approver))->toBe([$this->fin1->user_id => 'rule'])
        ->and(DB::table('role_user')->where('role_id', $this->approver->id)->value('membership_rule_id'))->toBe($byName->id)
        ->and(RoleMembershipRule::query()->whereKey($byDepartment->id)->exists())->toBeFalse();
    app(RoleMembershipRules::class)->delete($byName, $this->admin, 'Named rule retired');
    expect(sv7Holders($this->approver))->toBe([]);
});

it('keeps tenants apart', function () {
    sv7Rule($this->approver, sv7Department($this->finance));
    $rowsA = DB::table('role_user')->where('source', 'rule')->count();

    actAsTenant($this->otherTenant);
    $foreignRole = Role::query()->where('slug', 'hr-executive')->firstOrFail();
    expect(app(RoleMembershipSync::class)->run())->toBe(['granted' => 0, 'removed' => 0, 'reassigned' => 0])
        ->and(DB::table('role_user')->where('source', 'rule')->count())->toBe($rowsA)
        ->and(RoleMembershipRule::query()->count())->toBe(0);
    actAsTenant($this->tenant);
    expect(fn () => app(RoleMembershipRules::class)->save($foreignRole, null, ['name' => 'X', 'conditions' => sv7Department($this->finance)], $this->admin, 'r'))
        ->toThrow(RuntimeException::class, 'another organisation');
    expect(RoleMembershipRule::query()->count())->toBe(1);
});

it('seeds the external role with only its own tasks (AR-1), held by nobody', function () {
    $external = Role::query()->where('slug', 'external')->firstOrFail();
    expect($external->is_system)->toBeTrue()
        ->and($external->permissions()->pluck('key')->sort()->values()->all())->toBe(['task.act', 'task.view'])
        ->and($external->users()->count())->toBe(0);

    // A tenant's own role that carries the slug (made before the template existed) is never given template permissions.
    DB::table('roles')->where('id', $external->id)->update(['is_system' => false, 'template_keys' => null]);
    $external->permissions()->sync([]);
    app(ProvisionTenantAction::class)->seedSystemRoles();
    expect($external->permissions()->count())->toBe(0);
});

it('shows membership rules and how each member holds the role on the role page', function () {
    sv7Rule($this->approver, sv7Department($this->finance));
    Livewire::test(MembershipRulesRelationManager::class, ['ownerRecord' => $this->approver, 'pageClass' => EditRole::class])
        ->assertOk()->assertSee('Finance team')->assertSee('Department');
    Livewire::test(MembersRelationManager::class, ['ownerRecord' => $this->approver, 'pageClass' => EditRole::class])
        ->assertOk()->assertSee('Rule: Finance team');
    $this->get(RoleResource::getUrl('edit', ['record' => $this->approver]))->assertOk();
});

it('shows and saves only hand-given roles on the user form, keeping rule grants and every scope dimension', function () {
    $hrExec = Role::query()->where('slug', 'hr-executive')->firstOrFail();
    $user = $this->fin1->user;
    $user->roles()->attach($hrExec->id);
    sv7Rule($this->approver, sv7Department($this->finance));
    $team = Team::factory()->create(['company_id' => $this->company->id]);
    app(AccessScopes::class)->assign($user, ['team' => [$team->id]], 'Team scope');

    Livewire::test(EditUser::class, ['record' => $user->id])
        ->assertFormSet(fn (array $state) => expect(array_values($state['roles']))->toBe([(string) $hrExec->id])
            ->and(array_map('intval', $state['access_scope']['team']))->toBe([$team->id]))
        ->assertSee('Also held through role membership rules')
        ->fillForm(['roles' => [], 'audit_reason' => 'Hand-given roles removed'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect(sv7Holders($this->approver)[$user->id])->toBe('rule')
        ->and(sv7Holders($hrExec))->not->toHaveKey($user->id)
        ->and(UserAccessScope::query()->where('user_id', $user->id)->pluck('dimension')->all())->toBe(['team']);
});
