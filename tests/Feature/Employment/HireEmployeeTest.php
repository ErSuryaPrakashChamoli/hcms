<?php

use App\Domain\Audit\Models\AuditEvent;
use App\Domain\Employment\Actions\HireEmployeeAction;
use App\Domain\Employment\Models\Employee;
use App\Domain\Employment\Services\EmployeeCodeGenerator;
use App\Domain\Lifecycle\Enums\LifecycleState;
use App\Domain\Organisation\Models\Company;
use App\Domain\Organisation\Models\Department;
use App\Domain\Organisation\Models\Designation;
use App\Domain\People\Models\Person;
use App\Domain\Platform\Services\SettingsRepository;

beforeEach(function () {
    $this->tenant = provisionTenant();
    actAsTenant($this->tenant);
    $this->actingAs(tenantUser($this->tenant, ['employee.*']));
    $this->company = Company::factory()->create();
    $this->department = Department::factory()->create(['company_id' => $this->company->id]);
    $this->designation = Designation::factory()->create(['name' => 'Engineer']);
});

function hire(array $overrides = []): Employee
{
    return app(HireEmployeeAction::class)->handle(
        person: $overrides['person'] ?? ['first_name' => 'Rahul', 'last_name' => 'Sharma', 'personal_email' => fake()->unique()->safeEmail()],
        employee: $overrides['employee'] ?? ['joining_date' => '2026-09-01', 'work_email' => fake()->unique()->companyEmail()],
        position: $overrides['position'] ?? ['company_id' => test()->company->id, 'department_id' => test()->department->id, 'designation_id' => test()->designation->id],
        managerId: $overrides['manager_id'] ?? null,
        reason: $overrides['reason'] ?? 'Offer accepted',
    );
}

it('hires a person into a position with a generated code and probation state', function () {
    $employee = hire();

    expect($employee->employee_code)->toBe('EMP00001')
        ->and($employee->lifecycle_state)->toBe(LifecycleState::Probation)
        ->and($employee->joining_date->toDateString())->toBe('2026-09-01')
        ->and($employee->probation_end_date->toDateString())->toBe('2027-03-01')
        ->and($employee->person->full_name)->toBe('Rahul Sharma')
        ->and($employee->currentPosition->department_id)->toBe($this->department->id)
        ->and($employee->currentPosition->designation->name)->toBe('Engineer')
        ->and($employee->currentPosition->change_type)->toBe('hire')
        ->and($employee->currentPosition->effective_from->toDateString())->toBe('2026-09-01');
});

it('writes the timeline and audit trail for a hire', function () {
    $manager = hire(['person' => ['first_name' => 'Amit', 'last_name' => 'Verma']]);
    $employee = hire(['manager_id' => $manager->id]);

    $titles = $employee->timelineEntries()->reorder('id')->pluck('title')->all();

    expect($titles)->toBe(['Position assigned', 'Line manager assigned', 'Joined company', 'Started probation'])
        ->and($employee->currentManager->manager_id)->toBe($manager->id)
        ->and($employee->timelineEntries()->where('category', 'reporting')->value('description'))->toContain('Amit Verma')
        ->and(AuditEvent::query()->where('entity_type', Employee::class)->where('entity_id', (string) $employee->id)->pluck('action')->map->value->all())
        ->toContain('CREATE', 'JOINED', 'MANAGER_CHANGED')
        ->and(AuditEvent::query()->where('entity_type', Person::class)->where('action', 'CREATE')->latest('occurred_at')->value('reason'))->toBe('Offer accepted');
});

it('puts future joiners into preboarding and honours an explicit code and probation date', function () {
    $employee = hire(['employee' => ['joining_date' => now()->addMonth()->toDateString(), 'employee_code' => 'X-42', 'probation_end_date' => now()->addMonths(9)->toDateString()]]);

    expect($employee->lifecycle_state)->toBe(LifecycleState::Preboarding)
        ->and($employee->employee_code)->toBe('X-42')
        ->and($employee->probation_end_date->toDateString())->toBe(now()->addMonths(9)->toDateString());
});

it('generates sequential codes per tenant using tenant settings', function () {
    hire();
    hire();

    expect(app(EmployeeCodeGenerator::class)->next())->toBe('EMP00003');

    app(SettingsRepository::class)->set('employee.code.prefix', 'ACME-');
    app(SettingsRepository::class)->set('employee.code.padding', 3);

    expect(app(EmployeeCodeGenerator::class)->next())->toBe('ACME-001');

    $other = provisionTenant('Other');
    actAsTenant($other);
    expect(app(EmployeeCodeGenerator::class)->next())->toBe('EMP00001');
});

it('rejects a position without a company and rolls back the whole hire', function () {
    expect(fn () => hire(['position' => ['department_id' => $this->department->id]]))->toThrow(InvalidArgumentException::class);

    expect(Employee::query()->count())->toBe(0)
        ->and(Person::query()->count())->toBe(0);
});

it('reuses an existing person for a rehire within the same tenant', function () {
    $person = Person::factory()->create();

    $employee = hire(['person' => ['id' => $person->id]]);

    expect($employee->person_id)->toBe($person->id)
        ->and(Person::query()->count())->toBe(1);
});

it('keeps employees isolated per tenant', function () {
    hire();
    actAsTenant(provisionTenant('Other'));

    expect(Employee::query()->count())->toBe(0)
        ->and(Person::query()->count())->toBe(0);
});
