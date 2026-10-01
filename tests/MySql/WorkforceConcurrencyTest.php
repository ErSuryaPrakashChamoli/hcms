<?php

use App\Domain\Employment\Actions\AssignPositionAction;
use App\Domain\Employment\Models\Employee;
use App\Domain\Employment\Models\EmployeePosition;
use App\Domain\Workforce\Models\Position;
use App\Domain\Workforce\Models\PositionVersion;
use App\Domain\Workforce\Models\WorkforcePlanVersion;
use App\Domain\Workforce\Models\WorkforceScenario;
use App\Domain\Workforce\Services\PositionOccupancy;
use App\Domain\Workforce\Services\Positions;
use App\Domain\Workforce\Services\WorkforcePlans;
use App\Domain\Workforce\Services\WorkforceScenarios;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/../Feature/Workflow/WorkflowTestHelpers.php';
require_once __DIR__.'/../Feature/Performance/PerformanceTestHelpers.php';
require_once __DIR__.'/../Feature/Workforce/WorkforceTestHelpers.php';
require_once __DIR__.'/ConcurrencyHelpers.php';

/*
 | Phase 10 §43: real concurrency on MySQL for position capacity and workforce approvals. Same
 | harness and opt-in as the Phase 8 / 9 suites (PEOPLEOS_MYSQL_CONCURRENCY_DB, name containing
 | "concurrency"); SQLite runs skip these and claim nothing about MySQL locking.
 */

beforeEach(function () {
    $database = (string) env('PEOPLEOS_MYSQL_CONCURRENCY_DB', '');
    if ($database === '' || ! str_contains($database, 'concurrency') || ! function_exists('pcntl_fork')) {
        $this->markTestSkipped('Set PEOPLEOS_MYSQL_CONCURRENCY_DB to a disposable MySQL database (name containing "concurrency") and enable pcntl.');
    }
    config(['database.connections.concurrency' => array_merge(config('database.connections.mysql'), ['database' => $database]), 'database.default' => 'concurrency', 'queue.default' => 'sync']);
    DB::purge('concurrency');
    if (! ($GLOBALS['peopleos_concurrency_migrated'] ?? false)) {
        Artisan::call('migrate:fresh', ['--database' => 'concurrency', '--force' => true]);
        $GLOBALS['peopleos_concurrency_migrated'] = true;
    }
    $this->tenant = provisionTenant('Race '.uniqid());
    actAsTenant($this->tenant);
    $this->admin = tenantUser($this->tenant, ['*']);
    $this->approver = tenantUser($this->tenant, ['workforce.view', 'workforce.approve', 'workforce.manage', 'workforce.review']);
    $this->actingAs($this->admin);
    $this->org = workforceOrg();
    $this->assign = fn (int $employeeId, int $positionId, array $extra = []) => app(AssignPositionAction::class)->handle(Employee::query()->findOrFail($employeeId), ['position_id' => $positionId, ...$extra], 'transfer', now()->toDateString(), 'Race');
});

/** Phase 10 rows whose write is slowed down so a missing lock would let both writers through. */
function workforceSlowEvents(): array
{
    return ['eloquent.creating: '.EmployeePosition::class, 'eloquent.creating: '.PositionVersion::class, 'eloquent.updating: '.WorkforcePlanVersion::class, 'eloquent.updating: '.WorkforceScenario::class];
}

it('gives the last seat of a position to exactly one of two simultaneous assignments', function () {
    $position = openPosition(['code' => 'SEAT-'.uniqid(), 'title' => 'Last seat', 'organisation_node_id' => $this->org['department']->id], $this->admin, $this->approver);
    [$a, $b] = [activeEmployee(), activeEmployee()];

    $results = race([fn () => ($this->assign)($a->id, $position->id), fn () => ($this->assign)($b->id, $position->id)], slow: workforceSlowEvents());

    expect(collect($results)->filter(fn ($r) => $r === 'ok'))->toHaveCount(1)
        ->and(collect($results)->first(fn ($r) => $r !== 'ok'))->toContain('no free seat')
        ->and(app(PositionOccupancy::class)->occupancy($position->refresh())['occupied_seats'])->toBe(1);
});

it('never lets simultaneous part-time assignments exceed the FTE capacity', function () {
    $position = openPosition(['code' => 'FTE-'.uniqid(), 'title' => 'Pool', 'organisation_node_id' => $this->org['department']->id, 'occupancy_mode' => 'multiple', 'headcount' => 3, 'fte' => 1, 'fte_capacity' => 1.5], $this->admin, $this->approver);
    ($this->assign)(activeEmployee()->id, $position->id);
    [$a, $b] = [activeEmployee(), activeEmployee()];

    $results = race([fn () => ($this->assign)($a->id, $position->id, ['fte' => 0.5]), fn () => ($this->assign)($b->id, $position->id, ['fte' => 0.5])], slow: workforceSlowEvents());

    expect(collect($results)->filter(fn ($r) => $r === 'ok'))->toHaveCount(1)
        ->and(collect($results)->first(fn ($r) => $r !== 'ok'))->toContain('no FTE capacity')
        ->and(app(PositionOccupancy::class)->occupancy($position->refresh())['occupied_fte'])->toBe(1.5);
});

it('refuses an assignment that races a freeze or a closure that got the position first', function () {
    foreach (['frozen' => 'frozen', 'closed' => 'closed'] as $to => $expected) {
        $position = openPosition(['code' => strtoupper($to).'-'.uniqid(), 'title' => 'Racing '.$to, 'organisation_node_id' => $this->org['department']->id], $this->admin, $this->approver);
        $employee = activeEmployee();
        $results = race([
            fn () => app(Positions::class)->transition(Position::query()->findOrFail($position->id), $to, 'Race', $this->admin),
            function () use ($employee, $position) {
                usleep(150_000); // the lifecycle move takes the position row lock first
                ($this->assign)($employee->id, $position->id);
            },
        ], slow: workforceSlowEvents());

        expect($results[0])->toBe('ok')->and($results[1])->toContain($expected)
            ->and(EmployeePosition::query()->where('position_id', $position->id)->count())->toBe(0);
    }
});

it('approves a plan version once when two approvers act at the same time and keeps one active version', function () {
    $planner = tenantUser($this->tenant, ['workforce.view', 'workforce.plan']);
    $second = tenantUser($this->tenant, ['workforce.view', 'workforce.approve']);
    $plans = app(WorkforcePlans::class);
    $plan = $plans->create(['code' => 'RACE'.random_int(100, 999), 'name' => 'Race plan', 'company_id' => $this->org['company']->id, 'period_type' => 'annual', 'period_start' => '2027-01-01', 'period_end' => '2027-12-31'], $planner);
    $version = $plan->versions()->first();
    $plans->addLine($version, ['movement_type' => 'expansion', 'headcount' => 1, 'effective_date' => '2027-02-01'], $planner);
    $plans->submit($version, $planner);
    $plans->startReview($version->refresh(), $this->approver);

    $results = race([
        fn () => $plans->approve(WorkforcePlanVersion::query()->findOrFail($version->id), 'A', $this->approver),
        fn () => $plans->approve(WorkforcePlanVersion::query()->findOrFail($version->id), 'B', $second),
    ], slow: workforceSlowEvents());
    expect(collect($results)->filter(fn ($r) => $r === 'ok'))->toHaveCount(1)->and($version->refresh()->status)->toBe('approved');

    // Two approved versions published at the same time: exactly one stays active.
    $v2 = $plans->createVersion($plan->refresh(), [], $planner, $version);
    $plans->submit($v2, $planner);
    $plans->startReview($v2->refresh(), $this->approver);
    $plans->approve($v2->refresh(), null, $this->approver);
    $results = race([
        fn () => $plans->publish(WorkforcePlanVersion::query()->findOrFail($version->id), null, $this->approver),
        fn () => $plans->publish(WorkforcePlanVersion::query()->findOrFail($v2->id), null, $second),
    ], slow: workforceSlowEvents());
    expect(WorkforcePlanVersion::query()->where('workforce_plan_id', $plan->id)->where('status', 'active')->count())->toBe(1)
        ->and(collect($results)->filter(fn ($r) => $r === 'ok'))->not->toBeEmpty();
});

it('approves a scenario once under concurrency', function () {
    $planner = tenantUser($this->tenant, ['workforce.plan']);
    $second = tenantUser($this->tenant, ['workforce.approve']);
    $scenario = app(WorkforceScenarios::class)->create('R'.random_int(1000, 9999), 'Race scenario', null, ['attrition_rate_percent' => 5], $planner);

    $results = race([
        fn () => app(WorkforceScenarios::class)->approve(WorkforceScenario::query()->findOrFail($scenario->id), $this->approver),
        fn () => app(WorkforceScenarios::class)->approve(WorkforceScenario::query()->findOrFail($scenario->id), $second),
    ], slow: workforceSlowEvents());

    expect(collect($results)->filter(fn ($r) => $r === 'ok'))->toHaveCount(1)->and($scenario->refresh()->status)->toBe('approved');
});

it('keeps the audit chain intact after concurrent workforce writes', function () {
    expect(Artisan::call('peopleos:audit:verify'))->toBe(0);
});
