<?php

use App\Domain\Audit\Models\AuditEvent;
use App\Domain\Employment\Actions\AssignPositionAction;
use App\Domain\Employment\Actions\ChangeManagerAction;
use App\Domain\Employment\Actions\HireEmployeeAction;
use App\Domain\Organisation\Models\Company;
use App\Domain\Organisation\Models\Department;
use App\Domain\Organisation\Models\Designation;

beforeEach(function () {
    $this->tenant = provisionTenant();
    actAsTenant($this->tenant);
    $this->actingAs(tenantUser($this->tenant, ['employee.*']));
    $this->company = Company::factory()->create();
    $this->finance = Department::factory()->create(['name' => 'Finance']);
    $this->sales = Department::factory()->create(['name' => 'Sales']);
    $this->engineer = Designation::factory()->create(['name' => 'Engineer']);
    $this->lead = Designation::factory()->create(['name' => 'Tech Lead']);

    $this->hire = fn (string $first) => app(HireEmployeeAction::class)->handle(
        ['first_name' => $first, 'last_name' => 'Test'],
        ['joining_date' => '2025-01-01'],
        ['company_id' => $this->company->id, 'department_id' => $this->finance->id, 'designation_id' => $this->engineer->id],
    );
    $this->employee = ($this->hire)('Rahul');
});

it('opens a new position, closes the previous one and carries unchanged dimensions forward', function () {
    $position = app(AssignPositionAction::class)->handle(
        $this->employee,
        ['department_id' => $this->sales->id],
        'transfer',
        '2026-08-01',
        'Business need',
    );

    $previous = $this->employee->positions()->where('id', '!=', $position->id)->first();

    expect($previous->effective_to->toDateString())->toBe('2026-07-31')
        ->and($position->effective_from->toDateString())->toBe('2026-08-01')
        ->and($position->effective_to)->toBeNull()
        ->and($position->designation_id)->toBe($this->engineer->id)
        ->and($position->company_id)->toBe($this->company->id)
        ->and($this->employee->refresh()->currentPosition->id)->toBe($position->id)
        ->and($this->employee->positions()->effectiveOn('2026-06-15')->value('id'))->toBe($previous->id);
});

it('describes exactly what changed on the timeline and in the audit trail', function () {
    app(AssignPositionAction::class)->handle($this->employee, ['designation_id' => $this->lead->id, 'department_id' => $this->sales->id], 'promotion', '2026-09-15', 'Annual cycle');

    $entry = $this->employee->timelineEntries()->where('category', 'position')->orderByDesc('id')->first();

    expect($entry->title)->toBe('Promotion')
        ->and($entry->occurred_on->toDateString())->toBe('2026-09-15')
        ->and($entry->description)->toContain('Department: Finance → Sales')
        ->and($entry->description)->toContain('Designation: Engineer → Tech Lead')
        ->and($entry->description)->toContain('Reason: Annual cycle');

    $event = AuditEvent::query()->where('action', 'PROMOTED')->where('entity_id', (string) $this->employee->id)->first();
    expect($event->effective_date->toDateString())->toBe('2026-09-15')
        ->and($event->fieldChanges->firstWhere('field', 'designation')->after)->toBe('Tech Lead')
        ->and($event->fieldChanges->firstWhere('field', 'department')->before)->toBe('Finance')
        ->and($event->fieldChanges)->toHaveCount(2);
});

it('refuses a position that does not start after the current one', function () {
    expect(fn () => app(AssignPositionAction::class)->handle($this->employee, ['department_id' => $this->sales->id], 'transfer', '2025-01-01'))
        ->toThrow(InvalidArgumentException::class, 'must start after');
});

it('changes the line manager, closing the previous line and blocking loops', function () {
    $amit = ($this->hire)('Amit');
    $priya = ($this->hire)('Priya');

    app(ChangeManagerAction::class)->handle($this->employee, $amit, 'line', '2025-01-01');
    app(ChangeManagerAction::class)->handle($this->employee, $priya, 'line', '2026-01-01', 'Reorg');

    $lines = $this->employee->reportingRelationships()->where('type', 'line')->reorder('effective_from')->get();

    expect($lines)->toHaveCount(2)
        ->and($lines[0]->effective_to->toDateString())->toBe('2025-12-31')
        ->and($lines[1]->manager_id)->toBe($priya->id)
        ->and($this->employee->refresh()->currentManager->manager_id)->toBe($priya->id);

    // Priya -> Rahul would loop because Rahul -> Priya.
    expect(fn () => app(ChangeManagerAction::class)->handle($priya, $this->employee, 'line', '2026-02-01'))
        ->toThrow(InvalidArgumentException::class, 'loop');
    expect(fn () => app(ChangeManagerAction::class)->handle($priya, $priya))->toThrow(InvalidArgumentException::class, 'themselves');

    // Secondary lines do not affect the primary chain.
    $dotted = app(ChangeManagerAction::class)->handle($priya, $this->employee, 'dotted', '2026-02-01');
    expect($dotted->is_primary)->toBeFalse()
        ->and($this->employee->directReports()->count())->toBe(1);
});

it('is a no-op when the same manager is assigned again', function () {
    $amit = ($this->hire)('Amit');
    $first = app(ChangeManagerAction::class)->handle($this->employee, $amit);
    $second = app(ChangeManagerAction::class)->handle($this->employee, $amit);

    expect($second->id)->toBe($first->id)
        ->and($this->employee->reportingRelationships()->count())->toBe(1);
});
