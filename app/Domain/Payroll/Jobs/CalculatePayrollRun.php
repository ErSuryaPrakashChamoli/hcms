<?php

namespace App\Domain\Payroll\Jobs;

use App\Domain\Identity\Models\User;
use App\Domain\Payroll\Models\PayrollRun;
use App\Domain\Payroll\Services\PayrollRuns;
use App\Support\Tenancy\Jobs\BindTenantContext;
use App\Support\Tenancy\Jobs\TenantAwareJob;
use App\Support\Tenancy\TenantContext;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Queued calculation for large runs (Phase 4 §45). Unique per run, tenant-bound, retry-safe: a
 * recalculation replaces the run's entries inside one transaction under the run lock.
 */
class CalculatePayrollRun implements ShouldBeUnique, ShouldQueue, TenantAwareJob
{
    use Queueable;

    public int $tries = 2;

    /** @var list<int> Phase 14: retry with backoff instead of hammering a failing dependency. */
    public array $backoff = [60];

    public int $timeout = 3600;

    public ?int $tenantId;

    public function __construct(public readonly int $runId, public readonly ?int $actorId = null)
    {
        $this->tenantId = app(TenantContext::class)->id();
    }

    public function uniqueId(): string
    {
        return "payroll-run-{$this->tenantId}-{$this->runId}";
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

    public function handle(PayrollRuns $runs): void
    {
        $run = PayrollRun::query()->find($this->runId);
        if ($run !== null && $run->isEditable()) {
            $runs->calculate($run, $this->actorId ? User::query()->find($this->actorId) : null);
        }
    }
}
