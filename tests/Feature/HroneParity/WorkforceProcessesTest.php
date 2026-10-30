<?php

use App\Domain\Configuration\Models\Policy;
use App\Domain\Configuration\Models\PolicyAssignmentRule;
use App\Domain\Configuration\Services\Policies;
use App\Domain\Employment\Events\EmploymentEvent;
use App\Domain\Employment\Models\WorkforceReason;
use App\Domain\Employment\Services\TransferRequests;
use App\Domain\Exit\Models\ClearanceChecklist;
use App\Domain\Exit\Models\ExitClearance;
use App\Domain\Exit\Services\Exits;
use App\Domain\Experience\Services\ApprovalCenter;
use App\Domain\Lifecycle\Enums\LifecycleState;
use App\Domain\Organisation\Models\Department;
use App\Domain\Organisation\Models\Location;
use Illuminate\Support\Facades\Event;

require_once __DIR__.'/../Workflow/WorkflowTestHelpers.php';

/* HROne setup parity — Workforce: transfer requests and policy, reason masters, clearance checklists, retirement alerts. */

beforeEach(function () {
    $this->travelTo('2026-09-21 10:00:00');
    $this->tenant = provisionTenant();
    actAsTenant($this->tenant);
    $this->hr = tenantUser($this->tenant, ['*']);
    $this->approver = tenantUser($this->tenant, ['*']);
    $this->actingAs($this->hr);
});

function processPolicy(string $type, array $settings): void
{
    $policy = Policy::create(['type' => $type, 'name' => $type, 'code' => strtoupper($type).'_X']);
    app(Policies::class)->draft($policy, $settings);
    app(Policies::class)->publish($policy, '2020-01-01');
    PolicyAssignmentRule::create(['policy_type' => $type, 'policy_id' => $policy->id, 'name' => 'All', 'conditions' => []]);
}

it('keeps transfer requests to what the policy lets change, and applies them when someone else approves', function () {
    $employee = employeeWithUser();
    forceLifecycle($employee, LifecycleState::Active, ['joining_date' => '2024-01-01']);
    $sales = Department::factory()->create(['name' => 'Sales']);
    $pune = Location::factory()->create(['name' => 'Pune', 'company_id' => $employee->currentPosition->company_id]);
    $reason = WorkforceReason::create(['kind' => 'transfer', 'name' => 'Organisational requirement']);
    processPolicy('transfer', ['changeable_dimensions' => ['department_id'], 'notice_days' => 7, 'reason_required' => true]);
    $transfers = app(TransferRequests::class);

    expect(fn () => $transfers->request($employee, '2026-10-01', ['location_id' => $pune->id], null, $reason->id, null, $this->hr))->toThrow(RuntimeException::class, 'does not allow changing: Location')
        ->and(fn () => $transfers->request($employee, '2026-09-24', ['department_id' => $sales->id], null, $reason->id, null, $this->hr))->toThrow(RuntimeException::class, '7 day(s) notice')
        ->and(fn () => $transfers->request($employee, '2026-10-01', ['department_id' => $sales->id], null, null, null, $this->hr))->toThrow(RuntimeException::class, 'Choose a transfer reason');

    $request = $transfers->request($employee, '2026-10-01', ['department_id' => $sales->id], null, $reason->id, 'Sales expansion', $this->hr);
    expect($request->summary())->toBe('Department → Sales')
        ->and(fn () => $transfers->approve($request, null, $this->hr))->toThrow(RuntimeException::class, 'Someone other than the requester')
        ->and(app(ApprovalCenter::class)->pending($this->approver)->pluck('type'))->toContain('transfer');

    $transfers->approve($request, 'ok', $this->approver);
    expect($request->refresh()->status)->toBe('approved')
        ->and($employee->positions()->effectiveOn('2026-10-02')->first()->department_id)->toBe($sales->id);
});

it('builds clearance items from the checklist the exit policy maps', function () {
    $checklist = ClearanceChecklist::create(['name' => 'Engineering exit', 'items' => [['stage' => 'it', 'item' => 'Revoke GitHub access', 'mandatory' => true], ['stage' => 'it', 'item' => 'Wipe laptop', 'mandatory' => true]]]);
    processPolicy('exit', ['notice_period_days' => 30, 'clearance_checklist_id' => $checklist->id]);
    $employee = employeeWithUser();
    forceLifecycle($employee, LifecycleState::Active, ['joining_date' => '2024-01-01']);

    $case = app(Exits::class)->initiate($employee, 'resignation', 'Higher studies');
    $it = ExitClearance::query()->where('exit_case_id', $case->id)->where('stage', 'it')->firstOrFail();
    expect(collect($it->items)->pluck('item')->all())->toBe(['Revoke GitHub access', 'Wipe laptop'])
        ->and(ExitClearance::query()->where('exit_case_id', $case->id)->where('stage', 'hr')->first()?->items)->not->toBeEmpty(); // other stages keep their defaults
});

it('raises retirement due once from the separation policy retirement age', function () {
    processPolicy('exit', ['retirement_age' => 60, 'retire_on' => 'month_end']);
    $senior = employeeWithUser();
    forceLifecycle($senior, LifecycleState::Active, ['joining_date' => '2000-01-01']);
    $senior->person->update(['date_of_birth' => '1966-10-10']);
    $young = employeeWithUser();
    forceLifecycle($young, LifecycleState::Active, ['joining_date' => '2020-01-01']);
    $young->person->update(['date_of_birth' => '1990-10-10']);

    Event::fake([EmploymentEvent::class]);
    $this->artisan('peopleos:lifecycle:retirement-due')->assertSuccessful();
    $this->artisan('peopleos:lifecycle:retirement-due')->assertSuccessful();
    Event::assertDispatchedTimes(EmploymentEvent::class, 1);
    Event::assertDispatched(EmploymentEvent::class, fn (EmploymentEvent $e) => $e->name === 'employee.retirement_due' && $e->employee->is($senior) && $e->context['retires_on'] === '2026-10-31');
});
