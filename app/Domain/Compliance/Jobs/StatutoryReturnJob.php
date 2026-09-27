<?php

namespace App\Domain\Compliance\Jobs;

use App\Domain\Identity\Models\User;
use App\Support\Tenancy\Jobs\BindTenantContext;
use App\Support\Tenancy\Jobs\TenantAwareJob;
use App\Support\Tenancy\TenantContext;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Base for the Part W statutory jobs: tenant-bound (BindTenantContext), unique per return scope,
 * retry-safe (generation rebuilds an editable return inside one transaction and refuses an
 * approved one), and never touching payroll, which it only reads once finalized.
 */
abstract class StatutoryReturnJob implements ShouldBeUnique, ShouldQueue, TenantAwareJob
{
    use Queueable;

    public int $tries = 2;

    public int $timeout = 1800;

    public ?int $tenantId;

    public function __construct(public readonly ?int $actorId)
    {
        $this->tenantId = app(TenantContext::class)->id();
    }

    public function tenantId(): ?int
    {
        return $this->tenantId;
    }

    /** @return list<object> */
    public function middleware(): array
    {
        return [new BindTenantContext];
    }

    protected function actor(): User
    {
        return User::query()->findOrFail($this->actorId);
    }
}
