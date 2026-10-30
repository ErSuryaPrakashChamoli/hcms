<?php

use App\Domain\Audit\Models\AuditEvent;
use App\Domain\Identity\Enums\UserStatus;
use App\Domain\Identity\Models\Role;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Services\PermissionRegistry;
use App\Domain\Identity\Services\RoleMembers;
use App\Domain\Organisation\Models\Company;
use App\Domain\Platform\Services\SettingsRepository;
use App\Domain\Workflow\Enums\InstanceStatus;
use App\Domain\Workflow\Services\WorkflowEngine;
use App\Filament\Pages\RolesByPerson;
use App\Filament\Resources\Roles\Pages\EditRole;
use App\Filament\Resources\Roles\Pages\ListRoles;
use App\Filament\Resources\Roles\RelationManagers\MembersRelationManager;
use App\Filament\Resources\Roles\RoleResource;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

require_once __DIR__.'/../Workflow/WorkflowTestHelpers.php';

/*
| HROne setup parity P14 (Automation → Role): a role is active or inactive, shows its members (added and removed under
| the same rules as the user form), can be read by person, and names a reliever; the line manager has an organisation-wide
| reliever. Relievers take approvals only when the usual approver cannot, so existing workflows behave as before.
*/

beforeEach(function () {
    $this->tenant = provisionTenant();
    actAsTenant($this->tenant);
    $this->admin = tenantUser($this->tenant, ['*']);
    $this->actingAs($this->admin);
    $this->engine = app(WorkflowEngine::class);
});

function rolesPolishRole(array $permissions, array $attributes = []): Role
{
    $role = Role::factory()->create($attributes);
    $role->permissions()->sync(app(PermissionRegistry::class)->idsMatching($permissions));

    return $role;
}

function rolesPolishApproval(array $approver, array $config = []): array
{
    return linear([['id' => 'approve', 'type' => 'approval', 'name' => 'Approve', 'config' => ['mode' => 'single', 'approver' => $approver] + $config]]);
}

/* ---------- Status ---------- */

it('grants nothing through an inactive role, and keeps system roles active', function () {
    $role = rolesPolishRole(['payroll.view']);
    $user = tenantUser($this->tenant);
    $user->roles()->attach($role);
    expect($user->fresh()->hasPermission('payroll.view'))->toBeTrue()->and($user->fresh()->roleSlugs())->toContain($role->slug);

    $role->update(['status' => 'inactive']);
    expect($user->fresh()->hasPermission('payroll.view'))->toBeFalse()
        ->and($user->fresh()->roleSlugs())->not->toContain($role->slug)
        ->and($user->fresh()->load('roles')->roleSlugs())->not->toContain($role->slug);

    $system = Role::query()->where('slug', 'employee')->firstOrFail();
    expect(fn () => $system->update(['status' => 'inactive']))->toThrow(ValidationException::class, 'system role');
    expect($system->fresh()->isInactive())->toBeFalse();
});

/* ---------- Members ---------- */

it('adds and removes members from the role, audited on the person, within the delegation ceiling', function () {
    $role = rolesPolishRole(['employee.view']);
    $user = tenantUser($this->tenant);
    $company = Company::factory()->create(['name' => 'Markedge India']);

    $members = Livewire::test(MembersRelationManager::class, ['ownerRecord' => $role, 'pageClass' => EditRole::class])->assertOk();
    $members->callTableAction('addMember', data: ['user_id' => $user->id, 'company_id' => $company->id, 'reason' => 'New HR partner'])->assertNotified();
    expect($user->fresh()->roles()->pluck('roles.id')->all())->toBe([$role->id])
        ->and(DB::table('role_user')->where('user_id', $user->id)->value('company_id'))->toBe($company->id);
    Livewire::test(MembersRelationManager::class, ['ownerRecord' => $role, 'pageClass' => EditRole::class])
        ->assertCanSeeTableRecords([$user])->assertSee('Markedge India');

    Livewire::test(MembersRelationManager::class, ['ownerRecord' => $role, 'pageClass' => EditRole::class])
        ->callTableAction('removeMember', $user, data: ['reason' => 'Moved team']);
    expect($user->fresh()->roles()->count())->toBe(0);

    $audit = AuditEvent::query()->withoutTenancy()->where('action', 'ROLE_CHANGED')->where('entity_id', (string) $user->id)->get();
    expect($audit->pluck('metadata')->filter(fn ($m) => ($m['granted'] ?? []) === [$role->slug]))->not->toBeEmpty()
        ->and($audit->pluck('metadata')->filter(fn ($m) => ($m['removed'] ?? []) === [$role->slug]))->not->toBeEmpty();

    // Someone who holds only part of a custom role cannot hand it out; someone without user.assign_roles changes nothing.
    $limited = tenantUser($this->tenant, ['user.view', 'user.assign_roles', 'role.view']);
    $wide = rolesPolishRole(['payroll.view']);
    expect(fn () => app(RoleMembers::class)->add($wide, $user, null, $limited, 'try'))->toThrow(RuntimeException::class, 'do not hold yourself');
    $viewer = tenantUser($this->tenant, ['user.view', 'role.view']);
    expect(fn () => app(RoleMembers::class)->add($role, $user, null, $viewer, 'try'))->toThrow(RuntimeException::class, 'cannot change who holds roles');
    $this->actingAs($viewer);
    Livewire::test(MembersRelationManager::class, ['ownerRecord' => $role, 'pageClass' => EditRole::class])
        ->assertTableActionHidden('addMember');

    $foreign = tenantUser(provisionTenant('Other'));
    actAsTenant($this->tenant);
    expect(fn () => app(RoleMembers::class)->add($role, $foreign, null, $this->admin, 'x'))->toThrow(RuntimeException::class, 'another organisation');
});

it('shows roles by person, inactive roles marked, with the role matrix and relievers', function () {
    $hr = rolesPolishRole(['employee.view'], ['name' => 'HR desk']);
    $old = rolesPolishRole(['employee.view'], ['name' => 'Old desk', 'status' => 'inactive']);
    $person = tenantUser($this->tenant, [], ['name' => 'Kavya Menon']);
    $person->roles()->attach([$hr->id, $old->id]);
    $hr->update(['reliever_user_id' => $person->id]);

    $this->get(RolesByPerson::getUrl())->assertOk()->assertSee('Roles by person');
    Livewire::test(RolesByPerson::class)->assertCanSeeTableRecords([$person])->assertSee('Old desk (inactive)')->assertSee('HR desk');
    Livewire::test(ListRoles::class)->assertActionVisible('byPerson');
    $this->get(RoleResource::getUrl('index'))->assertOk()->assertSee('Reliever');

    $this->actingAs(tenantUser($this->tenant, ['role.view']));
    $this->get(RolesByPerson::getUrl())->assertForbidden();
});

/* ---------- Relievers ---------- */

it('keeps role approvals as before without a reliever, and sends them to the reliever when nobody active holds the role', function () {
    $employee = employeeWithUser();
    $role = rolesPolishRole(['task.view', 'task.act'], ['name' => 'Finance']);
    [$nodes, $edges] = rolesPolishApproval(['type' => 'role', 'role_id' => $role->id]);
    $workflow = publishWorkflow($nodes, $edges);

    // Today: the task waits in the role's queue even with no holder.
    expect($this->engine->start($workflow, $employee)->tasks->first()->assignee_role_id)->toBe($role->id);

    $reliever = tenantUser($this->tenant, ['task.view', 'task.act']);
    $role->update(['reliever_user_id' => $reliever->id]);
    $task = $this->engine->start($workflow, $employee)->tasks->first();
    expect($task->assignee_id)->toBe($reliever->id)->and($task->assignee_role_id)->toBeNull();

    // An active holder takes it again.
    $holder = tenantUser($this->tenant, ['task.view']);
    $holder->roles()->attach($role);
    expect($this->engine->start($workflow, $employee)->tasks->first()->assignee_role_id)->toBe($role->id);

    // An inactive role gives no tasks: its reliever, else the node's fallback, else no assignee.
    $role->update(['status' => 'inactive']);
    expect($this->engine->start($workflow, $employee)->tasks->first()->assignee_id)->toBe($reliever->id);
    $role->update(['reliever_user_id' => null]);
    expect($this->engine->start($workflow, $employee)->error)->toContain('Could not find an assignee');
    [$nodes, $edges] = rolesPolishApproval(['type' => 'role', 'role_id' => $role->id], ['fallback' => ['type' => 'user', 'user_id' => $this->admin->id]]);
    expect($this->engine->start(publishWorkflow($nodes, $edges), $employee)->tasks->first()->assignee_id)->toBe($this->admin->id);
});

it('sends a manager approval to the manager reliever when the manager\'s account is inactive or there is no manager', function () {
    $manager = employeeWithUser();
    $employee = employeeWithUser($manager);
    $orphan = employeeWithUser();
    [$nodes, $edges] = rolesPolishApproval(['type' => 'manager']);
    $workflow = publishWorkflow($nodes, $edges);
    $reliever = tenantUser($this->tenant, ['task.view', 'task.act']);

    // Without the setting: as before (inactive manager still assigned; no manager has no assignee).
    User::query()->whereKey($manager->user_id)->update(['status' => UserStatus::Suspended->value]);
    expect($this->engine->start($workflow, $employee)->tasks->first()->assignee_id)->toBe($manager->user_id);
    expect($this->engine->start($workflow, $orphan)->status)->toBe(InstanceStatus::Failed);

    app(SettingsRepository::class)->set('workflow.manager_reliever_user_id', $reliever->id, 'Cover for managers');
    expect($this->engine->start($workflow, $employee)->tasks->first()->assignee_id)->toBe($reliever->id)
        ->and($this->engine->start($workflow, $orphan)->tasks->first()->assignee_id)->toBe($reliever->id);

    // A node's own fallback comes before the organisation-wide reliever when there is no manager.
    [$nodes, $edges] = rolesPolishApproval(['type' => 'manager'], ['fallback' => ['type' => 'user', 'user_id' => $this->admin->id]]);
    expect($this->engine->start(publishWorkflow($nodes, $edges), $orphan)->tasks->first()->assignee_id)->toBe($this->admin->id);

    // An active manager is always the approver; an inactive reliever is no reliever.
    User::query()->whereKey($manager->user_id)->update(['status' => UserStatus::Active->value]);
    expect($this->engine->start($workflow, $employee)->tasks->first()->assignee_id)->toBe($manager->user_id);
    User::query()->whereKey($reliever->id)->update(['status' => UserStatus::Suspended->value]);
    expect($this->engine->start($workflow, $orphan)->error)->toContain('Could not find an assignee');
});
