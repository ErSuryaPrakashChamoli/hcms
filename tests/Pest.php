<?php

use App\Domain\Compensation\Models\EmployeeSalaryAssignment;
use App\Domain\Compensation\Models\SalaryStructure;
use App\Domain\Compensation\Models\SalaryStructureVersion;
use App\Domain\Compensation\Services\CompensationChanges;
use App\Domain\Compensation\Services\CompensationStructures;
use App\Domain\Employment\Models\Employee;
use App\Domain\Identity\Models\Role;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Scopes\AccessScope;
use App\Domain\Identity\Services\PermissionRegistry;
use App\Domain\Lifecycle\Enums\LifecycleState;
use App\Domain\Lifecycle\Services\LifecycleEngine;
use App\Domain\Platform\Actions\ProvisionTenantAction;
use App\Domain\Platform\Models\Tenant;
use App\Support\Tenancy\TenantContext;
use Faker\Provider\Base;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

// Phase 8: MySQL concurrency tests manage their own disposable database (no RefreshDatabase).
// UX.18: that database keeps its users for the whole run (it is migrated once; the tests fork, so they cannot use a
// transaction), while Faker's unique() starts over with each test's fresh application, so two tests could draw the same
// safeEmail() and collide on users_email_unique. Factory e-mails here come from a per-process sequence instead: never
// repeated within a run, and the database is migrated fresh at the start of every run.
pest()->extend(TestCase::class)
    ->beforeEach(fn () => fake()->addProvider(new class(fake()) extends Base
    {
        private static int $sequence = 0;

        public function safeEmail(): string
        {
            return 'concurrency-'.getmypid().'-'.(++self::$sequence).'@example.test';
        }
    }))
    ->in('MySql');

/*
|--------------------------------------------------------------------------
| Tenancy helpers
|--------------------------------------------------------------------------
*/

/** Provision a fully seeded tenant (system roles, features, settings) with no admin user. */
function provisionTenant(string $name = 'Acme'): Tenant
{
    app(PermissionRegistry::class)->sync();

    return app(ProvisionTenantAction::class)->handle([
        'name' => $name,
        'slug' => Str::slug($name).'-'.Str::lower(Str::random(4)),
    ]);
}

function actAsTenant(?Tenant $tenant): void
{
    app(TenantContext::class)->set($tenant);
}

/**
 * A tenant user holding one ad-hoc role with the given permission patterns (`company.*`, `*`).
 *
 * @param  list<string>  $permissions
 */
function tenantUser(Tenant $tenant, array $permissions = [], array $attributes = []): User
{
    return app(TenantContext::class)->runAs($tenant, function () use ($tenant, $permissions, $attributes) {
        $user = User::factory()->forTenant($tenant)->create($attributes);

        if ($permissions !== []) {
            $role = Role::factory()->create();
            $role->permissions()->sync(app(PermissionRegistry::class)->idsMatching($permissions));
            $user->roles()->attach($role);
        }

        return $user;
    });
}

function platformAdmin(): User
{
    return User::factory()->platformAdmin()->create();
}

/**
 * Put an employee directly into a lifecycle state for test setup, bypassing the engine's guard
 * (production code must use LifecycleEngine::transition()).
 *
 * @param  array<string, mixed>  $extra
 */
function forceLifecycle(Employee $employee, LifecycleState|string $state, array $extra = []): Employee
{
    LifecycleEngine::unguarded(fn () => $employee->update(['lifecycle_state' => $state] + $extra));

    return $employee;
}

/*
|--------------------------------------------------------------------------
| Compensation helpers (Phase 11)
|--------------------------------------------------------------------------
*/

/**
 * Four users, one per duty, for the current tenant (created once per test application): compensation
 * is written only through propose → review → approve → execute by different people.
 *
 * @return array{proposer: User, reviewer: User, approver: User, executor: User}
 */
function compensationActors(): array
{
    $tenant = app(TenantContext::class)->current();
    $key = 'tests.compensation.actors.'.$tenant->id;
    if (! app()->bound($key)) {
        app()->instance($key, [
            'proposer' => tenantUser($tenant, ['compensation.propose', 'compensation.view'], ['name' => 'Comp Proposer']),
            'reviewer' => tenantUser($tenant, ['compensation.review', 'compensation.view'], ['name' => 'Comp Reviewer']),
            'approver' => tenantUser($tenant, ['compensation.approve', 'compensation.view'], ['name' => 'Comp Approver']),
            'executor' => tenantUser($tenant, ['compensation.execute', 'compensation.view'], ['name' => 'Comp Executor']),
        ]);
    }

    return app($key);
}

/**
 * Give an employee compensation the only way PeopleOS allows: a change proposed, reviewed, approved
 * and executed by four different people. Returns the canonical row the change wrote.
 *
 * @param  array<string, float>  $componentValues
 */
function compensate(Employee $employee, float $ctcAnnual, string $from, array $componentValues = ['CONV' => 1600], string $changeType = 'hire', string $reason = 'test', string $structureCode = 'STANDARD'): EmployeeSalaryAssignment
{
    $actors = compensationActors();
    $changes = app(CompensationChanges::class);
    $structure = SalaryStructure::query()->where('code', $structureCode)->firstOrFail();
    $change = $changes->propose($employee, ['change_type' => $changeType, 'effective_from' => $from, 'salary_structure_id' => $structure->id, 'ctc_annual' => $ctcAnnual, 'component_values' => $componentValues, 'reason' => $reason], $actors['proposer']);
    $changes->submit($change, $actors['proposer']);
    $changes->review($change, $actors['reviewer']);
    $changes->approve($change, $actors['approver']);
    $changes->schedule($change, $actors['executor']);

    return EmployeeSalaryAssignment::query()->withoutGlobalScopes([AccessScope::class])->findOrFail($change->employee_salary_assignment_id);
}

/**
 * A new approved version of a structure (prepared and approved by two different people) with extra
 * components: approved versions are immutable, so a change is always a new version.
 *
 * @param  array<int, int>  $addComponents  salary_component_id => sort_order
 * @param  array<string, mixed>  $attributes
 */
function approveStructureVersion(string $structureCode, string $from, array $addComponents = [], array $attributes = []): SalaryStructureVersion
{
    $tenant = app(TenantContext::class)->current();
    $preparer = tenantUser($tenant, ['compensation.configure']);
    $approver = tenantUser($tenant, ['compensation.approve']);
    $structures = app(CompensationStructures::class);
    $version = $structures->newVersion(SalaryStructure::query()->where('code', $structureCode)->firstOrFail(), $preparer, $from);
    $rows = $version->components()->get()->map(fn ($c) => $c->only(['salary_component_id', 'formula_override', 'pay_nature', 'frequency', 'sort_order']))->all();
    foreach ($addComponents as $componentId => $sort) {
        $rows[] = ['salary_component_id' => $componentId, 'sort_order' => $sort];
    }
    $structures->updateDraft($version, ['components' => $rows] + $attributes, $preparer);
    $structures->submit($version, $preparer);

    return $structures->approve($version, $approver);
}
