<?php

use App\Domain\Audit\Models\AuditEvent;
use App\Domain\Employment\Actions\ChangeManagerAction;
use App\Domain\Employment\Actions\HireEmployeeAction;
use App\Domain\Employment\Actions\PromoteEmployeeAction;
use App\Domain\Employment\Actions\RehireEmployeeAction;
use App\Domain\Employment\Actions\TransferEmployeeAction;
use App\Domain\Employment\Events\EmploymentEvent;
use App\Domain\Employment\Exceptions\DuplicatePersonException;
use App\Domain\Employment\Exceptions\OverlappingAssignmentException;
use App\Domain\Employment\Models\Employee;
use App\Domain\Employment\Services\EmployeeCodeGenerator;
use App\Domain\Lifecycle\Enums\LifecycleState;
use App\Domain\Lifecycle\Exceptions\InvalidLifecycleTransitionException;
use App\Domain\Lifecycle\Services\LifecycleEngine;
use App\Domain\Organisation\Models\Company;
use App\Domain\Organisation\Models\Department;
use App\Domain\Organisation\Models\Designation;
use App\Domain\Organisation\Models\Location;
use App\Domain\People\Actions\UpdatePersonAction;
use App\Domain\People\Models\Person;
use App\Domain\People\Services\PersonMatcher;
use Illuminate\Support\Facades\Event;

/* Phase 1: employment foundation — events, promotion/transfer/rehire actions, overlap rules, codes, duplicates, lifecycle guard. */

beforeEach(function () {
    $this->travelTo('2026-09-21 09:00:00');
    $this->tenant = provisionTenant();
    actAsTenant($this->tenant);
    $this->hr = tenantUser($this->tenant, ['*']);
    $this->actingAs($this->hr);
    $this->company = Company::factory()->create(['name' => 'Alpha']);
    $this->delhi = Location::factory()->create(['company_id' => $this->company->id, 'name' => 'Delhi']);
    $this->mumbai = Location::factory()->create(['company_id' => $this->company->id, 'name' => 'Mumbai']);
    $this->sales = Department::factory()->create(['company_id' => $this->company->id, 'name' => 'Sales']);
    $this->bizdev = Department::factory()->create(['company_id' => $this->company->id, 'name' => 'Business Development']);
    $this->exec = Designation::factory()->create(['name' => 'Senior Executive']);
    $this->manager = Designation::factory()->create(['name' => 'Manager']);
    $this->boss = app(HireEmployeeAction::class)->handle(['first_name' => 'Boss', 'last_name' => 'One'], ['joining_date' => '2024-01-01'], ['company_id' => $this->company->id, 'location_id' => $this->delhi->id]);
});

function hireAsha(): Employee
{
    return app(HireEmployeeAction::class)->handle(
        ['first_name' => 'Asha', 'last_name' => 'Rao', 'personal_email' => 'asha@example.test', 'date_of_birth' => '1990-05-05'],
        ['joining_date' => '2026-01-01', 'work_email' => 'asha.rao@alpha.test'],
        ['company_id' => test()->company->id, 'location_id' => test()->delhi->id, 'department_id' => test()->sales->id, 'designation_id' => test()->exec->id],
        test()->boss->id,
    );
}

it('emits employee.created on hire and keeps the person, employee and position linked', function () {
    Event::fake([EmploymentEvent::class]);
    $asha = hireAsha();

    Event::assertDispatched(EmploymentEvent::class, fn (EmploymentEvent $e) => $e->name === 'employee.created' && $e->employee->is($asha));
    expect($asha->person->first_name)->toBe('Asha')
        ->and($asha->currentPosition->department_id)->toBe($this->sales->id)
        ->and($asha->currentManager->manager_id)->toBe($this->boss->id)
        ->and(Employee::query()->where('person_id', $asha->person_id)->count())->toBe(1);
});

it('records a department change as effective-dated history and emits the reserved events', function () {
    $asha = hireAsha();
    Event::fake([EmploymentEvent::class]);

    $position = app(TransferEmployeeAction::class)->handle($asha, ['department_id' => $this->bizdev->id, 'location_id' => $this->mumbai->id], '2026-07-01', null, 'Moved to BD');

    $history = $asha->positions()->reorder()->orderBy('effective_from')->get();
    expect($history)->toHaveCount(2)
        ->and($history[0]->department_id)->toBe($this->sales->id)
        ->and($history[0]->effective_to->toDateString())->toBe('2026-06-30')
        ->and($history[1]->department_id)->toBe($this->bizdev->id)
        ->and($history[1]->designation_id)->toBe($this->exec->id) // carried forward
        ->and($history[1]->change_type)->toBe('transfer')
        ->and($asha->positions()->effectiveOn('2026-03-01')->first()->department_id)->toBe($this->sales->id)
        ->and($asha->refresh()->currentPosition->id)->toBe($position->id);

    Event::assertDispatched(EmploymentEvent::class, fn ($e) => $e->name === 'employee.transferred');
    Event::assertDispatched(EmploymentEvent::class, fn ($e) => $e->name === 'employee.department_changed' && $e->context['before'] === $this->sales->id);
    Event::assertDispatched(EmploymentEvent::class, fn ($e) => $e->name === 'employee.location_changed');
    Event::assertNotDispatched(EmploymentEvent::class, fn ($e) => $e->name === 'employee.designation_changed');
    expect(AuditEvent::query()->where('action', 'TRANSFERRED')->where('entity_id', (string) $asha->id)->exists())->toBeTrue();
});

it('promotes with a new designation and manager in one transaction and emits employee.promoted and employee.manager_changed', function () {
    $asha = hireAsha();
    $newBoss = app(HireEmployeeAction::class)->handle(['first_name' => 'Boss', 'last_name' => 'Two'], ['joining_date' => '2024-01-01'], ['company_id' => $this->company->id]);
    Event::fake([EmploymentEvent::class]);

    app(PromoteEmployeeAction::class)->handle($asha, ['designation_id' => $this->manager->id], '2026-10-01', $newBoss->id, 'Promotion cycle');

    expect($asha->refresh()->positions()->effectiveOn('2026-10-01')->first()->designation_id)->toBe($this->manager->id)
        ->and($asha->reportingRelationships()->where('type', 'line')->effectiveOn('2026-10-01')->first()->manager_id)->toBe($newBoss->id)
        ->and($asha->reportingRelationships()->where('type', 'line')->effectiveOn('2026-09-01')->first()->manager_id)->toBe($this->boss->id)
        ->and($asha->timelineEntries()->count())->toBeGreaterThanOrEqual(4);
    Event::assertDispatched(EmploymentEvent::class, fn ($e) => $e->name === 'employee.promoted');
    Event::assertDispatched(EmploymentEvent::class, fn ($e) => $e->name === 'employee.designation_changed');
    Event::assertDispatched(EmploymentEvent::class, fn ($e) => $e->name === 'employee.manager_changed' && $e->context['previous_manager_id'] === $this->boss->id);
    expect(AuditEvent::query()->where('action', 'PROMOTED')->where('entity_id', (string) $asha->id)->exists())->toBeTrue();
});

it('rejects overlapping or backdated position assignments', function () {
    $asha = hireAsha();
    app(TransferEmployeeAction::class)->handle($asha, ['department_id' => $this->bizdev->id], '2026-12-01');

    expect(fn () => app(TransferEmployeeAction::class)->handle($asha, ['location_id' => $this->mumbai->id], '2026-10-01'))->toThrow(OverlappingAssignmentException::class, 'already starts on 2026-12-01');
    expect(fn () => app(TransferEmployeeAction::class)->handle($asha, ['location_id' => $this->mumbai->id], '2026-01-01'))->toThrow(OverlappingAssignmentException::class);
    expect($asha->positions()->count())->toBe(2);
});

it('re-employs the same employee from alumni without creating a second master', function () {
    $asha = hireAsha();
    forceLifecycle($asha, LifecycleState::Alumni, ['exit_date' => '2026-06-30']);
    Event::fake([EmploymentEvent::class]);

    $rehired = app(RehireEmployeeAction::class)->handle($asha, ['department_id' => $this->bizdev->id], '2026-09-01', $this->boss->id, 'Came back');

    expect($rehired->id)->toBe($asha->id)
        ->and($rehired->lifecycle_state)->toBe(LifecycleState::Active)
        ->and($rehired->employee_code)->toBe($asha->employee_code)
        ->and($rehired->joining_date->toDateString())->toBe('2026-09-01')
        ->and($rehired->exit_date)->toBeNull()
        ->and($rehired->positions()->count())->toBe(2)
        ->and(Employee::query()->where('person_id', $asha->person_id)->count())->toBe(1)
        ->and($rehired->lifecycleTransitions()->count())->toBeGreaterThanOrEqual(3);
    Event::assertDispatched(EmploymentEvent::class, fn ($e) => $e->name === 'employee.rehired');
    expect(fn () => app(RehireEmployeeAction::class)->handle($rehired, [], '2026-10-01'))->toThrow(InvalidArgumentException::class, 'Only an alumni');
});

it('blocks direct lifecycle_state mutation and lets only the engine change it', function () {
    $asha = hireAsha();
    expect(fn () => $asha->update(['lifecycle_state' => LifecycleState::Active]))->toThrow(InvalidLifecycleTransitionException::class, 'LifecycleEngine::transition');
    expect($asha->refresh()->lifecycle_state)->toBe(LifecycleState::Probation);

    app(LifecycleEngine::class)->transition($asha, LifecycleState::Confirmed, '2026-07-01', 'Confirmed after review');
    expect($asha->refresh()->lifecycle_state)->toBe(LifecycleState::Confirmed)->and($asha->confirmation_date->toDateString())->toBe('2026-07-01');
    expect(fn () => app(LifecycleEngine::class)->transition($asha, LifecycleState::Alumni, now(), 'nope'))->toThrow(InvalidLifecycleTransitionException::class);
});

it('generates unique, predictable, tenant-safe employee codes and skips manually taken numbers', function () {
    $codes = collect(range(1, 5))->map(fn () => app(EmployeeCodeGenerator::class)->next())->all();
    expect($codes)->toBe(['EMP00002', 'EMP00003', 'EMP00004', 'EMP00005', 'EMP00006']); // EMP00001 is Boss

    app(HireEmployeeAction::class)->handle(['first_name' => 'Manual', 'last_name' => 'Code'], ['joining_date' => '2026-01-01', 'employee_code' => 'EMP00007'], ['company_id' => $this->company->id]);
    expect(app(EmployeeCodeGenerator::class)->next())->toBe('EMP00008');

    $other = provisionTenant('Other');
    actAsTenant($other);
    expect(app(EmployeeCodeGenerator::class)->next())->toBe('EMP00001');
});

it('refuses definite duplicate persons, flags possible ones for review, and allows an explicit override or re-use', function () {
    $asha = hireAsha();

    expect(fn () => app(HireEmployeeAction::class)->handle(['first_name' => 'A', 'last_name' => 'R', 'personal_email' => 'ASHA@example.test'], ['joining_date' => '2026-01-01'], ['company_id' => $this->company->id]))
        ->toThrow(DuplicatePersonException::class, 'personal email');
    expect(fn () => app(HireEmployeeAction::class)->handle(['first_name' => 'A', 'last_name' => 'R'], ['joining_date' => '2026-01-01', 'work_email' => 'asha.rao@alpha.test'], ['company_id' => $this->company->id]))
        ->toThrow(DuplicatePersonException::class, 'work email');

    $possible = app(PersonMatcher::class)->candidates(['first_name' => 'asha', 'last_name' => 'RAO', 'date_of_birth' => '1990-05-05']);
    expect($possible)->toHaveCount(1)->and($possible[0]['definite'])->toBeFalse()->and($possible[0]['employee_code'])->toBe($asha->employee_code);
    expect(app(PersonMatcher::class)->definite(['first_name' => 'asha', 'last_name' => 'RAO', 'date_of_birth' => '1990-05-05']))->toBeEmpty();

    // Same person as a new employee is refused (one employee per person); a free person can be attached.
    expect(fn () => app(HireEmployeeAction::class)->handle(['id' => $asha->person_id], ['joining_date' => '2026-01-01'], ['company_id' => $this->company->id]))->toThrow(DuplicatePersonException::class);
    $free = Person::create(['first_name' => 'Free', 'last_name' => 'Person']);
    $hired = app(HireEmployeeAction::class)->handle(['id' => $free->id], ['joining_date' => '2026-01-01'], ['company_id' => $this->company->id]);
    expect($hired->person_id)->toBe($free->id);

    $override = app(HireEmployeeAction::class)->handle(['first_name' => 'Twin', 'last_name' => 'Rao', 'personal_email' => 'asha@example.test', 'allow_duplicate' => true], ['joining_date' => '2026-01-01'], ['company_id' => $this->company->id]);
    expect($override->person_id)->not->toBe($asha->person_id);
});

it('updates person facts through the authoritative action with an audited reason and never touches employee facts', function () {
    $asha = hireAsha();
    app(UpdatePersonAction::class)->handle($asha->person, ['preferred_name' => 'Ash', 'employee_code' => 'HACK', 'nationality' => 'IN'], 'Name preference');

    expect($asha->person->refresh()->preferred_name)->toBe('Ash')->and($asha->refresh()->employee_code)->not->toBe('HACK');
    expect(AuditEvent::query()->where('entity_type', Person::class)->where('entity_id', (string) $asha->person_id)->where('reason', 'Name preference')->exists())->toBeTrue();
});

it('keeps functional and dotted-line relationships alongside the primary line', function () {
    $asha = hireAsha();
    $functional = app(HireEmployeeAction::class)->handle(['first_name' => 'Func', 'last_name' => 'Head'], ['joining_date' => '2024-01-01'], ['company_id' => $this->company->id]);
    app(ChangeManagerAction::class)->handle($asha, $functional, 'functional', '2026-02-01', 'Matrix');
    app(ChangeManagerAction::class)->handle($asha, $functional, 'dotted', '2026-02-01');

    $current = $asha->reportingRelationships()->effectiveOn('2026-03-01')->get();
    expect($current)->toHaveCount(3)
        ->and($current->where('is_primary', true)->count())->toBe(1)
        ->and($current->firstWhere('type', 'line')->manager_id)->toBe($this->boss->id)
        ->and($current->firstWhere('type', 'functional')->manager_id)->toBe($functional->id);
});
