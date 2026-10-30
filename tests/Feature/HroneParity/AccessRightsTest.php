<?php

use App\Domain\Attendance\Models\AttendancePunch;
use App\Domain\Audit\Models\AuditEvent;
use App\Domain\Configuration\Services\SetupCatalogue;
use App\Domain\Employment\Actions\HireEmployeeAction;
use App\Domain\Employment\Models\Employee;
use App\Domain\Experience\Services\ExperiencePreferences;
use App\Domain\Experience\Services\GeoDashboard as GeoDashboardModel;
use App\Domain\Experience\Services\HomeComposer;
use App\Domain\Experience\Services\MySpace;
use App\Domain\Experience\Services\RoleLens;
use App\Domain\Experience\Services\TeamCalendar as TeamCalendarModel;
use App\Domain\Identity\Models\AccessScopeGroup;
use App\Domain\Identity\Models\Role;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Services\AccessScopeGroups;
use App\Domain\Identity\Services\AccessScopes;
use App\Domain\Identity\Services\AuthorizationContext;
use App\Domain\Organisation\Models\Company;
use App\Domain\Organisation\Models\Department;
use App\Domain\Organisation\Models\Designation;
use App\Domain\Organisation\Models\Grade;
use App\Domain\Organisation\Models\Level;
use App\Domain\Organisation\Models\Location;
use App\Domain\Platform\Services\SettingsRepository;
use App\Filament\Pages\GeoDashboard;
use App\Filament\Pages\Preferences;
use App\Filament\Pages\TeamCalendar;
use App\Filament\Resources\AccessScopeGroups\AccessScopeGroupResource;
use App\Filament\Resources\AccessScopeGroups\Pages\CreateAccessScopeGroup;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

/*
 | HROne parity CA-9 (Access rights) and SV-7 (wider access scopes): module data-access groups layered into AccessScopes,
 | designation / grade / level scopes, reportees and sub-departments, page access per My space item, widget, calendar and
 | geo access. Every mechanism only narrows; malformed configuration reaches nobody; tenants stay isolated; people with
 | no new configuration keep exactly what they had.
 */

beforeEach(function () {
    $this->travelTo('2026-11-10 10:00:00');
    $this->tenant = provisionTenant('Access A');
    $this->otherTenant = provisionTenant('Access B');
    actAsTenant($this->tenant);
    Filament::getPanel('admin')->boot();

    $this->admin = tenantUser($this->tenant, ['*']);
    $this->actingAs($this->admin);

    $this->alpha = Company::factory()->create(['name' => 'Alpha']);
    $this->beta = Company::factory()->create(['name' => 'Beta']);
    $this->locA = Location::factory()->create(['company_id' => $this->alpha->id, 'name' => 'Pune']);
    $this->locB = Location::factory()->create(['company_id' => $this->beta->id, 'name' => 'Delhi']);
    $this->sales = Department::factory()->create(['company_id' => $this->alpha->id, 'name' => 'Sales']);
    $this->salesNorth = Department::factory()->create(['company_id' => $this->alpha->id, 'name' => 'Sales North', 'parent_department_id' => $this->sales->id]);
    $this->ops = Department::factory()->create(['company_id' => $this->alpha->id, 'name' => 'Ops']);
    $this->engineer = Designation::factory()->create(['name' => 'Engineer']);
    $this->gradeA = Grade::factory()->create(['name' => 'G1']);
    $this->levelA = Level::factory()->create(['name' => 'L1']);

    $this->mgrUser = tenantUser($this->tenant, ['employee.view', 'attendance.view', 'leave.view', 'celebration.view']);
    $this->mgr = ca9Hire('Mona', $this->alpha, $this->locA, $this->sales, user: $this->mgrUser);
    $this->e1 = ca9Hire('Esha', $this->alpha, $this->locA, $this->salesNorth, $this->mgr, extra: ['designation_id' => $this->engineer->id, 'grade_id' => $this->gradeA->id]);
    $this->e2 = ca9Hire('Eli', $this->alpha, $this->locA, $this->ops, $this->e1);
    $this->e3 = ca9Hire('Bina', $this->beta, $this->locB, null);
    $this->e4 = ca9Hire('Omar', $this->alpha, $this->locA, $this->ops, extra: ['level_id' => $this->levelA->id]);
    foreach ([$this->mgr, $this->e1, $this->e2, $this->e3, $this->e4] as $i => $employee) {
        AttendancePunch::query()->create(['employee_id' => $employee->id, 'punched_at' => now()->subMinutes(10 + $i), 'direction' => 'in', 'source' => 'web',
            'latitude' => 18.52 + $i / 100, 'longitude' => 73.85]);
    }
});

function ca9Hire(string $first, Company $company, Location $location, ?Department $department, ?Employee $manager = null, ?User $user = null, array $extra = []): Employee
{
    return app(HireEmployeeAction::class)->handle(['first_name' => $first, 'last_name' => 'Test'], array_filter(['joining_date' => '2025-01-01', 'user_id' => $user?->id]),
        ['company_id' => $company->id, 'location_id' => $location->id, 'department_id' => $department?->id] + $extra, $manager?->id);
}

/** Save a data-access group as the administrator (through the service, audited). */
function ca9Group(array $data, array $members): AccessScopeGroup
{
    return app(AccessScopeGroups::class)->save(null, $data + ['members' => array_map(fn (User $u) => $u->id, $members)], test()->admin, 'Test group');
}

/** @return list<int> */
function ca9Ids(iterable $models, string $key = 'id'): array
{
    return collect($models)->pluck($key)->map(fn ($id) => (int) $id)->sort()->values()->all();
}

/** What $user sees: employees, punches, record answers, the employee-key sub-select per module. */
function ca9Snapshot(User $user): array
{
    $before = auth()->user();
    app(AccessScopes::class)->forget();
    test()->actingAs($user);
    $employees = Employee::query()->get();
    $all = AccessScopes::class;
    $snapshot = [
        'employees' => ca9Ids($employees),
        'punches' => ca9Ids(AttendancePunch::query()->get(), 'employee_id'),
        'scoped' => app($all)->isScoped($user),
        'keys' => collect(['workforce', 'time_office', 'payroll', null])->mapWithKeys(fn ($m) => [$m ?? 'none' => ca9Ids(app($all)->employeeKeys($user, $m)->get(), 'id')])->all(),
        'can' => Employee::query()->withoutGlobalScopes()->where('tenant_id', test()->tenant->id)->get()->mapWithKeys(fn (Employee $e) => [$e->id => $user->can('view', $e)])->all(),
    ];
    test()->actingAs($before);

    return $snapshot;
}

it('keeps exactly what people saw before when nothing new is configured for them', function () {
    $scoped = tenantUser($this->tenant, ['employee.view', 'attendance.view']);
    app(AccessScopes::class)->assign($scoped, ['company' => [$this->alpha->id]]);
    $people = [$this->admin, $scoped, $this->mgrUser];
    $before = array_map(fn (User $u) => ca9Snapshot($u), $people);
    $roleRows = DB::table('role_user')->orderBy('id')->get()->map(fn ($r) => (array) $r)->all();

    // Configure everything for someone else: data-access groups, a role page-access list, settings that are defaults.
    $other = tenantUser($this->tenant, ['employee.view']);
    ca9Group(['name' => 'Payroll Beta', 'module' => 'payroll', 'rules' => ['company' => [$this->beta->id]]], [$other]);
    ca9Group(['name' => 'Everyone Ops', 'module' => 'workforce', 'rules' => ['department' => [$this->ops->id]], 'reportees' => 'all'], [$other]);
    Role::query()->whereKey($other->roles()->first()->id)->update(['myspace_hidden_items' => json_encode(['preferences'])]);

    expect(array_map(fn (User $u) => ca9Snapshot($u), $people))->toBe($before)
        ->and(DB::table('role_user')->whereIn('user_id', array_map(fn (User $u) => $u->id, $people))->orderBy('id')->get()->map(fn ($r) => (array) $r)->all())
        ->toBe(collect($roleRows)->filter(fn ($r) => in_array($r['user_id'], array_map(fn (User $u) => $u->id, $people), true))->values()->all())
        ->and(collect($roleRows)->pluck('source')->unique()->values()->all())->toBe(['manual']);
    // An unscoped person with no group still is unscoped, and sees every employee.
    expect(app(AccessScopes::class)->isScoped($this->admin))->toBeFalse()
        ->and($before[0]['employees'])->toHaveCount(5);
});

it('narrows a member of a module group in that module only, and never beyond their own scope', function () {
    $hr = tenantUser($this->tenant, ['employee.view', 'attendance.view']);
    app(AccessScopes::class)->assign($hr, ['company' => [$this->alpha->id]]);
    $before = ca9Snapshot($hr);
    ca9Group(['name' => 'Time office Sales', 'module' => 'time_office', 'rules' => ['department' => [$this->sales->id]], 'include_sub_departments' => true], [$hr]);

    $after = ca9Snapshot($hr);
    // Employees (Workforce) unchanged; time-office records only for Sales and the departments below it.
    expect($after['employees'])->toBe($before['employees'])
        ->and($after['punches'])->toBe(ca9Ids([$this->mgr, $this->e1]))
        ->and($before['punches'])->toBe(ca9Ids([$this->mgr, $this->e1, $this->e2, $this->e4]))
        ->and($after['keys']['payroll'])->toBe($before['keys']['payroll'])
        ->and($after['keys']['time_office'])->toBe(ca9Ids([$this->mgr, $this->e1]));

    // Record level (policies), with the same answers.
    $this->actingAs($hr);
    $punchE4 = AttendancePunch::query()->withoutGlobalScopes()->where('employee_id', $this->e4->id)->first();
    expect(app(AccessScopes::class)->allows($hr, $punchE4))->toBeFalse()
        ->and(app(AccessScopes::class)->allows($hr, $this->e4))->toBeTrue()
        ->and(AttendancePunch::query()->find($punchE4->id))->toBeNull();

    // A group can never reach past the member's own scope: Beta stays out.
    $this->actingAs($this->admin);
    ca9Group(['name' => 'Workforce Beta', 'module' => 'workforce', 'rules' => ['company' => [$this->beta->id]]], [$hr]);
    $wide = ca9Snapshot($hr);
    expect($wide['employees'])->toBe([])->and($wide['punches'])->toBe([]);
    expect(array_intersect($wide['employees'], $before['employees']))->toBe($wide['employees']);
});

it('lets a Workforce group narrow everything about people, and module groups add up', function () {
    $hr = tenantUser($this->tenant, ['employee.view', 'attendance.view']);
    $before = ca9Snapshot($hr);
    expect($before['scoped'])->toBeFalse()->and($before['employees'])->toHaveCount(5);

    $group = ca9Group(['name' => 'Beta people', 'module' => 'workforce', 'basis' => 'employees', 'rules' => ['employee' => [$this->e3->id]]], [$hr]);
    $after = ca9Snapshot($hr);
    expect($after['scoped'])->toBeTrue()
        ->and($after['employees'])->toBe([$this->e3->id])
        ->and($after['punches'])->toBe([$this->e3->id])
        ->and($after['keys']['payroll'])->toBe([$this->e3->id])
        ->and($after['can'][$this->e1->id])->toBeFalse()->and($after['can'][$this->e3->id])->toBeTrue();

    // A second Workforce group adds its people; an inactive group does nothing.
    ca9Group(['name' => 'Level L1', 'module' => 'workforce', 'rules' => ['level' => [$this->levelA->id]]], [$hr]);
    expect(ca9Snapshot($hr)['employees'])->toBe(ca9Ids([$this->e3, $this->e4]));
    app(AccessScopeGroups::class)->save($group, ['name' => 'Beta people', 'module' => 'workforce', 'basis' => 'employees', 'status' => 'inactive',
        'rules' => ['employee' => [$this->e3->id]], 'members' => [$hr->id]], $this->admin, 'Paused');
    expect(ca9Snapshot($hr)['employees'])->toBe([$this->e4->id]);

    // Switching every group off gives back exactly what the person had.
    foreach (AccessScopeGroup::query()->get() as $g) {
        app(AccessScopeGroups::class)->delete($g, $this->admin, 'Done');
    }
    expect(ca9Snapshot($hr))->toBe($before);
});

it('reaches reportees directly or at every level, and departments with their sub-departments', function () {
    $this->actingAs($this->mgrUser);
    ca9Group(['name' => 'Beta and my team', 'module' => 'time_office', 'basis' => 'employees', 'rules' => ['employee' => [$this->e3->id]], 'reportees' => 'direct'], [$this->mgrUser]);
    expect(ca9Snapshot($this->mgrUser)['punches'])->toBe(ca9Ids([$this->mgr, $this->e1, $this->e3]));

    $group = AccessScopeGroup::query()->first();
    app(AccessScopeGroups::class)->save($group, ['name' => 'Beta and my team', 'module' => 'time_office', 'basis' => 'employees', 'reportees' => 'all',
        'rules' => ['employee' => [$this->e3->id]], 'members' => [$this->mgrUser->id]], $this->admin, 'All levels');
    expect(ca9Snapshot($this->mgrUser)['punches'])->toBe(ca9Ids([$this->mgr, $this->e1, $this->e2, $this->e3]));

    // Sub-departments only when asked.
    $hr = tenantUser($this->tenant, ['employee.view']);
    ca9Group(['name' => 'Sales only', 'module' => 'workforce', 'rules' => ['department' => [$this->sales->id]]], [$hr]);
    expect(ca9Snapshot($hr)['employees'])->toBe([$this->mgr->id]);
    AccessScopeGroup::query()->where('name', 'Sales only')->update(['include_sub_departments' => true]);
    expect(ca9Snapshot($hr)['employees'])->toBe(ca9Ids([$this->mgr, $this->e1]));
});

it('scopes users by designation, grade and level (AR-3) without touching anyone else', function () {
    $hr = tenantUser($this->tenant, ['employee.view']);
    app(AccessScopes::class)->assign($hr, ['designation' => [$this->engineer->id]], 'Engineers');
    expect(ca9Snapshot($hr)['employees'])->toBe([$this->e1->id]);
    app(AccessScopes::class)->assign($hr, ['grade' => [$this->gradeA->id]], 'Grade');
    expect(ca9Snapshot($hr)['employees'])->toBe([$this->e1->id]);
    app(AccessScopes::class)->assign($hr, ['level' => [$this->levelA->id]], 'Level');
    expect(ca9Snapshot($hr)['employees'])->toBe([$this->e4->id]);
    app(AccessScopes::class)->assign($hr, ['level' => [$this->levelA->id], 'company' => [$this->beta->id]], 'Both');
    expect(ca9Snapshot($hr)['employees'])->toBe([]);
});

it('fails closed on every malformed group or membership: the member sees nobody at all', function () {
    $hr = tenantUser($this->tenant, ['employee.view', 'attendance.view']);
    $self = ca9Hire('Hari', $this->alpha, $this->locA, $this->ops, user: $hr);
    $group = ca9Group(['name' => 'Ops', 'module' => 'time_office', 'rules' => ['department' => [$this->ops->id]]], [$hr]);
    expect(ca9Snapshot($hr)['employees'])->toHaveCount(6);

    actAsTenant($this->otherTenant);
    $foreign = AccessScopeGroup::query()->create(['name' => 'Elsewhere', 'module' => 'workforce']);
    actAsTenant($this->tenant);

    $corruptions = [
        'unknown module' => fn () => DB::table('access_scope_groups')->where('id', $group->id)->update(['module' => 'recruitment']),
        'unknown basis' => fn () => DB::table('access_scope_groups')->where('id', $group->id)->update(['basis' => 'everyone']),
        'unknown reportees' => fn () => DB::table('access_scope_groups')->where('id', $group->id)->update(['reportees' => 'peers']),
        'unknown status' => fn () => DB::table('access_scope_groups')->where('id', $group->id)->update(['status' => 'archived']),
        'unknown dimension' => fn () => DB::table('access_scope_group_rules')->where('access_scope_group_id', $group->id)->update(['dimension' => 'region']),
        'bad id' => fn () => DB::table('access_scope_group_rules')->where('access_scope_group_id', $group->id)->update(['scope_id' => 0]),
        'employee in an organisation group' => fn () => DB::table('access_scope_group_rules')->where('access_scope_group_id', $group->id)->update(['dimension' => 'employee']),
        'membership of another tenant' => fn () => DB::table('access_scope_group_users')->where('access_scope_group_id', $group->id)->update(['tenant_id' => $this->otherTenant->id]),
        'group of another tenant' => fn () => DB::table('access_scope_group_users')->where('access_scope_group_id', $group->id)->update(['access_scope_group_id' => $foreign->id]),
    ];
    foreach ($corruptions as $label => $corrupt) {
        DB::beginTransaction();
        $corrupt();
        $snapshot = ca9Snapshot($hr);
        expect($snapshot['employees'])->toBe([], $label)
            ->and($snapshot['punches'])->toBe([], $label)
            ->and($snapshot['scoped'])->toBeTrue($label)
            ->and($snapshot['can'][$self->id])->toBeFalse($label)
            ->and(array_filter($snapshot['keys']))->toBe([], $label);
        DB::rollBack();
    }
    app(AccessScopes::class)->forget();
    expect(app(AccessScopeGroups::class)->problems($group->refresh()))->toBe([]);
    DB::table('access_scope_groups')->where('id', $group->id)->update(['module' => 'recruitment']);
    expect(app(AccessScopeGroups::class)->problems($group->refresh()))->not->toBe([]);
});

it('reaches nobody through an unknown dimension in a user\'s own scope rows', function () {
    $hr = tenantUser($this->tenant, ['employee.view']);
    $self = ca9Hire('Hema', $this->alpha, $this->locA, null, user: $hr);
    DB::table('user_access_scopes')->insert(['tenant_id' => $this->tenant->id, 'user_id' => $hr->id, 'dimension' => 'region', 'scope_id' => 1, 'created_at' => now(), 'updated_at' => now()]);
    $snapshot = ca9Snapshot($hr);
    expect($snapshot['employees'])->toBe([])->and($snapshot['can'][$self->id])->toBeFalse()->and($snapshot['scoped'])->toBeTrue();
});

it('keeps primed record answers apart per module', function () {
    $hr = tenantUser($this->tenant, ['employee.view', 'attendance.view']);
    ca9Group(['name' => 'Beta time', 'module' => 'time_office', 'basis' => 'employees', 'rules' => ['employee' => [$this->e3->id]]], [$hr]);
    $this->actingAs($hr);
    $scopes = app(AccessScopes::class);
    app(AuthorizationContext::class)->run(function () use ($scopes, $hr) {
        // Primed for time office (where the group narrows): the answers without a module are not taken from it.
        $scopes->primeEmployeeIds($hr, [$this->e1->id, $this->e3->id], 'time_office');
        expect($scopes->allowsEmployeeId($hr, $this->e1->id, 'time_office'))->toBeFalse()
            ->and($scopes->allowsEmployeeId($hr, $this->e3->id, 'time_office'))->toBeTrue()
            ->and($scopes->allowsEmployeeId($hr, $this->e1->id))->toBeTrue()
            ->and($scopes->allowsEmployeeId($hr, $this->e1->id, 'payroll'))->toBeTrue();
    });
});

it('writes groups only for people allowed, with a reason, visible values and members of this organisation, all audited', function () {
    $service = app(AccessScopeGroups::class);
    $viewer = tenantUser($this->tenant, ['user.view']);
    expect(fn () => $service->save(null, ['name' => 'X', 'module' => 'payroll'], $viewer, 'Nope'))->toThrow(RuntimeException::class, 'cannot change data access')
        ->and(fn () => $service->save(null, ['name' => 'X', 'module' => 'payroll'], $this->admin, ' '))->toThrow(RuntimeException::class, 'reason')
        ->and(fn () => $service->save(null, ['name' => 'X', 'module' => 'recruitment'], $this->admin, 'R'))->toThrow(RuntimeException::class, 'module')
        ->and(fn () => $service->save(null, ['name' => 'X', 'module' => 'payroll', 'rules' => ['region' => [1]]], $this->admin, 'R'))->toThrow(RuntimeException::class, 'Unknown data access dimension')
        ->and(fn () => $service->save(null, ['name' => 'X', 'module' => 'payroll', 'rules' => ['employee' => [$this->e1->id]]], $this->admin, 'R'))->toThrow(RuntimeException::class, 'Specific employees')
        ->and(fn () => $service->save(null, ['name' => 'X', 'module' => 'payroll', 'basis' => 'employees', 'rules' => ['company' => [$this->alpha->id]]], $this->admin, 'R'))->toThrow(RuntimeException::class, 'employees only');

    // Another tenant's people and units are never accepted.
    actAsTenant($this->otherTenant);
    $outsider = tenantUser($this->otherTenant, ['employee.view']);
    $outsiderCompany = Company::factory()->create();
    actAsTenant($this->tenant);
    expect(fn () => $service->save(null, ['name' => 'X', 'module' => 'payroll', 'members' => [$outsider->id]], $this->admin, 'R'))->toThrow(RuntimeException::class, 'this organisation')
        ->and(fn () => $service->save(null, ['name' => 'X', 'module' => 'payroll', 'rules' => ['company' => [$outsiderCompany->id]]], $this->admin, 'R'))->toThrow(RuntimeException::class, 'you can see');
    // A scoped author cannot name what they cannot see.
    $scopedAdmin = tenantUser($this->tenant, ['user.assign_roles', 'employee.view']);
    app(AccessScopes::class)->assign($scopedAdmin, ['company' => [$this->alpha->id]]);
    $this->actingAs($scopedAdmin);
    expect(fn () => $service->save(null, ['name' => 'X', 'module' => 'payroll', 'basis' => 'employees', 'rules' => ['employee' => [$this->e3->id]]], $scopedAdmin, 'R'))->toThrow(RuntimeException::class, 'you can see');
    $this->actingAs($this->admin);

    $member = tenantUser($this->tenant, ['employee.view']);
    $group = $service->save(null, ['name' => 'Ops pay', 'module' => 'payroll', 'rules' => ['department' => [$this->ops->id]], 'members' => [$member->id]], $this->admin, 'Ops payroll team');
    $events = AuditEvent::query()->withoutGlobalScopes()->where('tenant_id', $this->tenant->id)->where('reason', 'Ops payroll team')->pluck('entity_type')->all();
    expect($events)->toContain($group->getMorphClass())->and(count($events))->toBe(3);
    expect($service->groupsOf($member)->pluck('name')->all())->toBe(['Ops pay']);

    // Other tenants are unaffected by this tenant's groups.
    actAsTenant($this->otherTenant);
    expect(AccessScopeGroup::query()->count())->toBe(0);
    actAsTenant($this->tenant);
});

it('offers the data access group screens to the right people', function () {
    $this->get(AccessScopeGroupResource::getUrl('index'))->assertOk();
    $this->get(AccessScopeGroupResource::getUrl('create'))->assertOk();
    Livewire::test(CreateAccessScopeGroup::class)
        ->fillForm(['name' => 'Asset Beta', 'module' => 'asset', 'basis' => 'organisation', 'reportees' => 'none', 'status' => 'active',
            'rules' => ['company' => [(string) $this->beta->id]], 'members' => [(string) $this->mgrUser->id], 'audit_reason' => 'Asset desk for Beta'])
        ->call('create');
    $group = AccessScopeGroup::query()->where('name', 'Asset Beta')->firstOrFail();
    expect(app(AccessScopeGroups::class)->rulesOf($group))->toBe(['company' => [$this->beta->id]])
        ->and(app(AccessScopeGroups::class)->membersOf($group))->toBe([$this->mgrUser->id]);

    $this->actingAs(tenantUser($this->tenant, ['employee.view']));
    $this->get(AccessScopeGroupResource::getUrl('index'))->assertForbidden();
});

it('leaves My space pages out by role, roles adding up, and fails closed on a malformed list', function () {
    $employeeRole = Role::query()->where('slug', 'employee')->firstOrFail();
    $user = $this->mgrUser;
    $user->roles()->attach($employeeRole->id);
    $this->actingAs($user->fresh());
    $space = function () {
        app()->forgetInstance(MySpace::class);
        app()->forgetInstance(ExperiencePreferences::class);

        return collect(app(MySpace::class)->available(auth()->user()))->map(fn ($e) => $e['item']->key())->all();
    };
    $before = $space();
    expect($before)->toContain('preferences');

    // One role leaves it out, the other leaves it in: still shown (roles add up).
    $employeeRole->update(['myspace_hidden_items' => ['preferences', 'my_hr']]);
    expect($space())->toBe($before);
    // Every role leaves it out: gone from My space (the page itself is untouched).
    $user->roles()->where('roles.id', '!=', $employeeRole->id)->first()->update(['myspace_hidden_items' => ['preferences']]);
    expect($space())->toBe(array_values(array_diff($before, ['preferences'])))
        ->and(Preferences::canAccess())->toBeTrue();
    // A malformed list on the only role leaves everything out; nothing is ever added.
    $user->roles()->detach($employeeRole->id);
    DB::table('roles')->where('id', $user->roles()->first()->id)->update(['myspace_hidden_items' => json_encode(['not' => 'a list'])]);
    expect($space())->toBe([]);
    DB::table('roles')->where('id', $user->roles()->first()->id)->update(['myspace_hidden_items' => null]);
    expect(array_diff($space(), $before))->toBe([]);
});

it('switches Home cards off and orders them for everyone, by the Setup centre only', function () {
    $user = $this->mgrUser;
    $this->actingAs($user);
    $home = fn () => app(HomeComposer::class)->for($user->fresh());
    $before = $home();
    expect(HomeComposer::organisationHidden())->toBe([]);

    $settings = app(SettingsRepository::class);
    $settings->set('home.hidden_blocks', ['birthdays', 'decisions'], 'Quiet Home');
    $after = $home();
    expect($after['main'])->toBe(array_values(array_diff($before['main'], ['decisions'])))
        ->and($after['side'])->toBe(array_values(array_diff($before['side'], ['birthdays'])))
        ->and($after['birthdays'])->toBeNull();

    $settings->set('home.hidden_blocks', [], 'Back');
    $settings->set('home.block_order', 'changes, unknown_card', 'Changes first');
    $ordered = $home();
    expect($ordered['main'][0])->toBe(in_array('changes', $before['main'], true) ? 'changes' : $before['main'][0])
        ->and(collect($ordered['main'])->sort()->values()->all())->toBe(collect($before['main'])->sort()->values()->all());

    // Fail closed: a stored value that is not a list switches every card off.
    $settings->set('home.block_order', null, 'Default');
    $settings->set('home.hidden_blocks', 'birthdays', 'Corrupt');
    expect($home()['main'])->toBe([])->and($home()['side'])->toBe([]);
    $settings->set('home.hidden_blocks', [], 'Fixed');
    expect($home()['main'])->toBe($before['main'])->and($home()['side'])->toBe($before['side']);

    $catalogue = app(SetupCatalogue::class);
    expect(fn () => $catalogue->save('access', [SetupCatalogue::field('home.hidden_blocks') => ['no_such_card']], 'x', $this->admin))->toThrow(RuntimeException::class, 'does not exist')
        ->and(fn () => $catalogue->save('access', [SetupCatalogue::field('home.block_order') => 'Day; drop table'], 'x', $this->admin))->toThrow(RuntimeException::class, 'expected format');
    expect($catalogue->save('access', [SetupCatalogue::field('home.hidden_blocks') => ['wall']], 'No wall', $this->admin))->toBe(['home.hidden_blocks']);
});

it('shows a manager their team\'s events and HR their scope\'s, only the kinds chosen and only records they may open', function () {
    $this->e1->person->update(['birthday_month' => 11, 'birthday_day' => 12, 'birthday_shared' => true]);
    $this->e3->person->update(['birthday_month' => 11, 'birthday_day' => 15, 'birthday_shared' => true]);
    $this->e1->update(['probation_end_date' => '2026-11-20']);
    $this->e3->update(['probation_end_date' => '2026-11-21']);

    $this->actingAs($this->mgrUser);
    $calendar = app(TeamCalendarModel::class);
    expect($calendar->audiences($this->mgrUser))->toBe(['team' => 'My team']);
    $events = collect($calendar->events($this->mgrUser, 'team', 2026, 11))->flatten(1);
    expect($events->pluck('employee_id')->unique()->values()->all())->toBe([$this->e1->id])
        ->and($events->pluck('kind')->sort()->values()->all())->toBe(['birthday', 'confirmation']);
    // A kind the organisation hides from managers is gone; a stored value that is not a list shows nothing.
    app(SettingsRepository::class)->set('calendar.manager_events', ['confirmation'], 'Less');
    expect(collect($calendar->events($this->mgrUser, 'team', 2026, 11))->flatten(1)->pluck('kind')->all())->toBe(['confirmation']);
    app(SettingsRepository::class)->set('calendar.manager_events', 'birthday', 'Corrupt');
    expect($calendar->kinds($this->mgrUser, 'team'))->toBe([])->and($calendar->events($this->mgrUser, 'team', 2026, 11))->toBe([]);
    // The organisation audience is not the manager's.
    expect($calendar->events($this->mgrUser, 'organisation', 2026, 11))->toBe([]);
    $this->get(TeamCalendar::getUrl())->assertOk()->assertSee('Team calendar');

    // HR (scoped to Alpha) sees Alpha's events, never Beta's; not for another signed-in person.
    $hr = tenantUser($this->tenant, ['employee.view', 'employee.update', 'celebration.view']);
    app(AccessScopes::class)->assign($hr, ['company' => [$this->alpha->id]]);
    $this->actingAs($hr);
    app()->forgetInstance(RoleLens::class);
    $hrEvents = collect(app(TeamCalendarModel::class)->events($hr, 'organisation', 2026, 11))->flatten(1);
    expect($hrEvents->pluck('employee_id')->unique()->values()->all())->toBe([$this->e1->id])
        ->and(app(TeamCalendarModel::class)->events($this->mgrUser, 'team', 2026, 11))->toBe([]);

    // Neither a manager nor HR: no calendar.
    $plain = tenantUser($this->tenant, ['employee.view']);
    $this->actingAs($plain);
    app()->forgetInstance(RoleLens::class);
    expect(TeamCalendar::canAccess())->toBeFalse();
    $this->get(TeamCalendar::getUrl())->assertForbidden();
});

it('shows check-in locations to nobody by default and the headcount to HR, managers only when chosen, audits each opening, and fails closed', function () {
    $hr = tenantUser($this->tenant, ['employee.view', 'employee.update', 'attendance.view']);
    app(AccessScopes::class)->assign($hr, ['company' => [$this->alpha->id]]);
    $this->actingAs($hr);
    $geo = app(GeoDashboardModel::class);
    expect($geo->panels($hr))->toBe(['headcount' => 'hr'])->and($geo->checkins($hr))->toHaveCount(0);
    app(SettingsRepository::class)->set('geo_dashboard.checkins_visible_to', ['hr'], 'HR sees check-ins');
    expect($geo->panels($hr))->toBe(['checkins' => 'hr', 'headcount' => 'hr'])
        ->and(ca9Ids($geo->checkins($hr), 'employee_id'))->toBe(ca9Ids([$this->mgr, $this->e1, $this->e2, $this->e4]))
        ->and(collect($geo->headcount($hr))->sum('count'))->toBe(4);
    $this->get(GeoDashboard::getUrl())->assertOk();
    expect(AuditEvent::query()->withoutGlobalScopes()->where('tenant_id', $this->tenant->id)->where('reason', 'Geo dashboard opened')->count())->toBe(1);

    // Managers: nothing by default; their own team only once chosen.
    $this->actingAs($this->mgrUser);
    app()->forgetInstance(RoleLens::class);
    expect($geo->panels($this->mgrUser))->toBe([])->and(GeoDashboard::canAccess())->toBeFalse();
    app(SettingsRepository::class)->set('geo_dashboard.checkins_visible_to', ['hr', 'manager'], 'Managers too');
    expect($geo->panels($this->mgrUser))->toBe(['checkins' => 'manager'])
        ->and(ca9Ids($geo->checkins($this->mgrUser), 'employee_id'))->toBe([$this->e1->id])
        ->and($geo->headcount($this->mgrUser))->toBe([]);
    app(SettingsRepository::class)->set('geo_dashboard.checkins_visible_to', 'manager', 'Corrupt');
    expect($geo->panels($this->mgrUser))->toBe([]);
});
