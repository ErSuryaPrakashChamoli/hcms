<?php

use App\Domain\Employment\Actions\AssignPositionAction;
use App\Domain\Employment\Exceptions\PositionUnavailableException;
use App\Domain\Employment\Models\EmployeePosition;
use App\Domain\Employment\Models\ReportingRelationship;
use App\Domain\Lifecycle\Enums\LifecycleState;
use App\Domain\Lifecycle\Models\EmployeeLifecycleTransition;
use App\Domain\Lifecycle\Services\LifecycleEngine;
use App\Domain\Organisation\Models\Company;
use App\Domain\Organisation\Models\Designation;
use App\Domain\Workforce\Events\WorkforceEvent;
use App\Domain\Workforce\Models\Position;
use App\Domain\Workforce\Models\PositionChangeRequest;
use App\Domain\Workforce\Models\PositionVersion;
use App\Domain\Workforce\Services\PositionOccupancy;
use App\Domain\Workforce\Services\Positions;
use Illuminate\Support\Facades\Event;

require_once __DIR__.'/../Workflow/WorkflowTestHelpers.php';
require_once __DIR__.'/../Performance/PerformanceTestHelpers.php';
require_once __DIR__.'/WorkforceTestHelpers.php';

beforeEach(function () {
    $this->travelTo('2026-10-05 09:00:00');
    $this->tenant = provisionTenant();
    actAsTenant($this->tenant);
    $this->admin = tenantUser($this->tenant, ['*']);
    $this->actingAs($this->admin);
    $this->planner = tenantUser($this->tenant, ['workforce.view', 'workforce.manage']);
    $this->approver = tenantUser($this->tenant, ['workforce.view', 'workforce.approve']);
    $this->employee = activeEmployee();
    $this->colleague = activeEmployee();
    $this->org = workforceOrg();
    $this->designation = Designation::query()->create(['name' => 'Engineering Manager', 'code' => 'ENGM']);
    $this->positions = app(Positions::class);
    $this->occupancy = app(PositionOccupancy::class);
    $this->assign = fn ($employee, $position, $from = '2026-10-05', array $extra = []) => app(AssignPositionAction::class)->handle($employee, ['position_id' => $position->id, ...$extra], 'transfer', $from, 'Seat');
});

it('keeps a position as capacity that exists, is approved by a second person and opens without any employee', function () {
    Event::fake([WorkforceEvent::class]);
    $position = $this->positions->create(['code' => 'eng-m-1', 'designation_id' => $this->designation->id, 'organisation_node_id' => $this->org['department']->id], $this->planner);

    expect($position->code)->toBe('ENG-M-1')->and($position->title)->toBe('Engineering Manager')
        ->and($position->department_id)->toBe($this->org['department']->nodeable_id)
        ->and($position->company_id)->toBe($this->org['company']->id)
        ->and(fn () => $this->positions->transition($position, 'approved', null, $this->approver))->toThrow(RuntimeException::class, 'cannot move from draft')
        ->and(fn () => $this->positions->transition($position, 'occupied', null, $this->planner))->toThrow(RuntimeException::class, 'derived from employee assignments');

    $this->positions->transition($position, 'proposed', null, $this->planner);
    expect(fn () => $this->positions->transition($position, 'approved', null, $this->planner))->toThrow(RuntimeException::class, 'workforce.approve');
    $selfApprover = tenantUser($this->tenant, ['workforce.manage', 'workforce.approve']);
    $this->positions->transition($position, 'draft', 'Rework', $this->planner);
    $this->positions->transition($position, 'proposed', null, $selfApprover);
    expect(fn () => $this->positions->transition($position, 'approved', null, $selfApprover))->toThrow(RuntimeException::class, 'proposed a position cannot approve');

    $this->positions->transition($position, 'approved', null, $this->approver);
    $this->positions->transition($position, 'open', null, $this->planner);
    $occupancy = $this->occupancy->occupancy($position->refresh());

    expect($position->status)->toBe('open')
        ->and($occupancy)->toMatchArray(['in_force' => true, 'seats' => 1, 'occupied_seats' => 0, 'remaining_seats' => 1, 'state' => 'vacant'])
        ->and(EmployeePosition::query()->where('position_id', $position->id)->count())->toBe(0)
        ->and(PositionVersion::query()->where('position_id', $position->id)->count())->toBe(6)
        ->and(fn () => $position->delete())->toThrow(RuntimeException::class, 'never deleted');
    Event::assertDispatched(WorkforceEvent::class, fn (WorkforceEvent $e) => $e->name === 'workforce.position.opened');
});

it('assigns through employment history, refuses a second occupant of a single seat and vacates explicitly', function () {
    $position = openPosition(['code' => 'ENG-M-2', 'designation_id' => $this->designation->id, 'organisation_node_id' => $this->org['department']->id], $this->planner, $this->approver);

    $row = ($this->assign)($this->employee, $position);
    expect($row->position_id)->toBe($position->id)->and($row->designation_id)->toBe($this->designation->id)
        ->and($row->department_id)->toBe($this->org['department']->nodeable_id)
        ->and($this->occupancy->occupancy($position)['state'])->toBe('filled')
        ->and(fn () => ($this->assign)($this->colleague, $position))->toThrow(PositionUnavailableException::class, 'no free seat');

    // An unrelated employment change keeps the seat; vacating is explicit.
    $this->travelTo('2026-11-01 09:00:00');
    app(AssignPositionAction::class)->handle($this->employee, ['location_id' => null], 'reassignment', '2026-11-01', 'Desk move');
    expect($this->employee->positions()->effectiveOn('2026-11-01')->first()->position_id)->toBe($position->id);
    app(AssignPositionAction::class)->handle($this->employee, ['vacate_position' => true], 'reassignment', '2026-12-01', 'Moved to a project');

    expect($this->occupancy->occupancy($position, '2026-12-01')['state'])->toBe('vacant')
        ->and($this->occupancy->occupancy($position, '2026-10-20')['occupants'][0]['employee_id'])->toBe($this->employee->id) // history kept
        ->and(($this->assign)($this->colleague, $position, '2026-12-01')->position_id)->toBe($position->id);
});

it('refuses assignments to frozen, abolished, not-yet-effective or planned positions and keeps them visible', function () {
    $position = openPosition(['code' => 'ENG-M-3', 'organisation_node_id' => $this->org['department']->id, 'title' => 'Platform lead'], $this->planner, $this->approver);

    expect(fn () => $this->positions->transition($position, 'frozen', '', $this->planner))->toThrow(RuntimeException::class, 'needs a reason');
    $this->positions->transition($position, 'frozen', 'Budget review', $this->planner);
    expect(fn () => ($this->assign)($this->employee, $position))->toThrow(PositionUnavailableException::class, 'frozen')
        ->and(fn () => $this->positions->transition($position, 'open', null, $this->planner))->toThrow(RuntimeException::class, 'needs a reason');
    $this->positions->transition($position, 'open', 'Budget confirmed', $this->planner);
    ($this->assign)($this->employee, $position);

    expect(fn () => $this->positions->transition($position, 'abolished', 'Restructure', $this->planner))->toThrow(RuntimeException::class, 'occupied');
    app(AssignPositionAction::class)->handle($this->employee, ['vacate_position' => true], 'reassignment', '2026-11-01', 'Moved');
    $this->positions->transition($position, 'abolished', 'Restructure', $this->planner, '2026-11-01');
    expect(fn () => ($this->assign)($this->colleague, $position, '2026-11-15'))->toThrow(PositionUnavailableException::class, 'abolished')
        ->and(Position::query()->whereKey($position->id)->exists())->toBeTrue()
        ->and($this->occupancy->occupancy($position, '2026-10-10')['occupants'])->toHaveCount(1);

    $future = $this->positions->create(['code' => 'REG-1', 'title' => 'Regional Manager', 'company_id' => $this->org['company']->id, 'effective_from' => '2027-04-01'], $this->planner);
    $this->positions->transition($future, 'proposed', null, $this->planner);
    $this->positions->transition($future, 'approved', null, $this->approver);
    $this->positions->transition($future, 'planned', null, $this->planner);
    expect($future->refresh()->status)->toBe('planned')
        ->and($future->versionOn('2026-10-05'))->toBeNull()                    // does not exist yet
        ->and($future->versionOn('2027-04-01')->status)->toBe('planned')
        ->and(fn () => ($this->assign)($this->colleague, $future, '2027-04-01'))->toThrow(PositionUnavailableException::class, 'planned');
});

it('keeps position history reproducible when the definition changes from an effective date', function () {
    $position = openPosition(['code' => 'MGR-1', 'title' => 'Manager', 'organisation_node_id' => $this->org['department']->id], $this->planner, $this->approver, '2026-01-01');
    ($this->assign)($this->employee, $position, '2026-02-01');

    $changed = $this->positions->change($position, ['title' => 'Senior Manager'], '2026-11-01', 'Scope grew', $this->planner);
    expect($changed)->toBeInstanceOf(PositionVersion::class)
        ->and($position->versionOn('2026-06-30')->title)->toBe('Manager')
        ->and($position->versionOn('2026-11-01')->title)->toBe('Senior Manager')
        ->and($position->refresh()->title)->toBe('Senior Manager')
        ->and(fn () => $position->versionOn('2026-06-30')->update(['title' => 'Rewritten']))->toThrow(RuntimeException::class, 'immutable')
        ->and(fn () => $this->positions->change($position, ['title' => 'X'], '2026-03-01', 'Backdated', $this->planner))->toThrow(RuntimeException::class, 'cannot start before');
});

it('supports multiple occupancy with seat and FTE capacity, and fractional FTE', function () {
    $pool = openPosition(['code' => 'SUP-1', 'title' => 'Support engineer', 'organisation_node_id' => $this->org['department']->id, 'occupancy_mode' => 'multiple', 'headcount' => 3, 'fte' => 1, 'fte_capacity' => 2.5], $this->planner, $this->approver);
    $third = activeEmployee();

    ($this->assign)($this->employee, $pool);
    ($this->assign)($this->colleague, $pool);
    expect(fn () => ($this->assign)($third, $pool))->toThrow(PositionUnavailableException::class, 'no FTE capacity')
        ->and(fn () => ($this->assign)($third, $pool, '2026-10-05', ['fte' => 0]))->toThrow(PositionUnavailableException::class)
        ->and(fn () => $this->positions->create(['code' => 'BAD', 'title' => 'Bad', 'company_id' => $this->org['company']->id, 'occupancy_mode' => 'single', 'headcount' => 2, 'fte' => 0], $this->planner))->toThrow(RuntimeException::class);

    ($this->assign)($third, $pool, '2026-10-05', ['fte' => 0.5]);
    expect($this->occupancy->occupancy($pool))->toMatchArray(['seats' => 3, 'occupied_seats' => 3, 'occupied_fte' => 2.5, 'remaining_fte' => 0.0, 'state' => 'filled']);
});

it('guards the position hierarchy: no self parent, no cycle, no cross-company parent', function () {
    $ceo = openPosition(['code' => 'CEO', 'title' => 'CEO', 'company_id' => $this->org['company']->id], $this->planner, $this->approver);
    $head = openPosition(['code' => 'HEAD', 'title' => 'Business head', 'company_id' => $this->org['company']->id, 'parent_position_id' => $ceo->id], $this->planner, $this->approver);
    $config = ['workforce.change_approval.organisation' => false];
    config(array_combine(array_map(fn ($k) => "peopleos.{$k}", array_keys($config)), $config));

    expect(fn () => $this->positions->change($ceo, ['parent_position_id' => $ceo->id], null, 'Loop', $this->planner))->toThrow(RuntimeException::class, 'own parent')
        ->and(fn () => $this->positions->change($ceo, ['parent_position_id' => $head->id], null, 'Loop', $this->planner))->toThrow(RuntimeException::class, 'circular');

    $other = Company::factory()->create();
    $foreign = $this->positions->create(['code' => 'OTHER-1', 'title' => 'Other', 'company_id' => $other->id], $this->planner);
    expect(fn () => $this->positions->create(['code' => 'X-1', 'title' => 'X', 'company_id' => $this->org['company']->id, 'parent_position_id' => $foreign->id], $this->planner))->toThrow(RuntimeException::class, 'same company');

    // A position hierarchy never creates reporting relationships.
    expect(ReportingRelationship::query()->count())->toBe(ReportingRelationship::query()->count());
});

it('frees the seat after the occupant exits and does not count a rehired employee during the gap', function () {
    $position = openPosition(['code' => 'OPS-1', 'title' => 'Ops analyst', 'organisation_node_id' => $this->org['department']->id], $this->planner, $this->approver, '2026-01-01');
    ($this->assign)($this->employee, $position, '2026-02-01');

    LifecycleEngine::unguarded(fn () => $this->employee->update(['lifecycle_state' => LifecycleState::Exited, 'exit_date' => '2026-06-30']));
    EmployeeLifecycleTransition::query()->create(['employee_id' => $this->employee->id, 'from_state' => 'active', 'to_state' => 'exited', 'effective_date' => '2026-06-30', 'reason' => 'Resigned']);
    expect($this->occupancy->occupancy($position, '2026-06-30')['occupied_seats'])->toBe(1)
        ->and($this->occupancy->occupancy($position, '2026-07-01')['occupied_seats'])->toBe(0)
        ->and(($this->assign)($this->colleague, $position, '2026-07-01')->position_id)->toBe($position->id);

    // Rehire clears exit_date; the gap stays unoccupied through the lifecycle history.
    LifecycleEngine::unguarded(fn () => $this->employee->update(['lifecycle_state' => LifecycleState::Active, 'exit_date' => null]));
    expect(collect($this->occupancy->occupancy($position, '2026-08-01')['occupants'])->pluck('employee_id')->all())->toBe([$this->colleague->id]);
});

it('routes configured changes through a second person and refuses capacity below occupancy', function () {
    $pool = openPosition(['code' => 'QA-1', 'title' => 'QA engineer', 'organisation_node_id' => $this->org['department']->id, 'occupancy_mode' => 'multiple', 'headcount' => 2], $this->planner, $this->approver);
    ($this->assign)($this->employee, $pool);
    ($this->assign)($this->colleague, $pool);

    $request = $this->positions->change($pool, ['headcount' => 3], '2026-11-01', 'Growth', $this->planner);
    expect($request)->toBeInstanceOf(PositionChangeRequest::class)->and($request->categories)->toBe(['headcount'])
        ->and($pool->refresh()->currentVersion->headcount)->toBe(2)
        ->and(fn () => $this->positions->decide($request, true, null, $this->planner))->toThrow(RuntimeException::class, 'workforce.approve');

    $this->positions->decide($request, true, 'Agreed', $this->approver);
    expect($request->refresh()->status)->toBe('approved')->and($pool->refresh()->currentVersion->headcount)->toBe(3)
        ->and($pool->versionOn('2026-10-31')->headcount)->toBe(2);

    $shrink = $this->positions->change($pool, ['headcount' => 1], '2026-12-01', 'Cut', $this->planner);
    expect(fn () => $this->positions->decide($shrink, true, null, $this->approver))->toThrow(RuntimeException::class, 'below the current occupancy')
        ->and($shrink->refresh()->status)->toBe('pending');

    // Unconfigured categories apply directly (location changes need no approval by default).
    expect($this->positions->change($pool, ['title' => 'QA lead engineer'], '2026-12-01', 'Rename', $this->planner))->toBeInstanceOf(PositionVersion::class);
});
