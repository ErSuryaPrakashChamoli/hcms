<?php

namespace App\Domain\Integration\Jobs;

use App\Domain\Integration\Models\IntegrationLogExport;
use App\Domain\Integration\Services\IntegrationLogExports;
use App\Domain\Platform\Models\Tenant;
use App\Support\Tenancy\Jobs\BindTenantContext;
use App\Support\Tenancy\Jobs\TenantAwareJob;
use App\Support\Tenancy\TenantContext;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * HROne parity SV-26: builds one queued log export (IntegrationLogExports::build) in the tenant it was asked in. Unique per
 * export and safe to retry: a queued or running export is rebuilt from scratch, a finished one is left alone. It only reads.
 */
class ExportIntegrationLog implements ShouldBeUnique, ShouldQueue, TenantAwareJob
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 900;

    /** @var list<int> */
    public array $backoff = [60, 300];

    public ?int $tenantId;

    public function __construct(public readonly int $exportId)
    {
        $this->tenantId = app(TenantContext::class)->id();
    }

    public function tenantId(): ?int
    {
        return $this->tenantId;
    }

    public function uniqueId(): string
    {
        return "{$this->tenantId}:{$this->exportId}";
    }

    /** @return list<object> */
    public function middleware(): array
    {
        return [new BindTenantContext];
    }

    public function handle(IntegrationLogExports $exports): void
    {
        $export = IntegrationLogExport::query()->find($this->exportId);
        if ($export !== null) {
            $exports->build($export);
        }
    }

    public function failed(?Throwable $e): void
    {
        $tenant = $this->tenantId === null ? null : Tenant::query()->find($this->tenantId);
        if ($tenant === null) {
            return;
        }
        app(TenantContext::class)->runAs($tenant, fn () => IntegrationLogExport::query()->whereKey($this->exportId)->whereIn('status', ['queued', 'running'])->first()
            ?->update(['status' => 'failed', 'error' => mb_substr('The export stopped: '.($e?->getMessage() ?? 'unknown error'), 0, 500), 'finished_at' => now()]));
    }
}
