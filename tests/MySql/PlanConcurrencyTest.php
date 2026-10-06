<?php

use App\Domain\Audit\Models\AuditEvent;
use App\Domain\Audit\Services\AuditIntegrityVerifier;
use App\Domain\Configuration\Enums\VersionStatus;
use App\Domain\Entitlements\Enums\Capability;
use App\Domain\Entitlements\Enums\DecisionOutcome as O;
use App\Domain\Entitlements\Models\PlanEntitlement;
use App\Domain\Entitlements\Models\PlanVersion;
use App\Domain\Entitlements\Models\TenantEntitlementProfile;
use App\Domain\Entitlements\Models\TenantPlanAssignment;
use App\Domain\Entitlements\Services\EntitlementConfiguration;
use App\Domain\Entitlements\Services\Entitlements;
use App\Domain\Entitlements\Services\EntitlementStateStore;
use App\Domain\Entitlements\Services\PlanCatalog;
use App\Domain\Platform\Models\Tenant;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/ConcurrencyHelpers.php';
require_once __DIR__.'/../Feature/Entitlements/PlanTestHelpers.php';

/*
 | SaaS.4: concurrent plan catalogue and assignment changes on MySQL never leave two plans for one day, two drafts
 | for one plan, a double publication, or an assignment to a version retired before it; retries are idempotent and
 | the shared cache never keeps serving the previous plan. Same opt-in as the other MySQL suites.
 */

beforeEach(function () {
    $database = (string) env('PEOPLEOS_MYSQL_CONCURRENCY_DB', '');
    if ($database === '' || ! str_contains($database, 'concurrency') || ! function_exists('pcntl_fork')) {
        $this->markTestSkipped('Set PEOPLEOS_MYSQL_CONCURRENCY_DB to a disposable MySQL database (name containing "concurrency") and enable pcntl.');
    }
    config(['database.connections.concurrency' => array_merge(config('database.connections.mysql'), ['database' => $database]), 'database.default' => 'concurrency',
        'queue.default' => 'sync', 'cache.default' => 'database', 'cache.stores.database.connection' => 'concurrency']);
    DB::purge('concurrency');
    if (! ($GLOBALS['peopleos_concurrency_migrated'] ?? false)) {
        Artisan::call('migrate:fresh', ['--database' => 'concurrency', '--force' => true]);
        $GLOBALS['peopleos_concurrency_migrated'] = true;
    }
    $this->tenant = provisionTenant('Plan race '.uniqid());
    $this->operator = platformAdmin();
    $this->today = now()->toDateString();
    $suffix = substr(uniqid(), -6);
    $this->starter = publishedPlan($this->operator, "starter-{$suffix}", ['leave' => true], $this->today);
    $this->growth = publishedPlan($this->operator, "growth-{$suffix}", ['leave' => true, 'payroll' => true], $this->today);
    actAsTenant($this->tenant);
});

afterEach(function () {
    if (isset($this->tenant)) {
        expect(app(AuditIntegrityVerifier::class)->verify($this->tenant->id)['valid'])->toBeTrue()
            ->and(app(AuditIntegrityVerifier::class)->verify(null)['valid'])->toBeTrue();
    }
});

/** An assignment call as a separate process makes it (fresh tenant, version and operator instances). */
function planAssignmentCall(object $test, PlanVersion $version, ?string $to = null, string $reason = 'Concurrent assignment'): Closure
{
    return function () use ($test, $version, $to, $reason) {
        app(EntitlementConfiguration::class)->assignPlan(Tenant::query()->findOrFail($test->tenant->id), PlanVersion::query()->findOrFail($version->id), $test->today, $to, $reason, $test->operator->fresh());
    };
}

/** A catalogue call as a separate process makes it. */
function planCatalogCall(object $test, Closure $call): Closure
{
    return function () use ($test, $call) {
        $call(app(PlanCatalog::class), $test->operator->fresh());
    };
}

it('1. leaves exactly one plan for a day when two operators assign different plans at once', function () {
    $results = race([planAssignmentCall($this, $this->starter, reason: 'Operator one: Starter'), planAssignmentCall($this, $this->growth, reason: 'Operator two: Growth')],
        slow: ['eloquent.creating: '.TenantPlanAssignment::class, 'eloquent.updating: '.TenantPlanAssignment::class]);

    expect($results)->toBe(['ok', 'ok']);
    expect(TenantPlanAssignment::query()->where('status', 'active')->count())->toBe(1)
        ->and(TenantPlanAssignment::query()->where('status', 'cancelled')->count())->toBe(1)
        ->and(TenantEntitlementProfile::query()->sole()->version)->toBe(2);
});

it('2. creates one assignment when the same assignment is submitted twice at once', function () {
    $same = planAssignmentCall($this, $this->growth, reason: 'Retry-safe assignment');
    expect(race([$same, $same], slow: ['eloquent.creating: '.TenantPlanAssignment::class]))->toBe(['ok', 'ok']);

    expect(TenantPlanAssignment::query()->count())->toBe(1)
        ->and(AuditEvent::query()->withoutTenancy()->where('tenant_id', $this->tenant->id)->where('action', 'PLAN_ASSIGNED')->count())->toBe(1);
});

it('3. opens one draft when two operators start a new version of the same plan at once', function () {
    $plan = $this->growth->plan;
    $draft = planCatalogCall($this, fn (PlanCatalog $c, $op) => $c->draft($plan->fresh(), 'Next version', $op));
    expect(race([$draft, $draft], slow: ['eloquent.creating: '.PlanVersion::class]))->toBe(['ok', 'ok']);

    expect(PlanVersion::query()->where('plan_id', $plan->id)->where('status', 'draft')->count())->toBe(1)
        ->and(PlanVersion::query()->where('plan_id', $plan->id)->max('version'))->toBe(2)
        ->and(AuditEvent::query()->withoutTenancy()->where('action', 'PLAN_VERSION_DRAFTED')->where('metadata->plan', $plan->code)->count())->toBe(1);
});

it('4. publishes a draft once when two operators publish it at once', function () {
    $catalog = app(PlanCatalog::class);
    $draft = $catalog->draft($this->growth->plan, 'Next version', $this->operator);
    $catalog->set($draft, Capability::Ai, true, 'Add AI', $this->operator);
    $tomorrow = now()->addDay()->toDateString();
    $publish = planCatalogCall($this, fn (PlanCatalog $c, $op) => $c->publish(PlanVersion::query()->findOrFail($draft->id), $tomorrow, 'Publish v2', $op));
    expect(race([$publish, $publish], slow: ['eloquent.updating: '.PlanVersion::class]))->toBe(['ok', 'ok']);

    expect($draft->fresh()->status)->toBe(VersionStatus::Published)
        ->and($this->growth->fresh()->effective_to->toDateString())->toBe($this->today)
        ->and(AuditEvent::query()->withoutTenancy()->where('action', 'PLAN_VERSION_PUBLISHED')->where('metadata->plan_version_id', $draft->id)->count())->toBe(1);
});

it('5. never assigns a version retired before the assignment: either the assignment came first, or it is refused', function () {
    $results = race([
        planAssignmentCall($this, $this->starter, reason: 'Assign while retiring'),
        planCatalogCall($this, fn (PlanCatalog $c, $op) => $c->retire(PlanVersion::query()->findOrFail($this->starter->id), 'Withdraw Starter', $op)),
    ], slow: ['eloquent.creating: '.TenantPlanAssignment::class, 'eloquent.updating: '.PlanVersion::class]);

    expect($results[1])->toBe('ok')->and($this->starter->fresh()->status)->toBe(VersionStatus::Retired);
    if ($results[0] === 'ok') {
        $assignment = TenantPlanAssignment::query()->sole();
        expect($assignment->created_at->lessThanOrEqualTo($this->starter->fresh()->retired_at))->toBeTrue();
    } else {
        expect($results[0])->toContain('retired')->and(TenantPlanAssignment::query()->count())->toBe(0);
    }
});

it('6. invalidates the shared cache on assignment, so no process keeps serving the previous plan', function () {
    app(EntitlementConfiguration::class)->assignPlan($this->tenant, $this->starter, $this->today, null, 'Starter first', $this->operator);
    actAsTenant($this->tenant);
    expect(app(Entitlements::class)->evaluate(Capability::Payroll)->reason->value)->toBe('NOT_IN_PLAN'); // warms the shared cache

    expect(race([planAssignmentCall($this, $this->growth, reason: 'Upgrade to Growth')]))->toBe(['ok']);

    app()->forgetInstance(EntitlementStateStore::class); // the parent's next request
    actAsTenant($this->tenant);
    expect(app(Entitlements::class)->evaluate(Capability::Payroll)->outcome)->toBe(O::Allow);
});

it('7. keeps both operators\' edits when two of them change the same draft at once (no lost update)', function () {
    $catalog = app(PlanCatalog::class);
    $draft = $catalog->draft($this->growth->plan, 'Next version', $this->operator);
    $results = race([
        planCatalogCall($this, fn (PlanCatalog $c, $op) => $c->set(PlanVersion::query()->findOrFail($draft->id), Capability::Ai, true, 'Add AI', $op)),
        planCatalogCall($this, fn (PlanCatalog $c, $op) => $c->set(PlanVersion::query()->findOrFail($draft->id), Capability::Attendance, true, 'Add attendance', $op)),
    ], slow: ['eloquent.creating: '.PlanEntitlement::class]);

    expect($results)->toBe(['ok', 'ok'])
        ->and($catalog->values($draft))->toEqual(['leave' => true, 'payroll' => true, 'ai' => true, 'attendance' => true])
        ->and(AuditEvent::query()->withoutTenancy()->where('action', 'PLAN_VERSION_EDITED')->where('metadata->plan_version_id', $draft->id)->count())->toBe(2);
});

it('8. reads during an assignment see the old plan or the new one, never a mix, and never another tenant\'s', function () {
    $other = provisionTenant('Plan race other '.uniqid());
    app(EntitlementConfiguration::class)->assignPlan($this->tenant, $this->starter, $this->today, null, 'Starter first', $this->operator);
    $reader = fn (int $tenantId, array $allowed) => function () use ($tenantId, $allowed) {
        foreach (range(1, 40) as $i) {
            app()->forgetInstance(EntitlementStateStore::class); // a new request each time: cache, then database
            actAsTenant(Tenant::query()->findOrFail($tenantId));
            $payroll = app(Entitlements::class)->evaluate(Capability::Payroll);
            $leave = app(Entitlements::class)->evaluate(Capability::Leave);
            $seen = [$payroll->planVersionId, $payroll->reason->value, $leave->planVersionId, $leave->reason->value];
            if (! in_array($seen, $allowed, true)) {
                throw new RuntimeException('Inconsistent decision: '.json_encode($seen));
            }
            usleep(20_000);
        }
    };
    $starter = [$this->starter->id, 'NOT_IN_PLAN', $this->starter->id, 'ENTITLED'];
    $growth = [$this->growth->id, 'ENTITLED', $this->growth->id, 'ENTITLED'];
    $unconfigured = [null, 'TENANT_UNCONFIGURED', null, 'TENANT_UNCONFIGURED'];

    $results = race([
        planAssignmentCall($this, $this->growth, reason: 'Upgrade while being read'),
        $reader($this->tenant->id, [$starter, $growth]),
        $reader($other->id, [$unconfigured]),
    ], slow: ['eloquent.creating: '.TenantPlanAssignment::class]);

    expect($results)->toBe(['ok', 'ok', 'ok']);
    app()->forgetInstance(EntitlementStateStore::class);
    actAsTenant($this->tenant);
    expect(app(Entitlements::class)->evaluate(Capability::Payroll)->planVersionId)->toBe($this->growth->id);
});

it('9. serialises two operators setting the same limit on one draft at once: one row, both edits audited in order', function () {
    $catalog = app(PlanCatalog::class);
    $draft = $catalog->draft($this->growth->plan, 'Limit edits', $this->operator);
    $results = race([
        planCatalogCall($this, fn (PlanCatalog $c, $op) => $c->set(PlanVersion::query()->findOrFail($draft->id), Capability::ActiveEmployeesMax, 100, 'Operator one: 100', $op)),
        planCatalogCall($this, fn (PlanCatalog $c, $op) => $c->set(PlanVersion::query()->findOrFail($draft->id), Capability::ActiveEmployeesMax, 250, 'Operator two: 250', $op)),
    ], slow: ['eloquent.creating: '.PlanEntitlement::class, 'eloquent.updating: '.PlanEntitlement::class]);

    expect($results)->toBe(['ok', 'ok']);
    $rows = PlanEntitlement::query()->where('plan_version_id', $draft->id)->where('capability', 'active_employees.max')->get();
    $edits = AuditEvent::query()->withoutTenancy()->with('fieldChanges')->where('action', 'PLAN_VERSION_EDITED')->where('metadata->plan_version_id', $draft->id)->orderBy('id')->get()
        ->map(fn ($e) => $e->fieldChanges->firstWhere('field', 'active_employees.max'))->map(fn ($c) => [$c->before, $c->after])->all();
    expect($rows)->toHaveCount(1)
        ->and([100, 250])->toContain($rows->first()->value_int)
        ->and($edits)->toHaveCount(2)
        ->and($edits[1][0])->toBe($edits[0][1])                       // the second edit saw the first one's result
        ->and($edits[1][1])->toBe((string) $rows->first()->value_int); // and the last edit is what is stored
});

it('10. reads during a publication keep answering from the pinned version, never the new one, never a mix', function () {
    $catalog = app(PlanCatalog::class);
    app(EntitlementConfiguration::class)->assignPlan($this->tenant, $this->starter, $this->today, null, 'Starter v1 today', $this->operator);
    $next = $catalog->draft($this->starter->plan, 'Starter v2', $this->operator);
    $catalog->set($next, Capability::ActiveEmployeesMax, 100, 'One hundred', $this->operator);
    $tomorrow = now()->addDay()->toDateString();

    // Starter v1 has no employee limit; v2 (published during the reads) has 100. A pinned tenant never sees 100.
    $reader = function () {
        foreach (range(1, 40) as $i) {
            app()->forgetInstance(EntitlementStateStore::class);
            actAsTenant(Tenant::query()->findOrFail($this->tenant->id));
            $d = app(Entitlements::class)->evaluate(Capability::ActiveEmployeesMax, now()->addDays(5)->toDateString(), 75);
            if ($d->planVersionId !== $this->starter->id || $d->reason->value !== 'LIMIT_NOT_CONFIGURED' || $d->limit !== null) {
                throw new RuntimeException('Moved by a publication: '.json_encode($d->toArray()));
            }
            usleep(20_000);
        }
    };
    $results = race([
        planCatalogCall($this, fn (PlanCatalog $c, $op) => $c->publish(PlanVersion::query()->findOrFail($next->id), $tomorrow, 'Starter v2 on sale', $op)),
        $reader, $reader,
    ], slow: ['eloquent.updating: '.PlanVersion::class]);

    expect($results)->toBe(['ok', 'ok', 'ok'])
        ->and($next->fresh()->status)->toBe(VersionStatus::Published)
        ->and(TenantPlanAssignment::query()->sole()->plan_version_id)->toBe($this->starter->id);
});

it('11. an assignment during a publication pins the version on sale today, whichever commits first', function () {
    $catalog = app(PlanCatalog::class);
    $next = $catalog->draft($this->growth->plan, 'Growth v2', $this->operator);
    $catalog->set($next, Capability::ActiveEmployeesMax, 100, 'One hundred', $this->operator);
    $tomorrow = now()->addDay()->toDateString();

    $results = race([
        planAssignmentCall($this, $this->growth, reason: 'Assign while v2 is being published'),
        planCatalogCall($this, fn (PlanCatalog $c, $op) => $c->publish(PlanVersion::query()->findOrFail($next->id), $tomorrow, 'Growth v2 from tomorrow', $op)),
    ], slow: ['eloquent.creating: '.TenantPlanAssignment::class, 'eloquent.updating: '.PlanVersion::class]);

    expect($results)->toBe(['ok', 'ok'])
        ->and(TenantPlanAssignment::query()->sole()->plan_version_id)->toBe($this->growth->id)
        ->and($this->growth->fresh()->effective_to->toDateString())->toBe($this->today);
    app()->forgetInstance(EntitlementStateStore::class);
    actAsTenant($this->tenant);
    expect(app(Entitlements::class)->evaluate(Capability::Payroll, now()->addDays(3)->toDateString())->planVersionId)->toBe($this->growth->id);
});
