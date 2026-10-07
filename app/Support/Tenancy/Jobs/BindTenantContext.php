<?php

namespace App\Support\Tenancy\Jobs;

use App\Domain\Identity\Services\AccessScopes;
use App\Domain\Platform\Models\Tenant;
use App\Support\Tenancy\TenantContext;
use Closure;
use Illuminate\Queue\Jobs\SyncJob;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Job middleware: re-binds the tenant captured at dispatch time so tenant-owned models created
 * inside a queue worker are stamped and scoped exactly as they would be in the request.
 * Jobs implement TenantAwareJob (or expose `public ?int $tenantId`) and return `[new BindTenantContext]`
 * from middleware(). The previous context is restored when the job finishes (runAs).
 *
 * Phase 14 hardening:
 * - A TenantAwareJob without a tenant fails; it never runs unscoped.
 * - A job for a suspended tenant is skipped and logged, not run, unless it records something that already happened
 *   outside PeopleOS (RunsForSuspendedTenants: payment reconciliation only, SaaS.7).
 * - Per-user organisation scopes cached by AccessScopes are cleared before and after each job, so a
 *   long-running worker never carries one job's scope into the next.
 */
final class BindTenantContext
{
    public function handle(object $job, Closure $next): mixed
    {
        $tenantId = $job instanceof TenantAwareJob ? $job->tenantId() : ($job->tenantId ?? null);
        $context = app(TenantContext::class);

        if ($tenantId === null) {
            if ($job instanceof TenantAwareJob) {
                throw new RuntimeException($job::class.' is tenant-aware but was dispatched without a tenant; refusing to run it unscoped.');
            }

            return $next($job);
        }

        $tenant = $context->bypass(fn () => Tenant::query()->findOrFail($tenantId));
        if (! $tenant->isAccessible() && ! $job instanceof RunsForSuspendedTenants) {
            Log::warning('Queued job skipped: tenant suspended', ['job' => $job::class, 'tenant_id' => $tenant->id]);

            return null;
        }

        // A sync job runs inside its caller's request, whose cached scopes stay valid; a worker job does not.
        $worker = property_exists($job, 'job') && $job->job !== null && ! $job->job instanceof SyncJob;
        $worker && app(AccessScopes::class)->forget();
        try {
            return $context->runAs($tenant, fn () => $next($job));
        } finally {
            $worker && app(AccessScopes::class)->forget();
        }
    }
}
