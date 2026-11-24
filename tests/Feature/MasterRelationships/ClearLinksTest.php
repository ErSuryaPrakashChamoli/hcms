<?php

use App\Domain\Audit\Models\AuditEvent;
use App\Domain\Employment\Actions\AssignPositionAction;
use App\Domain\Employment\Actions\PromoteEmployeeAction;
use App\Domain\Organisation\Models\Designation;
use App\Domain\Organisation\Models\Grade;
use App\Filament\Resources\Employees\Pages\ViewEmployee;
use App\Support\Masters\ActiveMasters;
use Livewire\Livewire;

require_once __DIR__.'/../Workflow/WorkflowTestHelpers.php';
require_once __DIR__.'/../Performance/PerformanceTestHelpers.php';

/*
| Master-relationships R1: a transfer or promotion can remove an optional field from its effective date ("Remove" on
| the form, `clear` on the action). A blank field still means "unchanged". The company is never removed. The earlier
| position keeps its values (history is never rewritten), and the audit and timeline show the removal.
*/

beforeEach(function () {
    $this->travelTo('2026-10-05 09:00:00');
    $this->tenant = provisionTenant();
    actAsTenant($this->tenant);
    $this->admin = tenantUser($this->tenant, ['*']);
    $this->actingAs($this->admin);
    $this->employee = activeEmployee();
    $this->grade = Grade::query()->create(['name' => 'T4', 'code' => 'T4']);
    $this->designation = Designation::query()->create(['name' => 'Sourcing Manager', 'code' => 'SRC-MGR']);
    app(AssignPositionAction::class)->handle($this->employee, ['grade_id' => $this->grade->id, 'designation_id' => $this->designation->id], 'transfer', '2026-10-01', 'Placed');
});

it('removes a field from the effective date and keeps the earlier position as it was', function () {
    $row = app(AssignPositionAction::class)->handle($this->employee, ['clear' => ['grade_id']], 'transfer', '2026-11-01', 'Grade no longer used');
    $earlier = $this->employee->positions()->effectiveOn('2026-10-15')->sole();

    expect($row->grade_id)->toBeNull()->and($row->designation_id)->toBe($this->designation->id)
        ->and($earlier->grade_id)->toBe($this->grade->id)->and($earlier->effective_to->toDateString())->toBe('2026-10-31')
        ->and(AuditEvent::query()->where('action', 'TRANSFERRED')->where('reason', 'Grade no longer used')->sole()->fieldChanges()->where('field', 'grade')->exists())->toBeTrue();
});

it('keeps a blank field unchanged, as before', function () {
    $row = app(AssignPositionAction::class)->handle($this->employee, ['grade_id' => '', 'designation_id' => null], 'transfer', '2026-11-01', 'Nothing removed');

    expect($row->grade_id)->toBe($this->grade->id)->and($row->designation_id)->toBe($this->designation->id);
});

it('never removes the company, and refuses a field that is both changed and removed', function () {
    $other = Grade::query()->create(['name' => 'T5', 'code' => 'T5']);

    expect(fn () => app(AssignPositionAction::class)->handle($this->employee, ['clear' => ['company_id']], 'transfer', '2026-11-01', 'x'))
        ->toThrow(InvalidArgumentException::class, 'The company cannot be removed')
        ->and(fn () => app(AssignPositionAction::class)->handle($this->employee, ['grade_id' => $other->id, 'clear' => ['grade_id']], 'transfer', '2026-11-01', 'x'))
        ->toThrow(InvalidArgumentException::class, 'Grade cannot be both changed and removed')
        ->and($this->employee->positions()->count())->toBe(2);
});

it('removes a field through a promotion too', function () {
    $row = app(PromoteEmployeeAction::class)->handle($this->employee, ['clear' => ['designation_id']], '2026-11-01', null, 'Role retired');

    expect($row->designation_id)->toBeNull()->and($row->change_type)->toBe('promotion');
});

it('offers Remove on the Transfer / promote form, previews it and saves it', function () {
    Livewire::test(ViewEmployee::class, ['record' => $this->employee->getRouteKey()])
        ->callAction('assignPosition', data: ['change_type' => 'transfer', 'effective_from' => '2026-11-01', 'clear' => ['grade_id'], 'audit_reason' => 'Regrade'])
        ->assertHasNoActionErrors()
        ->assertNotified('Position assigned');

    expect($this->employee->positions()->effectiveOn('2026-11-02')->value('grade_id'))->toBeNull();
});

it('leaves a deactivated master out of the pickers while records that use it keep it', function () {
    $this->designation->update(['status' => 'inactive']);
    $active = Designation::query()->create(['name' => 'Buyer', 'code' => 'BUY']);
    $options = ActiveMasters::only(Designation::class, Designation::query()->pluck('name', 'id')->all());

    expect($options)->toBe([$active->id => 'Buyer'])
        ->and($this->employee->positions()->effectiveOn('2026-10-15')->value('designation_id'))->toBe($this->designation->id);
});
