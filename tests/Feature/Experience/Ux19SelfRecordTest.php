<?php

use App\Domain\Employment\Actions\HireEmployeeAction;
use App\Domain\Employment\Exceptions\ProfileChangeRefused;
use App\Domain\Employment\Models\Employee;
use App\Domain\Experience\Services\PersonWorkspace;
use App\Domain\Identity\Models\Role;
use App\Domain\Identity\Models\User;
use App\Domain\Lifecycle\Enums\LifecycleState;
use App\Domain\Lifecycle\Models\EmployeeTimelineEntry;
use App\Domain\Lifecycle\Services\LifecycleEngine;
use App\Domain\Organisation\Models\Company;
use App\Domain\People\Actions\ChangeFamilyMemberAction;
use App\Domain\Platform\Models\Tenant;
use App\Filament\Pages\MyHr;
use App\Filament\Pages\NotificationCenter;
use App\Filament\Resources\Employees\EmployeeResource;
use App\Filament\Resources\Employees\Pages\ViewEmployee;
use App\Filament\Resources\Employees\RelationManagers\BankAccountsRelationManager;
use App\Filament\Resources\Employees\RelationManagers\BgvRelationManager;
use App\Filament\Resources\Employees\RelationManagers\CompensationChangesRelationManager;
use App\Filament\Resources\Employees\RelationManagers\DocumentsRelationManager;
use App\Filament\Resources\Employees\RelationManagers\FamilyMembersRelationManager;
use App\Filament\Resources\Employees\RelationManagers\LeaveRelationManager;
use App\Filament\Resources\Employees\RelationManagers\PositionsRelationManager;
use App\Filament\Resources\Employees\RelationManagers\SuccessionRelationManager;
use App\Filament\Resources\Employees\RelationManagers\TalentRelationManager;
use App\Filament\Resources\Employees\RelationManagers\TimelineRelationManager;
use App\Filament\Resources\Employees\RelationManagers\WorkflowsRelationManager;
use App\Livewire\Experience\DrawerHost;
use App\Support\Tenancy\TenantContext;
use Livewire\Livewire;

/*
| UX.19 (G12): the Employee 360 is also its subject's own record. employee.self opens the one record linked to the
| signed-in user, in the current tenant, read-only. It opens nothing else: not another employee, not the register,
| not sensitive data, compensation proposals, talent, succession, documents or background checks (each keeps its own
| rule). Revoking the permission closes it. The person finds it from My HR, the directory sheet, notifications and
| "My profile".
*/

function ux19User(Tenant $tenant, array $roles, ?Employee $employee = null): User
{
    return app(TenantContext::class)->runAs($tenant, function () use ($tenant, $roles, $employee) {
        $user = User::factory()->forTenant($tenant)->create();
        $user->roles()->attach(Role::query()->whereIn('slug', $roles)->pluck('id'));
        if ($employee !== null) {
            LifecycleEngine::unguarded(fn () => $employee->forceFill(['user_id' => $user->id])->save());
        }

        return $user;
    });
}

beforeEach(function () {
    $this->travelTo('2026-10-05 09:00:00');
    $this->tenant = provisionTenant();
    actAsTenant($this->tenant);
    $this->company = Company::factory()->create(['name' => 'Acme Tech']);
    $hire = fn (string $first, ?Employee $manager = null) => tap(app(HireEmployeeAction::class)->handle(
        ['first_name' => $first, 'last_name' => 'Self'], ['joining_date' => '2024-01-01', 'work_email' => strtolower($first).'@acme.test'],
        ['company_id' => $this->company->id], $manager?->id,
    ), fn (Employee $e) => forceLifecycle($e, LifecycleState::Active));
    $this->managerEmployee = $hire('Maya');
    $this->me = $hire('Ravi', $this->managerEmployee);
    $this->colleague = $hire('Omar', $this->managerEmployee);

    $this->employee = ux19User($this->tenant, ['employee'], $this->me);
    $this->manager = ux19User($this->tenant, ['employee', 'manager'], $this->managerEmployee);
});

it('opens the employee\'s own Employee 360 and nothing else', function () {
    $this->actingAs($this->employee);
    $this->get(EmployeeResource::getUrl('view', ['record' => $this->me]))->assertOk()->assertSee('Your record')->assertSee('Ravi Self');

    // Not a colleague, not the manager, not the register and no write page.
    $this->get(EmployeeResource::getUrl('view', ['record' => $this->colleague]))->assertForbidden();
    $this->get(EmployeeResource::getUrl('view', ['record' => $this->managerEmployee]))->assertForbidden();
    $this->get(EmployeeResource::getUrl('index'))->assertForbidden();
    $this->get(EmployeeResource::getUrl('create'))->assertForbidden();
    $this->get(EmployeeResource::getUrl('edit', ['record' => $this->me]))->assertForbidden();
    expect(EmployeeResource::canAccess())->toBeFalse()
        ->and($this->employee->can('viewAny', Employee::class))->toBeFalse()
        ->and($this->employee->can('update', $this->me))->toBeFalse()
        ->and($this->employee->can('delete', $this->me))->toBeFalse();

    // The record check runs on every update too: the page cannot be re-pointed at someone else.
    Livewire::test(ViewEmployee::class, ['record' => $this->me->getKey()])->assertOk();
    Livewire::test(ViewEmployee::class, ['record' => $this->colleague->getKey()])->assertStatus(403);
});

it('keeps every section of the own record on its own rule', function () {
    $this->actingAs($this->employee);
    $sees = fn (string $manager) => $manager::canViewForRecord($this->me, ViewEmployee::class);

    // Their own record, employment history, timeline and leave.
    expect($sees(FamilyMembersRelationManager::class))->toBeTrue()
        ->and($sees(PositionsRelationManager::class))->toBeTrue()
        ->and($sees(TimelineRelationManager::class))->toBeTrue()
        ->and($sees(LeaveRelationManager::class))->toBeTrue();

    // Sensitive data, compensation proposals about them, talent, succession, HR documents, background checks and
    // workflows keep their own permissions; the subject is not given them.
    expect($sees(BankAccountsRelationManager::class))->toBeFalse()
        ->and($sees(CompensationChangesRelationManager::class))->toBeFalse()
        ->and($sees(TalentRelationManager::class))->toBeFalse()
        ->and($sees(SuccessionRelationManager::class))->toBeFalse()
        ->and($sees(DocumentsRelationManager::class))->toBeFalse()
        ->and($sees(BgvRelationManager::class))->toBeFalse()
        ->and($sees(WorkflowsRelationManager::class))->toBeFalse()
        ->and($this->employee->can('viewSensitive', $this->me))->toBeFalse();

    // Sensitive timeline categories stay hidden from the subject too.
    EmployeeTimelineEntry::query()->create(['employee_id' => $this->me->id, 'occurred_on' => '2026-09-01', 'category' => 'compensation', 'title' => 'Salary revised']);
    EmployeeTimelineEntry::query()->create(['employee_id' => $this->me->id, 'occurred_on' => '2026-09-02', 'category' => 'position', 'title' => 'Moved to Platform']);
    $titles = collect(app(PersonWorkspace::class)->for($this->employee, $this->me)['changes'])->pluck('title')->all();
    expect($titles)->toContain('Moved to Platform')->not->toContain('Salary revised');

    // No header action for the subject: no edit, life events, statutory data or messaging oneself.
    Livewire::test(ViewEmployee::class, ['record' => $this->me->getKey()])
        ->assertActionHidden('message')->assertActionHidden('assignPosition')->assertActionHidden('lifecycle')
        ->assertActionHidden('viewStatutory')->assertActionHidden('editStatutory');
});

it('is read-only: the own record is never a write path', function () {
    $this->actingAs($this->employee);
    $family = Livewire::test(FamilyMembersRelationManager::class, ['ownerRecord' => $this->me, 'pageClass' => ViewEmployee::class])->assertOk();
    expect($family->instance()->isReadOnly())->toBeTrue();

    // The domain write path refuses whatever the screen shows.
    expect(fn () => app(ChangeFamilyMemberAction::class)->add($this->me, ['name' => 'Asha Self', 'relation' => array_key_first(config('peopleos.people.family_relations'))], $this->employee))
        ->toThrow(ProfileChangeRefused::class);
});

it('is revocable and bound to the person and the tenant', function () {
    // Without employee.self (here: a tenant role with the other employee permissions only) the own record is closed.
    $withoutSelf = tenantUser($this->tenant, ['leave.apply', 'task.view', 'task.act']);
    LifecycleEngine::unguarded(fn () => $this->colleague->forceFill(['user_id' => $withoutSelf->id])->save());
    $this->actingAs($withoutSelf);
    $this->get(EmployeeResource::getUrl('view', ['record' => $this->colleague]))->assertForbidden();
    // The policy itself refuses too, so no link, sheet or summary treats it as their record.
    expect($withoutSelf->can('view', $this->colleague))->toBeFalse()
        ->and(app(PersonWorkspace::class)->for($withoutSelf, $this->colleague))->toBe([]);

    // An employee record in another tenant that names the same user id is never "their own" here or there.
    $other = provisionTenant('Other Co');
    actAsTenant($other);
    $otherCompany = Company::factory()->create();
    $foreign = app(HireEmployeeAction::class)->handle(['first_name' => 'Foreign', 'last_name' => 'Self'], ['joining_date' => '2024-01-01'], ['company_id' => $otherCompany->id]);
    LifecycleEngine::unguarded(fn () => $foreign->forceFill(['user_id' => $this->employee->id])->save());
    // (A fresh user instance per tenant context: a user's permissions are read once per instance.)
    expect(User::query()->find($this->employee->id)->can('view', $foreign))->toBeFalse();
    actAsTenant($this->tenant);
    $again = User::query()->find($this->employee->id);
    expect($again->can('view', $foreign))->toBeFalse()
        ->and($again->can('view', $this->me))->toBeTrue();
});

it('gives the employee a way in from My HR, the directory sheet, notifications and My profile', function () {
    $this->actingAs($this->employee);
    $own = EmployeeResource::getUrl('view', ['record' => $this->me]);

    $this->get(MyHr::getUrl())->assertOk()->assertSee('Your record')->assertSee($own, false);
    $this->get(MyHr::getUrl())->assertSee('My profile');

    $center = new NotificationCenter;
    expect($center->linkFor($this->me->getMorphClass(), $this->me->id))->toBe($own)
        ->and($center->linkFor($this->colleague->getMorphClass(), $this->colleague->id))->toBeNull();

    $sheet = Livewire::test(DrawerHost::class)->call('show', 'person', (string) $this->me->id)->instance()->person();
    expect($sheet['profile'])->toBe($own);
    $other = Livewire::test(DrawerHost::class)->call('show', 'person', (string) $this->colleague->id)->instance()->person();
    expect($other['profile'] ?? null)->toBeNull();

    // A manager still opens their report's 360 as before, and their own.
    $this->actingAs($this->manager);
    $this->get(EmployeeResource::getUrl('view', ['record' => $this->me]))->assertOk();
    $this->get(EmployeeResource::getUrl('view', ['record' => $this->managerEmployee]))->assertOk();
});
