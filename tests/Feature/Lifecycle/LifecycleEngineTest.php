<?php

use App\Domain\Audit\Models\AuditEvent;
use App\Domain\Employment\Models\Employee;
use App\Domain\Lifecycle\Enums\LifecycleState;
use App\Domain\Lifecycle\Events\EmployeeLifecycleChanged;
use App\Domain\Lifecycle\Exceptions\InvalidLifecycleTransitionException;
use App\Domain\Lifecycle\Services\LifecycleEngine;
use Illuminate\Support\Facades\Event;

beforeEach(function () {
    $this->tenant = provisionTenant();
    actAsTenant($this->tenant);
    $this->actingAs(tenantUser($this->tenant, ['employee.*']));
    $this->employee = Employee::factory()->create(['lifecycle_state' => LifecycleState::Probation, 'joining_date' => '2026-03-01']);
    $this->engine = app(LifecycleEngine::class);
});

it('moves through allowed states, stamping dates and logging every hop', function () {
    Event::fake([EmployeeLifecycleChanged::class]);

    $this->engine->transition($this->employee, LifecycleState::Confirmed, '2026-09-01', 'Probation cleared');
    $this->engine->transition($this->employee, LifecycleState::NoticePeriod, '2027-01-10', 'Resigned');
    $this->engine->transition($this->employee, LifecycleState::Exited, '2027-03-10');
    $this->engine->transition($this->employee, LifecycleState::Alumni, '2027-03-11');

    $this->employee->refresh();

    expect($this->employee->lifecycle_state)->toBe(LifecycleState::Alumni)
        ->and($this->employee->confirmation_date->toDateString())->toBe('2026-09-01')
        ->and($this->employee->exit_date->toDateString())->toBe('2027-03-10')
        ->and($this->employee->lifecycleTransitions()->count())->toBe(4)
        ->and($this->employee->lifecycleTransitions()->reorder('id')->pluck('to_state')->map->value->all())->toBe(['confirmed', 'notice_period', 'exited', 'alumni'])
        ->and($this->employee->timelineEntries()->reorder('id')->pluck('title')->all())->toBe(['Confirmed', 'Resignation / notice period started', 'Exited company', 'Became alumni'])
        ->and(AuditEvent::query()->where('entity_id', (string) $this->employee->id)->whereIn('action', ['CONFIRMED', 'EXIT_INITIATED', 'EXIT_COMPLETED', 'ALUMNI_CREATED'])->count())->toBe(4)
        ->and(AuditEvent::query()->where('action', 'CONFIRMED')->value('reason'))->toBe('Probation cleared');

    Event::assertDispatched(EmployeeLifecycleChanged::class, 4);
    Event::assertDispatched(EmployeeLifecycleChanged::class, fn ($e) => $e->to === LifecycleState::Exited && $e->name() === 'employee.exited');
});

it('rejects transitions that the configured graph does not allow', function () {
    expect(fn () => $this->engine->transition($this->employee, LifecycleState::Alumni))
        ->toThrow(InvalidLifecycleTransitionException::class, 'cannot move from Probation to Alumni');

    expect($this->employee->refresh()->lifecycle_state)->toBe(LifecycleState::Probation)
        ->and($this->employee->lifecycleTransitions()->count())->toBe(0);
});

it('reads the allowed graph from configuration', function () {
    config()->set('peopleos.lifecycle.transitions.probation', ['alumni']);

    expect(LifecycleState::Probation->allowedNext())->toBe([LifecycleState::Alumni])
        ->and(LifecycleState::Probation->canTransitionTo(LifecycleState::Confirmed))->toBeFalse();
});
