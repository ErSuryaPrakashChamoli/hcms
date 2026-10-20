<?php

use App\Domain\Audit\Models\AuditEvent;
use App\Domain\Organisation\Models\Company;
use App\Domain\Platform\Enums\TenantStatus;
use App\Domain\Platform\Models\TenantDeferredJob;
use App\Domain\Platform\Services\TenantSuspensions;
use App\Support\Tenancy\Jobs\BindTenantContext;
use App\Support\Tenancy\Jobs\DeferredTenantJobs;
use App\Support\Tenancy\Jobs\RunsForSuspendedTenants;
use App\Support\Tenancy\Jobs\TenantAwareJob;
use App\Support\Tenancy\TenantContext;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Tests\Support\TenantAwareTestJob;

/*
| SaaS.8: queued work that reaches a worker while its tenant is suspended is deferred and dispatched again, once, when the
| tenant is reactivated. It used to be acknowledged and lost.
*/

class SaaS8RecordingJob implements RunsForSuspendedTenants, ShouldQueue, TenantAwareJob
{
    use Queueable;

    public function __construct(public int $tenantId, public string $name) {}

    public function tenantId(): ?int
    {
        return $this->tenantId;
    }

    public function middleware(): array
    {
        return [new BindTenantContext];
    }

    public function handle(): void
    {
        Company::factory()->create(['name' => $this->name]);
    }
}

beforeEach(function () {
    $this->tenant = provisionTenant('Acme');
    $this->other = provisionTenant('Other');
    $this->operator = platformAdmin();
    $this->suspensions = app(TenantSuspensions::class);
    $this->deferred = app(DeferredTenantJobs::class);
    $this->companies = fn (string $name) => app(TenantContext::class)->runAs($this->tenant, fn () => Company::query()->where('name', $name)->count());
});

it('defers a job that reaches a worker while its tenant is suspended, and runs it once after reactivation', function () {
    $this->suspensions->suspend($this->tenant, 'Contract review', $this->operator);
    actAsTenant($this->tenant);
    dispatch(new TenantAwareTestJob('Queued before the suspension'));
    actAsTenant(null);

    expect(($this->companies)('Queued before the suspension'))->toBe(0)
        ->and($this->deferred->pending($this->tenant))->toBe(1);

    expect($this->suspensions->reactivate($this->tenant, 'Contract renewed', $this->operator))->toBeTrue();
    expect(($this->companies)('Queued before the suspension'))->toBe(1)
        ->and($this->deferred->pending($this->tenant))->toBe(0);

    // A second release (the operator's command, another worker) dispatches nothing again.
    expect($this->deferred->release($this->tenant))->toBe(0)
        ->and(($this->companies)('Queued before the suspension'))->toBe(1);
});

it('keeps the deferred job encrypted at rest and never releases it while the tenant is still suspended', function () {
    $this->suspensions->suspend($this->tenant, 'Investigation', $this->operator);
    actAsTenant($this->tenant);
    dispatch(new TenantAwareTestJob('Secret-ish company name'));
    actAsTenant(null);

    $raw = DB::table('tenant_deferred_jobs')->value('payload');
    expect($raw)->not->toContain('Secret-ish')->not->toContain('TenantAwareTestJob')
        ->and(DB::table('tenant_deferred_jobs')->value('job_class'))->toBe(TenantAwareTestJob::class)
        ->and($this->deferred->release($this->tenant))->toBe(0)
        ->and($this->deferred->pending($this->tenant))->toBe(1);
});

it('still runs a job that records what already happened outside PeopleOS (never deferred)', function () {
    $this->suspensions->suspend($this->tenant, 'Investigation', $this->operator);
    dispatch(new SaaS8RecordingJob($this->tenant->id, 'Provider event'));

    expect(($this->companies)('Provider event'))->toBe(1)
        ->and($this->deferred->pending($this->tenant))->toBe(0);
});

it('releases only the reactivated tenant\'s work, and records the count on both chains', function () {
    $this->suspensions->suspend($this->tenant, 'Investigation', $this->operator);
    $this->suspensions->suspend($this->other, 'Investigation', $this->operator);
    foreach ([$this->tenant, $this->tenant, $this->other] as $i => $tenant) {
        actAsTenant($tenant);
        dispatch(new TenantAwareTestJob("Job {$i}"));
    }
    actAsTenant(null);

    $this->suspensions->reactivate($this->tenant, 'Cleared', $this->operator);

    expect($this->deferred->pending($this->tenant))->toBe(0)
        ->and($this->deferred->pending($this->other))->toBe(1)
        ->and(app(TenantContext::class)->runAs($this->other, fn () => Company::query()->where('name', 'Job 2')->count()))->toBe(0);
    $events = AuditEvent::query()->withoutTenancy()->where('action', 'TENANT_REACTIVATED')->get();
    expect($events)->toHaveCount(2)
        ->and($events->every(fn ($e) => ($e->metadata['deferred_jobs'] ?? null) === 2))->toBeTrue();
});

it('releases at once a job deferred after the tenant was already reactivated', function () {
    $job = new TenantAwareTestJob('Raced the reactivation');
    $job->tenantId = $this->tenant->id;

    // The worker read "suspended", then the operator reactivated, then the worker stored the job.
    $this->deferred->defer($job, $this->tenant);

    expect($this->deferred->pending($this->tenant))->toBe(0)
        ->and(($this->companies)('Raced the reactivation'))->toBe(1);
});

it('restores the status the suspension interrupted: trial stays trial, active stays active, unknown becomes active', function () {
    $this->tenant->forceFill(['status' => TenantStatus::Trial])->save();
    $this->suspensions->suspend($this->tenant, 'Investigation', $this->operator);
    expect($this->tenant->fresh()->status)->toBe(TenantStatus::Suspended)
        ->and($this->tenant->fresh()->suspended_from)->toBe('trial')
        ->and($this->tenant->fresh()->suspended_at)->not->toBeNull();
    $this->suspensions->reactivate($this->tenant, 'Cleared', $this->operator);
    expect($this->tenant->fresh()->status)->toBe(TenantStatus::Trial)
        ->and($this->tenant->fresh()->suspended_from)->toBeNull();

    $this->suspensions->suspend($this->other, 'Investigation', $this->operator);
    $this->suspensions->reactivate($this->other, 'Cleared', $this->operator);
    expect($this->other->fresh()->status)->toBe(TenantStatus::Active);

    // Suspended before SaaS.8 (nothing recorded): active, as before.
    $this->other->forceFill(['status' => TenantStatus::Suspended, 'suspended_from' => null])->save();
    $this->suspensions->reactivate($this->other, 'Cleared', $this->operator);
    expect($this->other->fresh()->status)->toBe(TenantStatus::Active);

    $changes = AuditEvent::query()->withoutTenancy()->where('action', 'TENANT_REACTIVATED')->whereNull('tenant_id')->orderBy('id')->first()->fieldChanges()->first();
    expect($changes->before)->toBe('suspended')->and($changes->after)->toBe('trial');
});

it('lets only a platform operator suspend or reactivate, in the service itself', function () {
    actAsTenant($this->tenant);
    $admin = tenantUser($this->tenant, ['*']);
    actAsTenant(null);

    expect(fn () => $this->suspensions->suspend($this->tenant, 'Not mine to do', $admin))->toThrow(RuntimeException::class, 'Only platform operators')
        ->and($this->tenant->fresh()->status)->toBe(TenantStatus::Active)
        ->and(fn () => $this->suspensions->suspend($this->tenant, 'abc', $this->operator))->toThrow(RuntimeException::class, 'A reason is required');

    $this->suspensions->suspend($this->tenant, 'Investigation', $this->operator);
    expect(fn () => $this->suspensions->reactivate($this->tenant, 'Let me back in', $admin))->toThrow(RuntimeException::class, 'Only platform operators')
        ->and($this->tenant->fresh()->status)->toBe(TenantStatus::Suspended);

    // A web request that names no operator is refused, whoever is signed in (the console may act without one).
    $console = new ReflectionProperty(app(), 'isRunningInConsole');
    $console->setValue(app(), false);
    try {
        expect(fn () => $this->suspensions->reactivate($this->tenant, 'No operator named'))->toThrow(RuntimeException::class, 'Only platform operators');
    } finally {
        $console->setValue(app(), null);
    }
    expect($this->tenant->fresh()->status)->toBe(TenantStatus::Suspended)
        ->and(TenantDeferredJob::query()->withoutTenancy()->count())->toBe(0);
});
