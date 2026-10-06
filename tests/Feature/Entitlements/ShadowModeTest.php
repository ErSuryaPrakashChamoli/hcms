<?php

use App\Domain\Employment\Models\Employee;
use App\Domain\Entitlements\Enums\Capability;
use App\Domain\Entitlements\Enums\DecisionOutcome as O;
use App\Domain\Entitlements\Enums\DecisionReason as R;
use App\Domain\Entitlements\Models\EntitlementShadowObservation;
use App\Domain\Entitlements\Services\EntitlementConfiguration;
use App\Domain\Entitlements\Services\Entitlements;
use App\Domain\Entitlements\Services\EntitlementStateStore;
use App\Domain\Entitlements\Services\ShadowRecorder;
use App\Domain\Integration\Services\ApiKeys;
use App\Domain\Leave\Models\LeaveRequest;
use App\Domain\Leave\Models\LeaveType;
use App\Domain\Leave\Services\LeaveAccrual;
use App\Domain\Leave\Services\Leaves;
use App\Domain\Lifecycle\Enums\LifecycleState;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

require_once __DIR__.'/../Leave/LeaveTestHelpers.php';
require_once __DIR__.'/../Attendance/AttendanceTestHelpers.php';
require_once __DIR__.'/../Workflow/WorkflowTestHelpers.php';
require_once __DIR__.'/../Performance/PerformanceTestHelpers.php';

/*
| SaaS.3 §16–§19, §27: shadow mode observes and never blocks: not a denial, not an unknown, not a failure.
*/

beforeEach(function () {
    $this->travelTo('2027-01-04 09:00:00'); // a Monday
    $this->tenant = provisionTenant('Alpha');
    $this->operator = platformAdmin();
    actAsTenant($this->tenant);
    $this->hr = tenantUser($this->tenant, ['*']);
    $this->actingAs($this->hr);
    leavePolicy(['CL' => ['days' => 12, 'accrual_frequency' => 'annual']]);
    $this->employee = activeEmployee(null, ['leave.apply']);
    app(LeaveAccrual::class)->accrue($this->employee);
    assignSchedule($this->employee, weeklySchedule(generalShift()));
});

function shadowRows(): Collection
{
    app(ShadowRecorder::class)->flush();

    return EntitlementShadowObservation::query()->withoutTenancy()->orderBy('id')->get();
}

function requestLeave(object $test): LeaveRequest
{
    return app(Leaves::class)->request($test->employee, LeaveType::query()->where('code', 'CL')->firstOrFail(), '2027-01-06', '2027-01-06', 'Family event');
}

it('lets a configured tenant without the module keep working, and records the would-be denial', function () {
    $config = app(EntitlementConfiguration::class);
    $config->configure($this->tenant, '2027-01-04', 'Contract', $this->operator);
    $config->set($this->tenant, Capability::Leave, false, '2027-01-04', null, 'Leave not in contract', $this->operator);
    actAsTenant($this->tenant);

    $request = requestLeave($this);

    expect($request->exists)->toBeTrue();
    $row = shadowRows()->firstWhere('surface', 'leave.request');
    expect($row->capability)->toBe('leave')->and($row->outcome)->toBe('DENY')->and($row->reason)->toBe('NOT_ENTITLED')
        ->and($row->tenant_id)->toBe($this->tenant->id)->and($row->last_source)->toBe('configuration')->and($row->occurrences)->toBe(1);
});

it('lets an unconfigured tenant keep working, and records UNKNOWN, never DENY', function () {
    expect(requestLeave($this)->exists)->toBeTrue();

    $rows = shadowRows();
    expect($rows->pluck('outcome')->unique()->values()->all())->toBe(['UNKNOWN'])
        ->and($rows->firstWhere('surface', 'leave.request')->reason)->toBe('TENANT_UNCONFIGURED');
});

it('keeps working when the entitlement engine itself fails, and makes the failure observable', function () {
    Log::spy();
    app()->bind(EntitlementStateStore::class, fn () => throw new RuntimeException('entitlement storage unavailable'));

    expect(requestLeave($this)->exists)->toBeTrue();
    $decision = app(Entitlements::class)->observe(Capability::Leave, 'test.failure');
    expect($decision->outcome)->toBe(O::Unknown)->and($decision->reason)->toBe(R::EvaluationFailed)->and($decision->enforced())->toBeFalse();
    expect(shadowRows()->firstWhere('surface', 'leave.request')->reason)->toBe('EVALUATION_FAILED');
    Log::shouldHaveReceived('warning')->withArgs(fn ($message) => $message === 'entitlements.evaluation_failed')->atLeast()->once();
});

it('survives a broken cache (falls back to the database) and malformed configuration (fails open, observable)', function () {
    $config = app(EntitlementConfiguration::class);
    $config->configure($this->tenant, '2027-01-04', 'Contract', $this->operator);
    $config->set($this->tenant, Capability::Leave, true, '2027-01-04', null, 'Leave included', $this->operator);
    actAsTenant($this->tenant);

    $broken = Mockery::mock(CacheRepository::class);
    $broken->shouldReceive('remember', 'forget', 'add')->andThrow(new RuntimeException('cache down'));
    app()->when(EntitlementStateStore::class)->needs(CacheRepository::class)->give(fn () => $broken);
    app()->forgetInstance(EntitlementStateStore::class);
    expect(app(Entitlements::class)->evaluate(Capability::Leave)->outcome)->toBe(O::Allow);

    app()->forgetInstance(EntitlementStateStore::class);
    DB::table('tenant_entitlements')->insert(['tenant_id' => $this->tenant->id, 'capability' => 'no.such.capability', 'value_bool' => true, 'effective_from' => '2027-01-04',
        'status' => 'active', 'reason' => 'corrupt row', 'created_at' => now(), 'updated_at' => now()]);
    expect(app(Entitlements::class)->evaluate(Capability::Leave)->reason)->toBe(R::EvaluationFailed)
        ->and(requestLeave($this)->exists)->toBeTrue();
});

it('answers UNKNOWN without a tenant, records nothing when switched off, and is never enforced', function () {
    actAsTenant(null);
    expect(app(Entitlements::class)->evaluate(Capability::Payroll)->reason)->toBe(R::NoTenantContext);

    actAsTenant($this->tenant);
    config(['peopleos.entitlements.mode' => 'off']);
    expect(app(Entitlements::class)->observe(Capability::Payroll, 'test.off')->reason)->toBe(R::ShadowDisabled)
        ->and(shadowRows()->where('surface', 'test.off'))->toHaveCount(0);

    config(['peopleos.entitlements.mode' => 'enforce']); // there is no enforcing mode: anything else is shadow
    expect(app(Entitlements::class)->mode())->toBe('shadow')
        ->and(app(Entitlements::class)->observe(Capability::Payroll, 'test.enforce')->enforced())->toBeFalse();
});

it('aggregates observations: one row per day, capability, decision, reason and surface, at most one write per window', function () {
    $entitlements = app(Entitlements::class);
    shadowRows(); // start from an empty buffer (the setup's hire was observed too)
    foreach (range(1, 50) as $i) {
        $entitlements->observe(Capability::Payroll, 'payroll.run.calculate');
    }
    expect(app(ShadowRecorder::class)->pending())->toBe(50);
    $calculate = fn () => shadowRows()->where('surface', 'payroll.run.calculate');
    expect($calculate())->toHaveCount(1)->and($calculate()->first()->occurrences)->toBe(50);

    // Inside the same window a second flush of the same observation does not write again.
    $entitlements->observe(Capability::Payroll, 'payroll.run.calculate');
    expect(app(ShadowRecorder::class)->flush())->toBe(0);

    // A new window writes again and adds to the same day's row.
    $this->travel(11)->minutes();
    $entitlements->observe(Capability::Payroll, 'payroll.run.calculate');
    expect($calculate()->first()->occurrences)->toBe(51);

    // Nothing about an employee or a value is stored: only the decision's keys.
    expect(array_keys($calculate()->first()->getAttributes()))->toBe(['id', 'tenant_id', 'observed_on', 'capability', 'outcome', 'reason', 'surface', 'occurrences',
        'first_seen_at', 'last_seen_at', 'last_source', 'last_entitlement_id', 'last_override_id', 'last_assignment_id']); // SaaS.4: the plan assignment behind the decision
});

it('observes the API and the module behind each scope, without changing the response', function () {
    $key = app(ApiKeys::class)->issue('Reader', ['employees.read', 'leave.read'])['plaintext'];
    actAsTenant(null);
    $this->withHeader('X-Api-Key', $key)->getJson('/api/v1/employees')->assertOk();

    $rows = shadowRows()->where('surface', 'api.request');
    expect($rows->pluck('capability')->all())->toBe(['integrations.api']); // employees.* is the core: not applicable, not recorded
});

it('observes the active-employee limit only when a finite limit applies, and never refuses a hire', function () {
    $config = app(EntitlementConfiguration::class);
    $config->configure($this->tenant, '2027-01-04', 'Contract', $this->operator);
    $config->set($this->tenant, Capability::ActiveEmployeesMax, 1, '2027-01-04', null, 'One employee', $this->operator);
    actAsTenant($this->tenant);

    $joiner = activeEmployee(null, ['task.view']); // a second employee becomes employed
    expect($joiner->lifecycle_state->isEmployed())->toBeTrue()
        ->and(Employee::query()->whereIn('lifecycle_state', array_map(fn ($s) => $s->value, array_filter(LifecycleState::cases(), fn ($s) => $s->isEmployed())))->count())->toBeGreaterThan(1);
    // The setup's hire (before the configuration) was observed as UNKNOWN; this one is over the limit.
    $rows = shadowRows()->where('surface', 'employee.activate');
    expect($rows->pluck('outcome')->sort()->values()->all())->toBe(['DENY', 'UNKNOWN']);
    $row = $rows->firstWhere('outcome', 'DENY');
    expect($row->capability)->toBe('active_employees.max')->and($row->reason)->toBe('LIMIT_EXCEEDED');
});
