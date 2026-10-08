<?php

use App\Domain\Audit\Models\AuditEvent;
use App\Domain\Audit\Services\AuditIntegrityVerifier;
use App\Domain\Enterprise\Services\Retention;
use App\Domain\Identity\Models\User;
use App\Domain\Offboarding\Enums\DeletionStatus;
use App\Domain\Offboarding\Models\TenantDeletion;
use App\Domain\Offboarding\Models\TenantDeletionStep;
use App\Domain\Offboarding\Models\TenantExport;
use App\Domain\Offboarding\Models\TenantOffboarding;
use App\Domain\Offboarding\Services\DeletionExecutor;
use App\Domain\Offboarding\Services\LegalHolds;
use App\Domain\Offboarding\Services\TenantDeletions;
use App\Domain\Offboarding\Services\TenantExports;
use App\Domain\Offboarding\Services\TenantOffboardings;
use App\Domain\Platform\Enums\TenantStatus;
use App\Domain\Platform\Models\Tenant;
use App\Domain\Subscriptions\Services\CommercialSubscriptions;
use App\Domain\Subscriptions\Services\SubscriptionDirectory;
use App\Support\Tenancy\TenantContext;
use Filament\Facades\Filament;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

require_once __DIR__.'/ConcurrencyHelpers.php';
require_once __DIR__.'/../Feature/Offboarding/OffboardingTestHelpers.php';
require_once __DIR__.'/../Feature/Entitlements/PlanTestHelpers.php';
require_once __DIR__.'/../Feature/Workflow/WorkflowTestHelpers.php';

/*
 | SaaS.9: real concurrency on MySQL for the exit lifecycle. Every destructive or eligibility-changing transition takes
 | the exit lock (LegalHolds::lockFor: the tenant row, then the open case) or a conditional update; these races prove
 | the outcomes: one approval, one worker, never an export and a deletion together, never a deletion after a hold, never
 | a reopened tenant with a deletion, never a closed tenant with a continuing subscription. Fixtures only: every tenant
 | here is provisioned by the test in a disposable database.
 */

beforeEach(function () {
    $database = (string) env('PEOPLEOS_MYSQL_CONCURRENCY_DB', '');
    if ($database === '' || ! str_contains($database, 'concurrency') || ! function_exists('pcntl_fork')) {
        $this->markTestSkipped('Set PEOPLEOS_MYSQL_CONCURRENCY_DB to a disposable MySQL database (name containing "concurrency") and enable pcntl.');
    }
    config(['database.connections.concurrency' => array_merge(config('database.connections.mysql'), ['database' => $database]), 'database.default' => 'concurrency',
        'queue.default' => 'sync', 'mail.default' => 'array', 'peopleos.offboarding.deletion_enabled' => true]);
    DB::purge('concurrency');
    if (! ($GLOBALS['peopleos_concurrency_migrated'] ?? false)) {
        Artisan::call('migrate:fresh', ['--database' => 'concurrency', '--force' => true]);
        $GLOBALS['peopleos_concurrency_migrated'] = true;
    }
    Storage::fake('local');
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    [$this->maker, $this->checker] = exitOperators();
    if (! ($GLOBALS['peopleos_exit_retention'] ?? false)) {
        retentionFor(null, 0, $this->maker, $this->checker); // fictional: every deletable category may go at once
        $GLOBALS['peopleos_exit_retention'] = true;
    }
    $this->tenant = exitRaceTenant();
    $this->platformEvents = fn (string $action, Tenant $tenant) => AuditEvent::query()->withoutTenancy()->whereNull('tenant_id')->where('action', $action)
        ->where('metadata->subject_tenant_id', $tenant->id)->count();
});

afterEach(function () {
    if (isset($this->tenant)) {
        expect(app(AuditIntegrityVerifier::class)->verify($this->tenant->id)['valid'])->toBeTrue()
            ->and(app(AuditIntegrityVerifier::class)->verify(null)['valid'])->toBeTrue();
    }
});

function exitRaceTenant(int $employees = 3): Tenant
{
    $tenant = provisionTenant('Exit race '.uniqid());
    app(TenantContext::class)->runAs($tenant, function () use ($employees) {
        foreach (range(1, $employees) as $i) {
            employeeWithUser();
        }
    });

    return $tenant;
}

function closedWithExportWaived(Tenant $tenant, User $operator): TenantOffboarding
{
    $case = offboardAndClose($tenant, $operator);

    return app(TenantOffboardings::class)->waiveExport($case, $operator, 'Customer took their copy (race fixture)');
}

/** Planned, approved and due now (the cooling-off moved into the past directly, as if it had run). */
function dueDeletion(TenantOffboarding $case, User $maker, User $checker): TenantDeletion
{
    $deletion = approvedDeletion($case, $maker, $checker);
    TenantDeletion::query()->withoutTenancy()->whereKey($deletion->id)->update(['scheduled_for' => now()->subMinute()]);

    return app(TenantDeletions::class)->find($deletion->id);
}

function employeesOf(Tenant $tenant): int
{
    return DB::table('employees')->where('tenant_id', $tenant->id)->count();
}

/** Waits (in a race child) until $condition holds, up to $seconds. */
function waitUntil(Closure $condition, float $seconds = 30): bool
{
    $until = microtime(true) + $seconds;
    while (microtime(true) < $until) {
        if ($condition()) {
            return true;
        }
        usleep(20_000);
    }

    return false;
}

/** Stretches a plan's evaluation (its many counts), so a racing writer lands between its checks and its write. */
function slowEvaluation(int $microseconds = 2_000): void
{
    Event::listen(QueryExecuted::class, fn (QueryExecuted $q) => str_contains($q->sql, 'count(*)') ? usleep($microseconds) : null);
}

/** Pauses right after a hold check, widening the window between reading the holds and deleting. */
function slowAfterHoldCheck(int $microseconds): void
{
    Event::listen(QueryExecuted::class, function (QueryExecuted $q) use ($microseconds) {
        if (str_starts_with(strtolower($q->sql), 'select') && str_contains($q->sql, '`legal_holds`')) {
            usleep($microseconds);
        }
    });
}

function slowDeletes(int $microseconds): void
{
    Event::listen(QueryExecuted::class, function (QueryExecuted $q) use ($microseconds) {
        if (str_starts_with(strtolower($q->sql), 'delete from')) {
            usleep($microseconds);
        }
    });
}

it('1. approves a deletion once when two checkers approve at once', function () {
    $case = closedWithExportWaived($this->tenant, $this->maker);
    $deletion = app(TenantDeletions::class)->request($case, $this->maker, 'Retention ended (race)');
    $second = User::factory()->platformAdmin()->create();

    $approve = fn (User $by) => fn () => app(TenantDeletions::class)->approve(app(TenantDeletions::class)->find($deletion->id), User::query()->findOrFail($by->id), 'Plan reviewed by '.$by->id);
    $results = race([$approve($this->checker), $approve($second)]);

    $steps = TenantDeletionStep::query()->withoutTenancy()->where('tenant_deletion_id', $deletion->id);
    expect(collect($results)->filter(fn ($r) => $r === 'ok'))->toHaveCount(1)
        ->and(collect($results)->reject(fn ($r) => $r === 'ok')->values()->all())->toBe(['This deletion is no longer waiting for approval.'])
        ->and(app(TenantDeletions::class)->find($deletion->id)->status)->toBe(DeletionStatus::Approved)
        ->and((clone $steps)->count())->toBeGreaterThan(0)
        ->and((clone $steps)->count())->toBe((clone $steps)->distinct()->count('position'))
        ->and(($this->platformEvents)('DELETION_APPROVED', $this->tenant))->toBe(1);
});

it('2. runs a deletion once when two workers pick it up at once', function () {
    $deletion = dueDeletion(closedWithExportWaived($this->tenant, $this->maker), $this->maker, $this->checker);

    $run = fn () => app(DeletionExecutor::class)->execute($deletion->id) ?: throw new RuntimeException('not claimed');
    $results = race([$run, $run]);
    sort($results);

    $deletion = app(TenantDeletions::class)->find($deletion->id);
    expect($results)->toBe(['not claimed', 'ok'])
        ->and($deletion->status)->toBe(DeletionStatus::Completed, (string) $deletion->failure)
        ->and($deletion->attempts)->toBe(1)
        ->and(($this->platformEvents)('DELETION_STARTED', $this->tenant))->toBe(1)
        ->and(($this->platformEvents)('DELETION_CERTIFIED', $this->tenant))->toBe(1)
        ->and(employeesOf($this->tenant))->toBe(0)
        ->and($this->tenant->fresh()->status)->toBe(TenantStatus::Deleted);
});

it('3. never queues an export and approves a deletion together: exactly one of the two goes through', function () {
    $case = closedWithExportWaived($this->tenant, $this->maker);
    $deletion = app(TenantDeletions::class)->request($case, $this->maker, 'Retention ended (race)');
    Queue::fake(); // the export stays queued (in flight): that is the state that must never meet an approved deletion

    $export = function () use ($case) {
        usleep(300_000); // lands while the approval is still evaluating the plan
        app(TenantExports::class)->request(Tenant::query()->findOrFail($this->tenant->id), User::query()->findOrFail($this->maker->id), 'One last copy (race)',
            TenantOffboarding::query()->withoutTenancy()->findOrFail($case->id));
    };
    $approve = function () use ($deletion) {
        slowEvaluation();
        app(TenantDeletions::class)->approve(app(TenantDeletions::class)->find($deletion->id), User::query()->findOrFail($this->checker->id), 'Plan reviewed (race)');
    };
    $results = race([$export, $approve]);

    $inFlight = TenantExport::query()->withoutTenancy()->where('tenant_id', $this->tenant->id)->whereIn('status', ['queued', 'generating'])->count();
    $approved = app(TenantDeletions::class)->find($deletion->id)->status === DeletionStatus::Approved;
    expect(collect($results)->filter(fn ($r) => $r === 'ok'))->toHaveCount(1)
        ->and($inFlight === 1 xor $approved)->toBeTrue()
        ->and(collect($results)->reject(fn ($r) => $r === 'ok')->first())->toMatch('/no new export|export (of this tenant )?is being generated/i');
});

it('4. stops a running deletion at the next batch when a hold arrives, and deletes nothing after it', function () {
    config(['peopleos.offboarding.batch_size' => 2]);
    $deletion = dueDeletion(closedWithExportWaived($this->tenant, $this->maker), $this->maker, $this->checker);
    $snapshot = tempnam(sys_get_temp_dir(), 'hold');

    $run = function () use ($deletion) {
        slowDeletes(5_000);
        slowAfterHoldCheck(20_000);
        app(DeletionExecutor::class)->execute($deletion->id);
    };
    $hold = function () use ($deletion, $snapshot) {
        // Mid-run: once the worker has finished a few steps.
        waitUntil(fn () => TenantDeletionStep::query()->withoutTenancy()->where('tenant_deletion_id', $deletion->id)->where('status', 'done')->count() >= 10)
            ?: throw new RuntimeException('the deletion never got going');
        app(LegalHolds::class)->place(Tenant::query()->findOrFail($this->tenant->id), [], 'Litigation notice (race)', 'MATTER-R4', null, User::query()->findOrFail($this->checker->id));
        $tenant = Tenant::query()->findOrFail($this->tenant->id);
        file_put_contents($snapshot, json_encode(['counts' => array_diff_key(tenantRowCounts($tenant), ['audit_events' => 0, 'audit_event_changes' => 0]),
            'audit' => DB::table('audit_events')->where('tenant_id', $tenant->id)->pluck('id')->all()]));
    };
    $results = race([$run, $hold]);

    $atHold = json_decode((string) file_get_contents($snapshot), true);
    unlink($snapshot);
    $deletion = app(TenantDeletions::class)->find($deletion->id);
    expect($results)->toBe(['ok', 'ok'])
        ->and($deletion->status)->toBe(DeletionStatus::Failed)
        ->and($deletion->failure)->toContain('legal hold')
        // Nothing the hold covers (everything) changed after it committed.
        ->and(array_diff_key(tenantRowCounts($this->tenant), ['audit_events' => 0, 'audit_event_changes' => 0]))->toBe($atHold['counts'])
        ->and(DB::table('audit_events')->whereIn('id', $atHold['audit'])->count())->toBe(count($atHold['audit']));
});

it('5. stops the retention purge, and refuses an approval, when a hold arrives while they evaluate', function () {
    // (a) The operational retention purge of an active tenant, in batches of 1000.
    $user = app(TenantContext::class)->runAs($this->tenant, fn () => tenantUser($this->tenant, ['ai.use']));
    foreach (array_chunk(range(1, 4000), 500) as $chunk) {
        DB::table('ai_interactions')->insert(array_map(fn () => ['tenant_id' => $this->tenant->id, 'user_id' => $user->id, 'assistant' => 'employee', 'question' => 'old',
            'answer' => 'x', 'created_at' => now()->subDays(400), 'updated_at' => now()->subDays(400)], $chunk));
    }
    $snapshot = tempnam(sys_get_temp_dir(), 'purge');
    $purge = function () {
        slowAfterHoldCheck(300_000);
        slowDeletes(400_000);
        $out = app(TenantContext::class)->runAs(Tenant::query()->findOrFail($this->tenant->id), fn () => app(Retention::class)->purge());
        $out['ai_interactions'] === 'held' ?: throw new RuntimeException('purged '.$out['ai_interactions']);
    };
    $hold = function () use ($snapshot) {
        waitUntil(fn () => DB::table('ai_interactions')->where('tenant_id', $this->tenant->id)->count() < 4000) ?: throw new RuntimeException('the purge never started');
        app(LegalHolds::class)->place(Tenant::query()->findOrFail($this->tenant->id), ['analytics'], 'Regulator inquiry (race)', null, null, User::query()->findOrFail($this->checker->id));
        file_put_contents($snapshot, (string) DB::table('ai_interactions')->where('tenant_id', $this->tenant->id)->count());
    };
    $results = race([$purge, $hold]);
    $atHold = (int) file_get_contents($snapshot);
    unlink($snapshot);
    expect($results)->toBe(['ok', 'ok'])
        ->and($atHold)->toBeGreaterThan(0)->toBeLessThan(4000)
        ->and(DB::table('ai_interactions')->where('tenant_id', $this->tenant->id)->count())->toBe($atHold);

    // (b) The deletion approval evaluating retention and holds while a hold on people is placed.
    $tenant = exitRaceTenant();
    $case = closedWithExportWaived($tenant, $this->maker);
    $deletion = app(TenantDeletions::class)->request($case, $this->maker, 'Retention ended (race)');
    $people = employeesOf($tenant);
    $approve = function () use ($deletion) {
        slowEvaluation();
        app(TenantDeletions::class)->approve(app(TenantDeletions::class)->find($deletion->id), User::query()->findOrFail($this->checker->id), 'Plan reviewed (race)');
    };
    $hold = function () use ($tenant) {
        usleep(300_000);
        app(LegalHolds::class)->place(Tenant::query()->findOrFail($tenant->id), ['people'], 'Claim by a former employee (race)', null, null, User::query()->findOrFail($this->maker->id));
    };
    [$approval, $placed] = race([$approve, $hold]);
    expect($placed)->toBe('ok');
    if ($approval === 'ok') {
        // Approved before the hold committed: the worker still keeps what the hold covers.
        TenantDeletion::query()->withoutTenancy()->whereKey($deletion->id)->update(['scheduled_for' => now()->subMinute()]);
        app(DeletionExecutor::class)->execute($deletion->id);
    } else {
        expect($approval)->toMatch('/legal hold was placed or released meanwhile|changed since this plan was made/');
    }
    expect(employeesOf($tenant))->toBe($people);
    $this->tenant = $tenant; // its chain is verified after the test as well
});

it('6. never leaves a reopened tenant with a deletion: reopen against planning, and against the worker', function () {
    // (a) Reopen and plan at once.
    $case = closedWithExportWaived($this->tenant, $this->maker);
    $reopen = fn () => app(TenantOffboardings::class)->reopen(TenantOffboarding::query()->withoutTenancy()->findOrFail($case->id), User::query()->findOrFail($this->maker->id), 'Customer renewed (race)');
    $plan = fn () => app(TenantDeletions::class)->request(TenantOffboarding::query()->withoutTenancy()->findOrFail($case->id), User::query()->findOrFail($this->checker->id), 'Retention ended (race)');
    $results = race([$reopen, $plan]);

    $planned = TenantDeletion::query()->withoutTenancy()->where('tenant_id', $this->tenant->id)->whereIn('status', ['planned', 'approved'])->count();
    $active = $this->tenant->fresh()->status === TenantStatus::Active;
    expect(collect($results)->filter(fn ($r) => $r === 'ok'))->toHaveCount(1)
        ->and($active xor $planned === 1)->toBeTrue();

    // (b) Cancel-and-reopen against the worker claiming the due deletion.
    $tenant = exitRaceTenant();
    $case = closedWithExportWaived($tenant, $this->maker);
    $deletion = dueDeletion($case, $this->maker, $this->checker);
    $people = employeesOf($tenant);
    $comeBack = function () use ($deletion, $case) {
        $operator = User::query()->findOrFail($this->maker->id);
        app(TenantDeletions::class)->cancel(app(TenantDeletions::class)->find($deletion->id), $operator, 'Customer renewed (race)');
        app(TenantOffboardings::class)->reopen(TenantOffboarding::query()->withoutTenancy()->findOrFail($case->id), $operator, 'Customer renewed (race)');
    };
    $run = fn () => app(DeletionExecutor::class)->execute($deletion->id) ?: throw new RuntimeException('not claimed');
    [$back, $ran] = race([$comeBack, $run]);

    $deletion = app(TenantDeletions::class)->find($deletion->id);
    if ($back === 'ok') {
        expect($ran)->toBe('not claimed')->and($tenant->fresh()->status)->toBe(TenantStatus::Active)
            ->and($deletion->status)->toBe(DeletionStatus::Cancelled)->and(employeesOf($tenant))->toBe($people);
    } else {
        expect($ran)->toBe('ok')->and($deletion->status)->toBe(DeletionStatus::Completed, (string) $deletion->failure)
            ->and($tenant->fresh()->status)->not->toBe(TenantStatus::Active)->and(employeesOf($tenant))->toBe(0);
    }
    $this->tenant = $tenant;
});

it('7. never closes a tenant with a continuing subscription: closing against a cancellation and against a new start', function () {
    $plan = publishedPlan($this->maker, 'exitrace'.uniqid(), ['leave' => true], today()->toDateString());
    $directory = app(SubscriptionDirectory::class);

    // (a) The subscription is cancelled while the tenant is closed.
    $subscription = app(CommercialSubscriptions::class)->start($this->tenant, $plan, today()->toDateString(), null, 'Contract', $this->maker);
    $case = app(TenantOffboardings::class)->request($this->tenant, 'Customer gave notice (race)', null, null, $this->maker);
    $cancel = fn () => app(CommercialSubscriptions::class)->cancel($subscription, today()->toDateString(), 'Contract ended (race)', User::query()->findOrFail($this->checker->id));
    $close = fn () => app(TenantOffboardings::class)->close(TenantOffboarding::query()->withoutTenancy()->findOrFail($case->id), User::query()->findOrFail($this->maker->id), 'Service ended (race)');
    [$cancelled, $closed] = race([$cancel, $close]);
    expect($cancelled)->toBe('ok')
        ->and($closed === 'ok' || str_contains($closed, 'end it on Subscriptions first'))->toBeTrue($closed);
    if ($this->tenant->fresh()->status === TenantStatus::Closed) {
        expect($directory->continuing($this->tenant->fresh()))->toBe([]);
    }

    // (b) A subscription is started while the tenant is closed.
    $tenant = exitRaceTenant(1);
    $case = app(TenantOffboardings::class)->request($tenant, 'Customer gave notice (race)', null, null, $this->maker);
    $start = fn () => app(CommercialSubscriptions::class)->start(Tenant::query()->findOrFail($tenant->id), $plan, today()->toDateString(), null, 'New contract (race)', User::query()->findOrFail($this->checker->id));
    $close = fn () => app(TenantOffboardings::class)->close(TenantOffboarding::query()->withoutTenancy()->findOrFail($case->id), User::query()->findOrFail($this->maker->id), 'Service ended (race)');
    $results = race([$start, $close]);
    expect(collect($results)->filter(fn ($r) => $r === 'ok'))->toHaveCount(1)
        ->and($tenant->fresh()->status === TenantStatus::Closed xor $directory->continuing($tenant->fresh()) !== [])->toBeTrue();
    $this->tenant = $tenant;
});

it('8. creates one export when two requests arrive at once: the second joins the first', function () {
    Queue::fake();
    $owner = app(TenantContext::class)->runAs($this->tenant, fn () => tenantUser($this->tenant, ['data.export']));
    $operatorRequest = fn () => app(TenantExports::class)->request(Tenant::query()->findOrFail($this->tenant->id), User::query()->findOrFail($this->maker->id), 'Operator copy (race)');
    $ownerRequest = fn () => app(TenantExports::class)->request(Tenant::query()->findOrFail($this->tenant->id), User::query()->findOrFail($owner->id), 'Our own copy (race)');
    $results = race([$operatorRequest, $ownerRequest], slow: ['eloquent.creating: '.TenantExport::class]);

    expect($results)->toBe(['ok', 'ok'])
        ->and(TenantExport::query()->withoutTenancy()->where('tenant_id', $this->tenant->id)->count())->toBe(1)
        ->and(AuditEvent::query()->withoutTenancy()->where('tenant_id', $this->tenant->id)->where('action', 'EXPORT_REQUESTED')->count())->toBe(1);
});

it('9. plans a deletion only once the export is delivered, while it completes', function () {
    Queue::fake();
    $case = offboardAndClose($this->tenant, $this->maker);
    $export = app(TenantExports::class)->request($this->tenant, $this->maker, 'Offboarding export (race)', $case);

    $complete = function () use ($export) {
        usleep(300_000);
        app(TenantExports::class)->generate($export->id) ?: throw new RuntimeException('not claimed');
        app(TenantExports::class)->markDelivered(app(TenantExports::class)->find($export->id), User::query()->findOrFail($this->maker->id), 'Handed over (race)');
    };
    $plan = function () use ($case) {
        $refusals = [];
        $planned = waitUntil(function () use ($case, &$refusals) {
            try {
                app(TenantDeletions::class)->request(TenantOffboarding::query()->withoutTenancy()->findOrFail($case->id), User::query()->findOrFail($this->checker->id), 'Retention ended (race)');

                return true;
            } catch (RuntimeException $e) {
                $refusals[] = $e->getMessage();

                return false;
            }
        });
        $planned ?: throw new RuntimeException('never planned: '.implode(' | ', array_unique($refusals)));
    };
    $results = race([$complete, $plan]);

    $export = app(TenantExports::class)->find($export->id);
    $deletion = TenantDeletion::query()->withoutTenancy()->where('tenant_id', $this->tenant->id)->sole();
    expect($results)->toBe(['ok', 'ok'])
        ->and($export->delivered_at)->not->toBeNull()
        ->and($deletion->requested_at->greaterThanOrEqualTo($export->delivered_at))->toBeTrue()
        ->and($deletion->plan['gates'])->toBe([]);
});

it('10. keeps external records consistent while the deletion runs: a confirmation is final and audited once', function () {
    config(['peopleos.offboarding.batch_size' => 2]);
    $deletion = dueDeletion(closedWithExportWaived($this->tenant, $this->maker), $this->maker, $this->checker);
    $backup = TenantDeletionStep::query()->withoutTenancy()->where('tenant_deletion_id', $deletion->id)->where('target', 'backups')->firstOrFail();

    $run = function () use ($deletion) {
        slowDeletes(2_000);
        app(DeletionExecutor::class)->execute($deletion->id);
    };
    $record = fn (User $by, string $status, float $after) => function () use ($by, $status, $after, $backup) {
        usleep((int) ($after * 1_000_000));
        app(TenantDeletions::class)->recordExternal(TenantDeletionStep::query()->withoutTenancy()->findOrFail($backup->id), $status, User::query()->findOrFail($by->id), "Recorded {$status} (race)");
    };
    $results = race([$run, $record($this->checker, TenantDeletionStep::EXTERNAL_CONFIRMED, 0.5), $record($this->maker, TenantDeletionStep::EXTERNAL_REQUESTED, 0.5)]);

    $deletion = app(TenantDeletions::class)->find($deletion->id);
    [$ran, $confirmed, $requested] = $results;
    $final = TenantDeletionStep::query()->withoutTenancy()->findOrFail($backup->id)->status;
    $recorded = collect([$confirmed, $requested])->filter(fn ($r) => $r === 'ok')->count();
    expect($ran)->toBe('ok')
        ->and($deletion->status)->toBe(DeletionStatus::Completed, (string) $deletion->failure)
        ->and($recorded)->toBeGreaterThanOrEqual(1)
        // A confirmation that went through is never overwritten; a refused one was told to reload.
        ->and($confirmed !== 'ok' || $final === TenantDeletionStep::EXTERNAL_CONFIRMED)->toBeTrue($final)
        ->and($final)->toBeIn([TenantDeletionStep::EXTERNAL_REQUESTED, TenantDeletionStep::EXTERNAL_CONFIRMED])
        ->and(($this->platformEvents)('EXTERNAL_DELETION_CONFIRMED', $this->tenant))->toBe($recorded)
        ->and(collect($deletion->certificate['external_dependencies'])->firstWhere('target', 'backups')['status'])
        ->toBeIn([TenantDeletionStep::EXTERNAL_REQUIRED, TenantDeletionStep::EXTERNAL_REQUESTED, TenantDeletionStep::EXTERNAL_CONFIRMED]);
    foreach ([$confirmed, $requested] as $result) {
        expect($result === 'ok' || str_contains($result, 'already confirmed'))->toBeTrue($result);
    }
});

it('11. resumes a partial deletion once when two operators retry and the scheduler fires at once', function () {
    config(['peopleos.offboarding.batch_size' => 2]);
    $deletion = dueDeletion(closedWithExportWaived($this->tenant, $this->maker), $this->maker, $this->checker);
    $crash = true;
    Event::listen(QueryExecuted::class, function (QueryExecuted $q) use (&$crash) {
        if ($crash && str_starts_with($q->sql, 'delete from `employees`')) {
            $crash = false;
            throw new RuntimeException('database went away (simulated)');
        }
    });
    app(DeletionExecutor::class)->execute($deletion->id);
    $deletion = app(TenantDeletions::class)->find($deletion->id);
    expect($deletion->status)->toBe(DeletionStatus::Failed)->and(employeesOf($this->tenant))->toBeGreaterThan(0)
        ->and(TenantDeletionStep::query()->withoutTenancy()->where('tenant_deletion_id', $deletion->id)->where('status', 'done')->count())->toBeGreaterThan(0);

    $retry = fn (User $by) => fn () => app(TenantDeletions::class)->retry(app(TenantDeletions::class)->find($deletion->id), User::query()->findOrFail($by->id), 'Database is back (race)');
    $tick = function () {
        usleep(50_000);
        app(TenantDeletions::class)->dispatchDue();
    };
    $results = race([$retry($this->maker), $retry($this->checker), $tick]);

    $deletion = app(TenantDeletions::class)->find($deletion->id);
    expect(collect(array_slice($results, 0, 2))->filter(fn ($r) => $r === 'ok'))->toHaveCount(1)
        ->and(collect(array_slice($results, 0, 2))->reject(fn ($r) => $r === 'ok')->values()->all())->toBe(['Only a failed deletion is retried.'])
        ->and($results[2])->toBe('ok')
        ->and($deletion->status)->toBe(DeletionStatus::Completed, (string) $deletion->failure)
        ->and($deletion->attempts)->toBe(2)
        ->and(($this->platformEvents)('DELETION_RETRIED', $this->tenant))->toBe(1)
        ->and(($this->platformEvents)('DELETION_STARTED', $this->tenant))->toBe(1)
        ->and(($this->platformEvents)('DELETION_CERTIFIED', $this->tenant))->toBe(1)
        ->and(employeesOf($this->tenant))->toBe(0);
});
