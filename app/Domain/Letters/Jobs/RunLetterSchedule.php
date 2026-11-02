<?php

namespace App\Domain\Letters\Jobs;

use App\Domain\Letters\Models\LetterSchedule;
use App\Domain\Letters\Services\LetterSchedules;
use App\Support\Tenancy\Jobs\BindTenantContext;
use App\Support\Tenancy\Jobs\TenantAwareJob;
use App\Support\Tenancy\TenantContext;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * HROne parity WO-13: runs one letter schedule (LetterSchedules::run) in the tenant it belongs to. A retry is safe: what was
 * done for each employee and occurrence is kept, so no letter is written twice.
 */
class RunLetterSchedule implements ShouldQueue, TenantAwareJob
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 1800;

    /** @var list<int> */
    public array $backoff = [60, 300];

    public ?int $tenantId;

    public function __construct(public readonly int $scheduleId)
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

    public function handle(LetterSchedules $schedules): void
    {
        $schedule = LetterSchedule::query()->find($this->scheduleId);
        if ($schedule !== null) {
            $schedules->run($schedule);
        }
    }
}
