<?php

namespace App\Domain\ComplianceReports\Jobs;

use App\Domain\ComplianceReports\Models\ComplianceReportPack;
use App\Domain\ComplianceReports\Services\ComplianceReports;
use App\Support\Tenancy\Jobs\BindTenantContext;
use App\Support\Tenancy\Jobs\TenantAwareJob;
use App\Support\Tenancy\TenantContext;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Builds a requested compliance report pack. Tenant-bound, unique per pack, and safe to retry: a pack still queued or
 * running is rebuilt from scratch; a completed one is left alone. It only reads.
 */
class GenerateComplianceReportPack implements ShouldBeUnique, ShouldQueue, TenantAwareJob
{
    use Queueable;

    public int $tries = 2;

    /** @var list<int> */
    public array $backoff = [120];

    public int $timeout = 1800;

    public ?int $tenantId;

    public function __construct(public readonly int $packId)
    {
        $this->tenantId = app(TenantContext::class)->id();
    }

    public function tenantId(): ?int
    {
        return $this->tenantId;
    }

    public function uniqueId(): string
    {
        return "{$this->tenantId}:{$this->packId}";
    }

    /** @return list<object> */
    public function middleware(): array
    {
        return [new BindTenantContext];
    }

    public function handle(ComplianceReports $reports): void
    {
        $pack = ComplianceReportPack::query()->find($this->packId);
        if ($pack !== null && in_array($pack->status, ['queued', 'running'], true)) {
            $reports->build($pack);
        }
    }
}
