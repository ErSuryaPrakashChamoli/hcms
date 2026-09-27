<?php

namespace App\Domain\Attendance\Jobs;

use App\Domain\Attendance\Models\AttendancePunch;
use App\Domain\Attendance\Services\AttendanceProcessor;
use App\Domain\Employment\Models\Employee;
use App\Support\Tenancy\Jobs\BindTenantContext;
use App\Support\Tenancy\Jobs\TenantAwareJob;
use App\Support\Tenancy\TenantContext;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * Recalculate one employee-day (Phase 2 §35). Idempotent: the processor rebuilds the day from raw
 * punches; a failure marks the day's punches failed with the error so they can be retried.
 */
class ProcessAttendanceDay implements ShouldQueue, TenantAwareJob
{
    use Queueable;

    public int $tries = 3;

    public ?int $tenantId;

    public function __construct(public readonly int $employeeId, public readonly string $date, public readonly ?string $reason = null)
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
        if ($employee === null) {
            return;
        }

        try {
            $processor->process($employee, Carbon::parse($this->date));
        } catch (Throwable $e) {
            AttendancePunch::query()->where('employee_id', $this->employeeId)->whereDate('punched_at', $this->date)->where('processing_status', '!=', 'processed')
                ->update(['processing_status' => 'failed', 'processing_error' => mb_substr($e->getMessage(), 0, 1000)]);
            throw $e;
        }
    }
}
