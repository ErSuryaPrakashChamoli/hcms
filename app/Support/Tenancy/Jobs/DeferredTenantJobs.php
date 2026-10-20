<?php

namespace App\Support\Tenancy\Jobs;

use App\Domain\Platform\Models\Tenant;
use App\Domain\Platform\Models\TenantDeferredJob;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * SaaS.8: queued work for a suspended tenant waits for reactivation instead of being lost.
 *
 * Before, BindTenantContext acknowledged such a job and returned: the queue deleted it, so a notification, webhook,
 * payroll calculation or certificate queued just before a suspension never happened after the reactivation.
 *
 * - defer(): the job (without its queue handle) is serialized and kept encrypted with the application key. A job that
 *   records something that already happened outside PeopleOS (RunsForSuspendedTenants) still runs and is never deferred.
 * - release(): every deferred job of an accessible tenant is dispatched again, oldest first, and its row deleted in the
 *   same transaction. The rows are locked first, so two releases at once (the reactivation and the operator's command,
 *   or two workers) dispatch each job once. Delivery is at least once: dispatch happens before the commit, so a failed
 *   commit can repeat a job, never lose it; PeopleOS jobs are idempotent (claimed deliveries, unique locks, delivery ids).
 * - A job deferred while the tenant is being reactivated is released at once: after storing it, defer() reads the status
 *   again, so no deferred job waits for a reactivation that has already happened.
 */
final class DeferredTenantJobs
{
    private const BATCH = 100;

    public function __construct(private readonly TenantContext $tenants) {}

    public function defer(object $job, Tenant $tenant): ?TenantDeferredJob
    {
        $copy = clone $job;
        if (property_exists($copy, 'job')) {
            $copy->job = null;
        }
        $handle = property_exists($job, 'job') ? $job->job : null;
        try {
            $payload = serialize($copy);
        } catch (Throwable $e) {
            // Only an object that never went through a queue (an anonymous class, a closure) cannot be serialized.
            Log::error('Queued job for a suspended tenant could not be deferred', ['job' => $job::class, 'tenant_id' => $tenant->getKey(), 'error' => $e->getMessage()]);

            return null;
        }

        $row = $this->tenants->bypass(fn () => TenantDeferredJob::query()->create([
            'tenant_id' => $tenant->getKey(),
            'job_class' => $job::class,
            'payload' => $payload,
            'connection' => $handle?->getConnectionName(),
            'queue' => $handle?->getQueue(),
            'deferred_at' => now(),
        ]));
        Log::warning('Queued job deferred: tenant suspended', ['job' => $job::class, 'tenant_id' => $tenant->getKey(), 'deferred_job_id' => $row->getKey()]);

        if ($this->tenants->bypass(fn () => Tenant::query()->whereKey($tenant->getKey())->first())?->isAccessible()) {
            $this->release($tenant);
        }

        return $row;
    }

    public function pending(Tenant $tenant): int
    {
        return $this->tenants->bypass(fn () => TenantDeferredJob::query()->where('tenant_id', $tenant->getKey())->count());
    }

    /** @return int the number of jobs dispatched again */
    public function release(Tenant $tenant): int
    {
        $current = $this->tenants->bypass(fn () => Tenant::query()->whereKey($tenant->getKey())->first());
        if ($current === null || ! $current->isAccessible()) {
            return 0;
        }

        $released = 0;
        do {
            $batch = DB::transaction(fn () => $this->tenants->bypass(function () use ($current) {
                $rows = TenantDeferredJob::query()->where('tenant_id', $current->getKey())->orderBy('id')->limit(self::BATCH)->lockForUpdate()->get();
                foreach ($rows as $row) {
                    dispatch(unserialize($row->payload));
                }
                if ($rows->isNotEmpty()) {
                    TenantDeferredJob::query()->whereKey($rows->modelKeys())->delete();
                }

                return $rows->count();
            }), 3);
            $released += $batch;
        } while ($batch === self::BATCH);

        if ($released > 0) {
            Log::info('Deferred jobs released', ['tenant_id' => $current->getKey(), 'count' => $released]);
        }

        return $released;
    }
}
