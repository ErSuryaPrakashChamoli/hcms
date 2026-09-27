<?php

namespace App\Support\Tenancy;

use App\Domain\Platform\Models\Tenant;
use Closure;
use Illuminate\Support\Facades\Context;

/**
 * Holds the tenant the current execution is acting for.
 *
 * Every tenant-scoped query is constrained by this context (see TenantScope). When no tenant is
 * bound, tenant-scoped queries fail closed and return nothing. Platform-level code that legitimately
 * needs to cross tenants must be explicit about it via bypass().
 */
final class TenantContext
{
    private ?Tenant $tenant = null;

    private int $bypassDepth = 0;

    public function set(?Tenant $tenant): void
    {
        $this->tenant = $tenant;

        // Hidden context propagates to queued jobs without leaking into log lines.
        Context::addHidden('tenant_id', $tenant?->getKey());
    }

    public function current(): ?Tenant
    {
        return $this->tenant;
    }

    public function id(): ?int
    {
        return $this->tenant?->getKey();
    }

    public function has(): bool
    {
        return $this->tenant !== null;
    }

    public function clear(): void
    {
        $this->set(null);
    }

    /**
     * Run the callback with the given tenant bound, restoring the previous tenant afterwards.
     *
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    public function runAs(Tenant $tenant, Closure $callback): mixed
    {
        $previous = $this->tenant;
        $this->set($tenant);

        try {
            return $callback();
        } finally {
            $this->set($previous);
        }
    }

    /**
     * Run the callback with tenant scoping disabled. Reserved for platform operations
     * (provisioning, integrity verification, platform administration).
     *
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    public function bypass(Closure $callback): mixed
    {
        $this->bypassDepth++;

        try {
            return $callback();
        } finally {
            $this->bypassDepth--;
        }
    }

    public function isBypassed(): bool
    {
        return $this->bypassDepth > 0;
    }
}
