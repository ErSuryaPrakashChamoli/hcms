<?php

namespace App\Domain\Employment\Jobs;

use App\Domain\Employment\Imports\EmployeeImport;
use App\Domain\Employment\Imports\Workbooks\WorkbookImports;
use App\Domain\Platform\Models\Tenant;
use App\Support\Tenancy\Jobs\BindTenantContext;
use App\Support\Tenancy\Jobs\TenantAwareJob;
use App\Support\Tenancy\TenantContext;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Master uploads: runs an approved workbook (WorkbookImports::execute) in the tenant it was started in, outside any web
 * request. A retry carries on where the previous attempt stopped (finished worksheets and rows are not run again), and a
 * lock keeps two workers off the same workbook. The timeout stays under the queue's retry_after (3900 s).
 */
class RunWorkbookImport implements ShouldQueue, TenantAwareJob
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 3600;

    /** @var list<int> */
    public array $backoff = [60, 300];

    /** Phases: read and check the uploaded workbook, or run the approved one. */
    public const CHECK = 'check';

    public const RUN = 'run';

    public ?int $tenantId;

    public function __construct(public readonly int $importId, public readonly string $phase = self::RUN)
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

    public function handle(WorkbookImports $workbooks): void
    {
        $import = EmployeeImport::query()->find($this->importId);
        if ($import !== null && $import->isWorkbook()) {
            $this->phase === self::CHECK ? $workbooks->check($import) : $workbooks->execute($import);
        }
    }

    public function failed(?Throwable $e): void
    {
        $tenant = $this->tenantId === null ? null : Tenant::query()->find($this->tenantId);
        if ($tenant === null) {
            return;
        }
        app(TenantContext::class)->runAs($tenant, function () use ($e) {
            $import = EmployeeImport::query()->whereKey($this->importId)->first();
            if ($import === null) {
                return;
            }
            if ($this->phase === self::CHECK) {
                $options = $import->options ?? [];
                unset($options['checking']);
                $import->update(['status' => 'failed', 'options' => $options, 'error' => mb_substr('The workbook could not be checked: '.($e?->getMessage() ?? 'unknown error'), 0, 500)]);
            } elseif ($import->status === 'importing') {
                $import->update(['status' => 'failed', 'imported_at' => now(), 'error' => mb_substr('The run stopped: '.($e?->getMessage() ?? 'unknown error').' Worksheets that finished keep their rows; see each worksheet.', 0, 500)]);
            }
        });
    }
}
