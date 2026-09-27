<?php

namespace App\Support\Tenancy\Jobs;

/**
 * Contract for every queued job that touches tenant-owned data (architecture contract §33):
 * the job captures the tenant at dispatch and returns [new BindTenantContext] from middleware(),
 * so the worker re-binds it before handle() runs. Enforced by the architecture tests.
 */
interface TenantAwareJob
{
    public function tenantId(): ?int;
}
