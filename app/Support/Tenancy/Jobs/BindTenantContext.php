<?php

namespace App\Support\Tenancy\Jobs;

use App\Domain\Platform\Models\Tenant;
use App\Support\Tenancy\TenantContext;
use Closure;

/**
 * Job middleware: re-binds the tenant captured at dispatch time so tenant-owned models created
 * inside a queue worker are stamped and scoped exactly as they would be in the request.
 * Jobs implement TenantAwareJob (or expose `public ?int $tenantId`) and return `[new BindTenantContext]`
 * from middleware(). The previous context is restored when the job finishes (runAs).
 */
final class BindTenantContext
{
    public function handle(object $job, Closure $next): mixed
    {
        $tenantId = $job instanceof TenantAwareJob ? $job->tenantId() : ($job->tenantId ?? null);
        $context = app(TenantContext::class);

        if ($tenantId === null) {
            return $next($job);
        }

        $tenant = $context->bypass(fn () => Tenant::query()->findOrFail($tenantId));

        return $context->runAs($tenant, fn () => $next($job));
    }
}
