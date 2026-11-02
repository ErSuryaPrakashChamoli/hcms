<?php

namespace App\Domain\Attendance\Jobs;

use App\Domain\Attendance\Services\AttendanceMonthRuns;
use App\Domain\Attendance\Services\AttendanceProcessor;
use App\Domain\Employment\Models\Employee;
use App\Domain\Platform\Models\Tenant;
use App\Support\Tenancy\Jobs\BindTenantContext;
use App\Support\Tenancy\Jobs\TenantAwareJob;
use App\Support\Tenancy\TenantContext;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Recalculate a run of one employee's days (a monthly attendance upload marks up to a month at once). Idempotent:
 * the processor rebuilds each day from its inputs and leaves days locked by payroll as they are.
 *
 * HROne parity TX-3: as part of a month run (Run attendance → Process), the run's counters move when the employee is
 * done, or failed for good after the retries; the last one completes the run and sends its notice.
 */
class ProcessAttendanceRange implements ShouldQueue, TenantAwareJob
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 600;

    /** @var list<int> */
    public array $backoff = [60, 300];

    public ?int $tenantId;

    public function __construct(public readonly int $employeeId, public readonly string $from, public readonly string $to, public readonly ?int $runId = null)
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

    public function handle(AttendanceProcessor $processor): void
    {
        $employee = Employee::query()->find($this->employeeId);
        if ($employee !== null) {
            $processor->processRange($employee, $this->from, $this->to);
        }
        if ($this->runId !== null) {
            app(AttendanceMonthRuns::class)->tick($this->runId, true);
        }
    }

    public function failed(?Throwable $e = null): void
    {
        // failed() runs outside the job middleware: bind the run's tenant here (tenants are not tenant-scoped).
        $tenant = $this->runId === null || $this->tenantId === null ? null : Tenant::query()->find($this->tenantId);
        if ($tenant !== null) {
            app(TenantContext::class)->runAs($tenant, fn () => app(AttendanceMonthRuns::class)->tick((int) $this->runId, false));
        }
    }
}
