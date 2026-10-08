<?php

use App\Domain\Audit\Models\AuditEvent;
use App\Domain\Audit\Services\AuditIntegrityVerifier;
use App\Domain\Identity\Enums\UserStatus;
use App\Domain\Identity\Models\Role;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Models\UserInvitation;
use App\Domain\Notifications\Models\NotificationDelivery;
use App\Domain\Notifications\Services\NotificationRecovery;
use App\Domain\Organisation\Models\Company;
use App\Domain\Platform\Actions\ProvisionTenantAction;
use App\Domain\Platform\Enums\TenantStatus;
use App\Domain\Platform\Models\Tenant;
use App\Domain\Platform\Models\TenantDeferredJob;
use App\Domain\Platform\Services\TenantDeferredWork;
use App\Domain\Platform\Services\TenantFirstAdministrator;
use App\Domain\Platform\Services\TenantSuspensions;
use App\Support\Tenancy\Jobs\DeferredTenantJobs;
use App\Support\Tenancy\TenantContext;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\Support\TenantAwareTestJob;

require_once __DIR__.'/ConcurrencyHelpers.php';

/*
 | SaaS.8: real concurrency on MySQL for every new transition: deferred-work release, deferral during reactivation,
 | suspension and reactivation (status restore), notification resend and recovery sweeps, the first administrator's
 | invitation, and provisioning. Same harness and opt-in as the earlier suites.
 */

beforeEach(function () {
    $database = (string) env('PEOPLEOS_MYSQL_CONCURRENCY_DB', '');
    if ($database === '' || ! str_contains($database, 'concurrency') || ! function_exists('pcntl_fork')) {
        $this->markTestSkipped('Set PEOPLEOS_MYSQL_CONCURRENCY_DB to a disposable MySQL database (name containing "concurrency") and enable pcntl.');
    }
    config(['database.connections.concurrency' => array_merge(config('database.connections.mysql'), ['database' => $database]), 'database.default' => 'concurrency',
        'queue.default' => 'sync', 'mail.default' => 'array']);
    DB::purge('concurrency');
    if (! ($GLOBALS['peopleos_concurrency_migrated'] ?? false)) {
        Artisan::call('migrate:fresh', ['--database' => 'concurrency', '--force' => true]);
        $GLOBALS['peopleos_concurrency_migrated'] = true;
    }
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $this->tenant = provisionTenant('Ops race '.uniqid());
    $this->operator = User::factory()->platformAdmin()->create();
    $this->platformEvents = fn (string $action) => AuditEvent::query()->withoutTenancy()->whereNull('tenant_id')->where('action', $action)
        ->where('metadata->subject_tenant_id', $this->tenant->id)->count();
    $this->tenantEvents = fn (string $action) => AuditEvent::query()->withoutTenancy()->where('tenant_id', $this->tenant->id)->where('action', $action)->count();
    $this->companies = fn (string $prefix) => app(TenantContext::class)->runAs($this->tenant, fn () => Company::query()->where('name', 'like', $prefix.'%')->count());
});

afterEach(function () {
    if (isset($this->tenant)) {
        expect(app(AuditIntegrityVerifier::class)->verify($this->tenant->id)['valid'])->toBeTrue()
            ->and(app(AuditIntegrityVerifier::class)->verify(null)['valid'])->toBeTrue();
    }
});

function deferJobs(Tenant $tenant, string $prefix, int $count): void
{
    foreach (range(1, $count) as $i) {
        $job = new TenantAwareTestJob("{$prefix} {$i}");
        $job->tenantId = $tenant->id;
        app(DeferredTenantJobs::class)->defer($job, $tenant);
    }
}

it('1. dispatches each deferred job once when the reactivation release and an operator release run at once', function () {
    app(TenantSuspensions::class)->suspend($this->tenant, 'Race setup', $this->operator);
    deferJobs($this->tenant, 'Released', 5);
    Tenant::query()->whereKey($this->tenant->id)->update(['status' => TenantStatus::Active->value, 'suspended_from' => null]);

    $release = fn () => fn () => app(DeferredTenantJobs::class)->release(Tenant::query()->findOrFail($this->tenant->id));
    $operator = fn () => app(TenantDeferredWork::class)->release(Tenant::query()->findOrFail($this->tenant->id), User::query()->findOrFail($this->operator->id), 'Operator release');
    $results = race([$release(), $operator], slow: ['eloquent.creating: '.Company::class]);

    expect($results)->toBe(['ok', 'ok'])
        ->and(($this->companies)('Released'))->toBe(5)
        ->and(TenantDeferredJob::query()->withoutTenancy()->where('tenant_id', $this->tenant->id)->count())->toBe(0);
});

it('2. never strands a job deferred while the tenant is being reactivated: it runs exactly once', function () {
    app(TenantSuspensions::class)->suspend($this->tenant, 'Race setup', $this->operator);
    deferJobs($this->tenant, 'Early', 2);

    $reactivate = fn () => app(TenantSuspensions::class)->reactivate(Tenant::query()->findOrFail($this->tenant->id), 'Cleared', User::query()->findOrFail($this->operator->id)) ?: throw new RuntimeException('no change');
    $late = function () {
        $job = new TenantAwareTestJob('Late 1');
        $job->tenantId = $this->tenant->id;
        // The worker read "suspended" before the reactivation committed, and stores the job now.
        app(DeferredTenantJobs::class)->defer($job, Tenant::query()->findOrFail($this->tenant->id));
    };
    $results = race([$reactivate, $late], slow: ['eloquent.creating: '.TenantDeferredJob::class, 'eloquent.creating: '.Company::class]);

    expect($results)->toBe(['ok', 'ok'])
        ->and(($this->companies)('Early'))->toBe(2)
        ->and(($this->companies)('Late'))->toBe(1)
        ->and(TenantDeferredJob::query()->withoutTenancy()->where('tenant_id', $this->tenant->id)->count())->toBe(0)
        ->and($this->tenant->fresh()->status)->toBe(TenantStatus::Active);
});

it('3. reactivates once, restoring the interrupted trial, when two operators reactivate at once', function () {
    Tenant::query()->whereKey($this->tenant->id)->update(['status' => TenantStatus::Trial->value]);
    app(TenantSuspensions::class)->suspend(Tenant::query()->findOrFail($this->tenant->id), 'Race setup', $this->operator);
    $second = User::factory()->platformAdmin()->create();

    $reactivate = fn (User $by) => fn () => app(TenantSuspensions::class)->reactivate(Tenant::query()->findOrFail($this->tenant->id), 'Cleared by '.$by->id, $by) ?: throw new RuntimeException('no change');
    $results = race([$reactivate($this->operator), $reactivate($second)]);

    expect(collect($results)->filter(fn ($r) => $r === 'ok'))->toHaveCount(1)
        ->and(collect($results)->reject(fn ($r) => $r === 'ok')->values()->all())->toBe(['no change'])
        ->and($this->tenant->fresh()->status)->toBe(TenantStatus::Trial)
        ->and($this->tenant->fresh()->suspended_from)->toBeNull()
        ->and(($this->tenantEvents)('TENANT_REACTIVATED'))->toBe(1)
        ->and(($this->platformEvents)('TENANT_REACTIVATED'))->toBe(1);
});

it('4. records the interrupted status once when two operators suspend a trial tenant at once', function () {
    Tenant::query()->whereKey($this->tenant->id)->update(['status' => TenantStatus::Trial->value]);

    $suspend = fn (string $reason) => fn () => app(TenantSuspensions::class)->suspend(Tenant::query()->findOrFail($this->tenant->id), $reason, User::query()->findOrFail($this->operator->id)) ?: throw new RuntimeException('no change');
    $results = race([$suspend('Operator one'), $suspend('Operator two')]);

    expect(collect($results)->filter(fn ($r) => $r === 'ok'))->toHaveCount(1)
        ->and($this->tenant->fresh()->status)->toBe(TenantStatus::Suspended)
        ->and($this->tenant->fresh()->suspended_from)->toBe('trial')
        ->and(($this->platformEvents)('TENANT_SUSPENDED'))->toBe(1);
});

it('5. sends a failed notification again once when two administrators resend it at once', function () {
    actAsTenant($this->tenant);
    $admin = tenantUser($this->tenant, ['notification.deliveries', 'notification.resend']);
    $delivery = NotificationDelivery::query()->create(['user_id' => $admin->id, 'channel' => 'email', 'subject' => 'Race', 'body' => 'Race', 'status' => 'failed', 'recipient' => 'race@acme.test']);
    actAsTenant(null);

    $resend = fn () => fn () => app(TenantContext::class)->runAs(Tenant::query()->findOrFail($this->tenant->id),
        fn () => app(NotificationRecovery::class)->resend(NotificationDelivery::query()->findOrFail($delivery->id), User::query()->findOrFail($admin->id), 'Mail was down'));
    $results = race([$resend(), $resend()]);

    actAsTenant($this->tenant);
    expect(collect($results)->filter(fn ($r) => $r === 'ok'))->toHaveCount(1)
        ->and($delivery->fresh()->resend_count)->toBe(1)
        ->and($delivery->fresh()->status)->toBe('sent')
        ->and(($this->tenantEvents)('NOTIFICATION_RESENT'))->toBe(1);
});

it('6. dispatches a lost delivery again once when two recovery sweeps run at once', function () {
    actAsTenant($this->tenant);
    $user = tenantUser($this->tenant, ['task.view']);
    $lost = NotificationDelivery::query()->create(['user_id' => $user->id, 'channel' => 'email', 'subject' => 'Lost', 'body' => 'Lost', 'status' => 'queued', 'recipient' => 'lost@acme.test']);
    $stuck = NotificationDelivery::query()->create(['user_id' => $user->id, 'channel' => 'email', 'subject' => 'Stuck', 'body' => 'Stuck', 'status' => 'sending', 'recipient' => 'stuck@acme.test']);
    NotificationDelivery::query()->whereKey([$lost->id, $stuck->id])->update(['updated_at' => now()->subHours(2)]);
    actAsTenant(null);

    $sweep = fn () => fn () => throw new RuntimeException(json_encode(app(TenantContext::class)->runAs(Tenant::query()->findOrFail($this->tenant->id), fn () => app(NotificationRecovery::class)->recover())));
    $results = collect(race([$sweep(), $sweep()]))->map(fn ($r) => json_decode($r, true));

    actAsTenant($this->tenant);
    expect($results->sum('requeued'))->toBe(1)
        ->and($results->sum('stale_sending'))->toBe(1)
        ->and($lost->fresh()->status)->toBe('sent')
        ->and($stuck->fresh()->status)->toBe('failed');
});

it('7. issues one invitation when two operators send the first administrator\'s invitation at once', function () {
    actAsTenant($this->tenant);
    $owner = tenantUser($this->tenant, [], ['email' => 'owner-'.uniqid().'@race.test', 'status' => UserStatus::Invited]);
    $owner->roles()->attach(app(TenantContext::class)->runAs($this->tenant, fn () => Role::query()->where('slug', 'tenant-super-admin')->value('id')));
    actAsTenant(null);
    $second = User::factory()->platformAdmin()->create();

    $send = fn (User $by) => fn () => app(TenantFirstAdministrator::class)->send(Tenant::query()->findOrFail($this->tenant->id), $by, 'Customer asked for the link');
    $results = race([$send($this->operator), $send($second)], slow: ['eloquent.creating: '.UserInvitation::class]);

    expect(collect($results)->filter(fn ($r) => $r === 'ok'))->toHaveCount(1)
        ->and(collect($results)->reject(fn ($r) => $r === 'ok')->first())->toContain('moments ago')
        ->and(UserInvitation::query()->withoutTenancy()->where('user_id', $owner->id)->count())->toBe(1)
        ->and(($this->platformEvents)('INVITATION_ISSUED'))->toBe(1);
});

it('8. provisions one tenant when two operators submit the same slug at once', function () {
    $slug = 'race-'.uniqid();
    $second = User::factory()->platformAdmin()->create();

    $provision = fn (User $by) => fn () => app(ProvisionTenantAction::class)->handle(['name' => 'Race Co', 'slug' => $slug], null, 'Signed order form', $by);
    $results = race([$provision($this->operator), $provision($second)], slow: ['eloquent.creating: '.Tenant::class]);

    expect(collect($results)->filter(fn ($r) => $r === 'ok'))->toHaveCount(1)
        ->and(Tenant::query()->where('slug', $slug)->count())->toBe(1)
        ->and(AuditEvent::query()->withoutTenancy()->whereNull('tenant_id')->where('action', 'TENANT_PROVISIONED')->where('metadata->subject_tenant', $slug)->count())->toBe(1);
});

it('9. reads an audit chain\'s head through the chain index, never by scanning other tenants\' rows', function () {
    // Other tenants write after this one, so a backward primary-key scan would have to pass their rows first.
    foreach (range(1, 3) as $i) {
        app(TenantContext::class)->runAs(provisionTenant('Busy '.uniqid()), fn () => Company::factory()->count(5)->create());
    }
    $captured = null;
    DB::listen(function ($query) use (&$captured) {
        if (str_contains($query->sql, 'from `audit_events`') && str_contains($query->sql, 'for update') && str_contains($query->sql, 'limit 1')) {
            $captured ??= $query;
        }
    });
    app(TenantContext::class)->runAs($this->tenant, fn () => Company::factory()->create());
    expect($captured)->not->toBeNull();

    $plan = DB::select('EXPLAIN '.str_replace(' for update', '', $captured->sql), $captured->bindings)[0];
    $analyze = DB::select('EXPLAIN ANALYZE '.str_replace(' for update', '', $captured->sql), $captured->bindings)[0]->EXPLAIN;
    preg_match('/actual time=[^)]*rows=(\d+)/', substr($analyze, strrpos($analyze, '->')), $m);

    expect($plan->key)->toBe('audit_events_chain_head_index')
        ->and((int) ($m[1] ?? PHP_INT_MAX))->toBeLessThanOrEqual(1);
});
