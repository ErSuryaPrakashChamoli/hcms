<?php

use App\Domain\Attendance\Models\AttendanceRegularisation;
use App\Domain\Employment\Actions\ChangeManagerAction;
use App\Domain\Employment\Actions\HireEmployeeAction;
use App\Domain\Employment\Models\Employee;
use App\Domain\Experience\Services\ApprovalCenter;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Services\AccessScopes;
use App\Domain\Identity\Services\AuthorizationContext;
use App\Domain\Leave\Models\LeaveRequest;
use App\Domain\Leave\Models\LeaveType;
use App\Domain\Lifecycle\Enums\LifecycleState;
use App\Domain\Organisation\Models\Company;
use App\Domain\Organisation\Models\Department;
use App\Domain\Organisation\Models\Team;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/../Leave/LeaveTestHelpers.php';

/*
| UX.15 closure P1-02: the approval queue no longer pays two authorisation queries per pending item, and
| decides exactly as before. Proof: an oracle that re-implements the pre-change checks literally (permission,
| own record by `exists`, scope by `employeeKeys()->where(id)->exists()`), compared with every policy decision
| made outside an authorisation pass and inside a primed pass, for every viewer and every request, across
| scopes, relationships, organisations, own requests, missing permissions, suspension, permission changes and
| the tenant boundary.
*/

beforeEach(function () {
    $this->travelTo('2026-10-05 09:00:00');
    $this->tenant = provisionTenant();
    actAsTenant($this->tenant);
    leavePolicy(['EL' => ['days' => 24, 'accrual_frequency' => 'annual']]);
    $this->type = LeaveType::query()->where('code', 'EL')->first();
    $this->x = Company::factory()->create();
    $this->y = Company::factory()->create();
    $this->d1 = Department::query()->create(['company_id' => $this->x->id, 'name' => 'Engineering', 'code' => 'D1', 'status' => 'active']);
    $this->d2 = Department::query()->create(['company_id' => $this->x->id, 'name' => 'Design', 'code' => 'D2', 'status' => 'active']);
    $this->d3 = Department::query()->create(['company_id' => $this->y->id, 'name' => 'Services', 'code' => 'D3', 'status' => 'active']);
    $hire = fn (string $name, Company $c, Department $d, ?Employee $manager, ?User $user = null) => tap(app(HireEmployeeAction::class)->handle(
        ['first_name' => $name, 'last_name' => 'Case'], ['joining_date' => '2024-01-01', 'user_id' => $user?->id],
        ['company_id' => $c->id, 'department_id' => $d->id], $manager?->id,
    ), fn (Employee $e) => forceLifecycle($e, LifecycleState::Active));

    $this->managerUser = tenantUser($this->tenant, ['leave.view', 'leave.approve', 'attendance.view', 'attendance.approve']);
    $this->m = $hire('Manager', $this->x, $this->d1, null, $this->managerUser);
    $this->direct = $hire('Direct', $this->x, $this->d1, $this->m, tenantUser($this->tenant, ['leave.apply']));
    $this->indirect = $hire('Indirect', $this->x, $this->d1, $this->direct);
    $this->dotted = $hire('Dotted', $this->x, $this->d2, null);
    app(ChangeManagerAction::class)->handle($this->dotted, $this->m, 'dotted', '2024-02-01', 'test');
    $this->unrelated = $hire('Unrelated', $this->x, $this->d2, null);
    $this->crossOrg = $hire('Elsewhere', $this->y, $this->d3, null);
    $this->people = [$this->m, $this->direct, $this->indirect, $this->dotted, $this->unrelated, $this->crossOrg];

    foreach ($this->people as $i => $e) {
        LeaveRequest::query()->create(['employee_id' => $e->id, 'leave_type_id' => $this->type->id, 'from_date' => '2026-10-2'.$i, 'to_date' => '2026-10-2'.$i,
            'from_session' => 'full', 'to_session' => 'full', 'days' => 1, 'reason' => 'Case', 'status' => 'pending']);
        AttendanceRegularisation::query()->create(['employee_id' => $e->id, 'date' => '2026-10-0'.($i + 1), 'type' => array_key_first(config('peopleos.attendance.regularisation_types')),
            'reason' => 'Case', 'status' => 'pending']);
    }
    // Another tenant's request: never reachable from this tenant, whatever the path.
    $other = provisionTenant('Other');
    actAsTenant($other);
    leavePolicy(['EL' => ['days' => 24, 'accrual_frequency' => 'annual']]);
    $outsider = tap(app(HireEmployeeAction::class)->handle(['first_name' => 'Outsider', 'last_name' => 'Case'], ['joining_date' => '2024-01-01'], ['company_id' => Company::factory()->create()->id]), fn (Employee $e) => forceLifecycle($e, LifecycleState::Active));
    $this->foreign = LeaveRequest::query()->create(['employee_id' => $outsider->id, 'leave_type_id' => LeaveType::query()->where('code', 'EL')->value('id'), 'from_date' => '2026-10-28', 'to_date' => '2026-10-28',
        'from_session' => 'full', 'to_session' => 'full', 'days' => 1, 'reason' => 'Case', 'status' => 'pending']);
    actAsTenant($this->tenant);
});

/** The pre-change checks, re-implemented literally. */
function preChangeAllows(User $user, mixed $model): bool
{
    $scopes = app(AccessScopes::class);
    if (($model->getAttributes()['tenant_id'] ?? null) !== null && (int) $model->getAttributes()['tenant_id'] !== (int) app(TenantContext::class)->id()) {
        return false;
    }
    if (! $scopes->isScoped($user)) {
        return true;
    }

    return $model->employee_id === null || $scopes->employeeKeys($user)->where('employees.id', $model->employee_id)->exists();
}

function preChangeIsOwn(User $user, mixed $model): bool
{
    return Employee::query()->where('user_id', $user->id)->where('id', $model->employee_id)->exists();
}

function preChangeApprove(User $user, mixed $model, string $permission): bool
{
    return $user->hasPermission($permission) && preChangeAllows($user, $model) && ! preChangeIsOwn($user, $model);
}

/** Every viewer × every request: oracle, policy outside a pass, policy inside a primed pass. */
function approvalDecisions(User $viewer, array $models, string $permission): array
{
    test()->actingAs($viewer);
    app(AccessScopes::class)->forget();
    $ids = collect($models)->pluck('employee_id')->all();
    $out = [];
    foreach ($models as $model) {
        $key = class_basename($model).':'.$model->id;
        $oracle = preChangeApprove($viewer, $model, $permission);
        $outside = $viewer->can('approve', $model);
        $inside = app(AuthorizationContext::class)->run(function () use ($viewer, $ids, $model) {
            app(AccessScopes::class)->primeEmployeeIds($viewer, $ids);

            return $viewer->can('approve', $model);
        });
        $out[$key] = [$oracle, $outside, $inside];
    }

    return $out;
}

it('decides every approval exactly as before, outside and inside an authorisation pass, for every scope and relationship', function () {
    $leave = LeaveRequest::query()->orderBy('id')->get()->all();
    $regs = AttendanceRegularisation::query()->orderBy('id')->get()->all();
    $foreign = app(TenantContext::class)->bypass(fn () => LeaveRequest::query()->whereKey($this->foreign->id)->first());
    $team = Team::query()->create(['company_id' => $this->x->id, 'name' => 'Nobody yet', 'code' => 'T0', 'status' => 'active']);
    $scopes = app(AccessScopes::class);

    $viewers = [
        'manager, tenant-wide (no scope rows)' => fn () => $this->managerUser,
        'manager, department scope' => function () use ($scopes) {
            $scopes->assign($this->managerUser, ['department' => [$this->d1->id]]);

            return $this->managerUser->fresh();
        },
        'manager, relationship only (empty team)' => function () use ($scopes, $team) {
            $scopes->assign($this->managerUser, ['team' => [$team->id]]);

            return $this->managerUser->fresh();
        },
        'manager, other organisation' => function () use ($scopes) {
            $scopes->assign($this->managerUser, ['company' => [$this->y->id]]);

            return $this->managerUser->fresh();
        },
        'unauthorised manager (no approve permission)' => fn () => tenantUser($this->tenant, ['leave.view', 'attendance.view']),
        'HR approver without an employee record' => fn () => tenantUser($this->tenant, ['leave.view', 'leave.approve', 'attendance.approve']),
        'suspended approver' => fn () => tap(tenantUser($this->tenant, ['leave.approve', 'attendance.approve']), fn (User $u) => $u->forceFill(['status' => 'suspended'])->save()),
        'manager after losing approve permission' => function () use ($scopes) {
            $scopes->assign($this->managerUser, []);
            $this->managerUser->roles->each(fn ($role) => $role->permissions()->detach($role->permissions()->whereIn('key', ['leave.approve', 'attendance.approve'])->pluck('permissions.id')));

            return User::query()->find($this->managerUser->id);
        },
    ];

    $matrix = [];
    foreach ($viewers as $name => $make) {
        $viewer = $make();
        foreach ([[$leave, 'leave.approve'], [$regs, 'attendance.approve'], [[$foreign], 'leave.approve']] as [$models, $permission]) {
            foreach (approvalDecisions($viewer, $models, $permission) as $key => [$oracle, $outside, $inside]) {
                $matrix[$name][$key] = $oracle;
                expect($outside)->toBe($oracle, "$name / $key outside a pass")
                    ->and($inside)->toBe($oracle, "$name / $key inside a primed pass");
            }
        }
    }

    // Sanity on the outcomes themselves (the model, not just the equivalence).
    $leaveOf = fn (Employee $e) => 'LeaveRequest:'.LeaveRequest::query()->where('employee_id', $e->id)->value('id');
    expect($matrix['manager, tenant-wide (no scope rows)'][$leaveOf($this->unrelated)])->toBeTrue()
        ->and($matrix['manager, department scope'][$leaveOf($this->crossOrg)])->toBeFalse()
        ->and($matrix['manager, department scope'][$leaveOf($this->indirect)])->toBeTrue()
        ->and($matrix['manager, relationship only (empty team)'][$leaveOf($this->direct)])->toBeTrue()
        ->and($matrix['manager, relationship only (empty team)'][$leaveOf($this->dotted)])->toBeTrue()
        ->and($matrix['manager, relationship only (empty team)'][$leaveOf($this->indirect)])->toBeFalse()
        ->and($matrix['manager, relationship only (empty team)'][$leaveOf($this->unrelated)])->toBeFalse()
        ->and($matrix['manager, other organisation'][$leaveOf($this->crossOrg)])->toBeTrue()
        ->and(collect($matrix)->every(fn ($row) => $row[$leaveOf($this->m)] === false || ! str_starts_with(array_search($row, $matrix, true), 'manager')))->toBeTrue()
        ->and(collect($matrix['unauthorised manager (no approve permission)'])->filter()->all())->toBe([])
        ->and(collect($matrix['manager after losing approve permission'])->filter()->all())->toBe([])
        ->and(collect($matrix)->every(fn ($row) => $row['LeaveRequest:'.$this->foreign->id] === false))->toBeTrue();
});

it('builds the same queue as the pre-change checks would, for scoped and tenant-wide viewers', function () {
    $team = Team::query()->create(['company_id' => $this->x->id, 'name' => 'Nobody yet', 'code' => 'T0', 'status' => 'active']);
    foreach ([[], ['team' => [$team->id]], ['department' => [$this->d1->id]]] as $scope) {
        app(AccessScopes::class)->assign($this->managerUser, $scope);
        $viewer = $this->managerUser->fresh();
        $this->actingAs($viewer);
        $expected = LeaveRequest::query()->where('status', 'pending')->get()->filter(fn ($r) => preChangeApprove($viewer, $r, 'leave.approve'))->map(fn ($r) => 'leave:'.$r->id)
            ->merge(AttendanceRegularisation::query()->where('status', 'pending')->get()->filter(fn ($r) => preChangeApprove($viewer, $r, 'attendance.approve'))->map(fn ($r) => 'regularisation:'.$r->id))
            ->sort()->values()->all();
        $actual = (new ApprovalCenter(app(TenantContext::class)))->pending($viewer)->pluck('id')->sort()->values()->all();

        expect($actual)->toBe($expected, json_encode($scope));
    }
});

it('spends no authorisation query per additional pending item, and keeps no fact after the pass', function () {
    app(AccessScopes::class)->assign($this->managerUser, ['department' => [$this->d1->id]]);
    $viewer = $this->managerUser->fresh();
    $this->actingAs($viewer);
    $reports = collect(range(1, 40))->map(fn ($i) => tap(app(HireEmployeeAction::class)->handle(['first_name' => 'R'.$i, 'last_name' => 'Case'], ['joining_date' => '2024-01-01'],
        ['company_id' => $this->x->id, 'department_id' => $this->d1->id], $this->m->id), fn (Employee $e) => forceLifecycle($e, LifecycleState::Active)));
    $queries = function () use ($viewer) {
        DB::flushQueryLog();
        DB::enableQueryLog();
        (new ApprovalCenter(app(TenantContext::class)))->pending($viewer);
        $n = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $n;
    };
    $file = fn ($e, $i) => LeaveRequest::query()->create(['employee_id' => $e->id, 'leave_type_id' => $this->type->id, 'from_date' => now()->addDays(10 + $i)->toDateString(), 'to_date' => now()->addDays(10 + $i)->toDateString(),
        'from_session' => 'full', 'to_session' => 'full', 'days' => 1, 'reason' => 'Case', 'status' => 'pending']);
    $reports->take(10)->each($file);
    $ten = $queries();
    $reports->slice(10)->each($file);
    $forty = $queries();

    expect(($forty - $ten) / 30)->toBeLessThanOrEqual(0.1)
        ->and(app(AuthorizationContext::class)->active())->toBeFalse()
        ->and(app(AuthorizationContext::class)->has('scope:'.app(TenantContext::class)->id().':'.$viewer->id.':'.$reports->first()->id))->toBeFalse();
});
