<?php

namespace App\Domain\Attendance\Jobs;

use App\Domain\Attendance\Services\AttendanceProcessor;
use App\Domain\Employment\Models\Employee;
use App\Support\Tenancy\Jobs\BindTenantContext;
use App\Support\Tenancy\Jobs\TenantAwareJob;
use App\Support\Tenancy\TenantContext;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Recalculate a run of one employee's days (a monthly attendance upload marks up to a month at once). Idempotent:
 * the processor rebuilds each day from its inputs and leaves days locked by payroll as they are.
 */
class ProcessAttendanceRange implements ShouldQueue, TenantAwareJob
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 600;

    /** @var list<int> */
    public array $backoff = [60, 300];

    public ?int $tenantId;

    public function __construct(public readonly int $employeeId, public readonly string $from, public readonly string $to)
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
    }
}
