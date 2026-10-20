<?php

use App\Domain\Career\Models\CareerProfile;
use App\Domain\Career\Services\CareerProfiles;
use App\Domain\Employment\Models\Employee;
use App\Domain\Organisation\Models\Designation;
use App\Domain\Succession\Models\CriticalPosition;
use App\Domain\Succession\Models\ReadinessAssessment;
use App\Domain\Succession\Models\SuccessionPlan;
use App\Domain\Succession\Models\Successor;
use App\Domain\Succession\Services\CriticalPositions;
use App\Domain\Succession\Services\Readiness;
use App\Domain\Succession\Services\SuccessionPlans;
use App\Domain\Talent\Models\TalentPool;
use App\Domain\Talent\Models\TalentPoolMembership;
use App\Domain\Talent\Models\TalentReviewSession;
use App\Domain\Talent\Services\TalentPools;
use App\Domain\Talent\Services\TalentReviews;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/../Feature/Workflow/WorkflowTestHelpers.php';
require_once __DIR__.'/../Feature/Performance/PerformanceTestHelpers.php';
require_once __DIR__.'/ConcurrencyHelpers.php';

/*
 | Phase 9 §41: real concurrency on MySQL for career, talent and succession. Same harness and
 | opt-in as the Phase 8 suite: PEOPLEOS_MYSQL_CONCURRENCY_DB names a disposable database whose name
 | contains "concurrency"; SQLite runs skip these and claim nothing about MySQL locking.
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
    $this->actingAs($this->admin);
    $this->assessment = ['criticality' => 'high', 'business_impact' => 'high', 'scarcity' => 'high', 'replacement_difficulty' => 'high', 'operational_dependency' => 'high', 'reason' => 'Race'];
});

/** Phase 9 rows whose write is slowed down so a missing lock or unique key would let both writers through. */
function talentSlowEvents(): array
{
    return [
        'eloquent.creating: '.Successor::class, 'eloquent.creating: '.TalentPoolMembership::class, 'eloquent.creating: '.ReadinessAssessment::class,
        'eloquent.creating: '.CriticalPosition::class, 'eloquent.updating: '.TalentReviewSession::class, 'eloquent.updating: '.CareerProfile::class,
    ];
}

it('adds a successor once when two people add the same employee to a plan at the same time', function () {
    $position = app(CriticalPositions::class)->designate(Designation::query()->create(['name' => 'Race head', 'code' => 'RACEH']), null, 'Race head', $this->assessment, 12, $this->admin);
    $plan = app(SuccessionPlans::class)->create($position, [], $this->admin);
    $employee = activeEmployee();

    $results = race([
        fn () => app(SuccessionPlans::class)->addSuccessor(SuccessionPlan::query()->findOrFail($plan->id), Employee::query()->findOrFail($employee->id), 'A', null, null, $this->admin),
        fn () => app(SuccessionPlans::class)->addSuccessor(SuccessionPlan::query()->findOrFail($plan->id), Employee::query()->findOrFail($employee->id), 'B', null, null, $this->admin),
    ], slow: talentSlowEvents());

    expect(collect($results)->filter(fn ($r) => $r === 'ok'))->toHaveCount(1)
        ->and(collect($results)->first(fn ($r) => $r !== 'ok'))->toContain('already a successor')
        ->and(Successor::query()->where('succession_plan_id', $plan->id)->where('employee_id', $employee->id)->count())->toBe(1);
});

it('designates a role critical once and adds a pool member once under concurrency', function () {
    $role = Designation::query()->create(['name' => 'Race role', 'code' => 'RACER']);
    $results = race([
        fn () => app(CriticalPositions::class)->designate(Designation::query()->findOrFail($role->id), null, 'One', $this->assessment, 12, $this->admin),
        fn () => app(CriticalPositions::class)->designate(Designation::query()->findOrFail($role->id), null, 'Two', $this->assessment, 12, $this->admin),
    ], slow: talentSlowEvents());
    expect(collect($results)->filter(fn ($r) => $r === 'ok'))->toHaveCount(1)
        ->and(CriticalPosition::query()->where('designation_id', $role->id)->where('status', 'active')->count())->toBe(1);

    $pool = TalentPool::create(['code' => 'RACEPOOL', 'name' => 'Race pool']);
    $employee = activeEmployee();
    $results = race([
        fn () => app(TalentPools::class)->add(TalentPool::query()->findOrFail($pool->id), Employee::query()->findOrFail($employee->id), 'A', $this->admin),
        fn () => app(TalentPools::class)->add(TalentPool::query()->findOrFail($pool->id), Employee::query()->findOrFail($employee->id), 'B', $this->admin),
    ], slow: talentSlowEvents());
    expect(collect($results)->filter(fn ($r) => $r === 'ok'))->toHaveCount(1)
        ->and(collect($results)->first(fn ($r) => $r !== 'ok'))->toContain('already an active member')
        ->and(TalentPoolMembership::query()->where('talent_pool_id', $pool->id)->where('status', 'active')->count())->toBe(1);
});

it('leaves exactly one current readiness label when two assessments for the same target race', function () {
    $position = app(CriticalPositions::class)->designate(Designation::query()->create(['name' => 'Race lead', 'code' => 'RACEL']), null, 'Race lead', $this->assessment, 12, $this->admin);
    $employee = activeEmployee();

    $results = race([
        fn () => app(Readiness::class)->assess(Employee::query()->findOrFail($employee->id), $position->id, null, 'ready_now', 'Panel A', null, $this->admin),
        fn () => app(Readiness::class)->assess(Employee::query()->findOrFail($employee->id), $position->id, null, 'lt_1_year', 'Panel B', null, $this->admin),
    ], slow: talentSlowEvents());

    expect($results)->toBe(['ok', 'ok'])
        ->and(ReadinessAssessment::query()->where('employee_id', $employee->id)->count())->toBe(2)
        ->and(ReadinessAssessment::query()->where('employee_id', $employee->id)->where('status', 'current')->count())->toBe(1);
});

it('completes a talent review once and rejects a stale career profile update', function () {
    $employee = activeEmployee();
    $reviews = app(TalentReviews::class);
    $session = $reviews->create('Race review', null, [], $this->admin);
    $reviews->addEmployees($session, [$employee->id], $this->admin);
    $reviews->start($session->refresh(), $this->admin);
    $reviews->recordDecision($session->items()->firstOrFail(), 'no_change', 'Steady', $this->admin);

    $results = race([
        fn () => $reviews->complete(TalentReviewSession::query()->findOrFail($session->id), 'First', $this->admin),
        fn () => $reviews->complete(TalentReviewSession::query()->findOrFail($session->id), 'Second', $this->admin),
    ], slow: talentSlowEvents());
    expect(collect($results)->filter(fn ($r) => $r === 'ok'))->toHaveCount(1)
        ->and(collect($results)->first(fn ($r) => $r !== 'ok'))->toContain('in progress')
        ->and($session->refresh()->status)->toBe('completed');

    $profiles = app(CareerProfiles::class);
    $version = $profiles->profileFor($employee)->lock_version;
    $results = race([
        fn () => $profiles->updateProfile(Employee::query()->findOrFail($employee->id), ['development_priorities' => 'Finance'], (int) $version, $this->admin),
        fn () => $profiles->updateProfile(Employee::query()->findOrFail($employee->id), ['development_priorities' => 'Operations'], (int) $version, $this->admin),
    ], slow: talentSlowEvents());
    expect(collect($results)->filter(fn ($r) => $r === 'ok'))->toHaveCount(1)
        ->and(collect($results)->first(fn ($r) => $r !== 'ok'))->toContain('changed meanwhile');
});

it('keeps the audit chain intact after concurrent talent writes', function () {
    expect(Artisan::call('peopleos:audit:verify'))->toBe(0);
});
