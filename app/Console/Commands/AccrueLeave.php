<?php

namespace App\Console\Commands;

use App\Domain\Audit\Services\AuditRecorder;
use App\Domain\Employment\Models\Employee;
use App\Domain\Leave\Models\LeaveLedgerEntry;
use App\Domain\Leave\Services\LeaveAccrual;
use App\Domain\Leave\Services\LeaveYear;
use App\Domain\Platform\Models\Tenant;
use App\Support\Tenancy\TenantContext;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

class AccrueLeave extends Command
{
    protected $signature = 'peopleos:leave:accrue {--date= : Accrue as of this date (default today)} {--close-year= : Close this leave year (carry forward + lapse)} {--tenant=}';

    protected $description = 'Post leave accruals due for every employed employee; optionally close a leave year';

    public function handle(LeaveAccrual $accrual, LeaveYear $years, TenantContext $tenants): int
    {
        $asOf = Carbon::parse($this->option('date') ?? now())->startOfDay();

        Tenant::query()
            ->when($this->option('tenant'), fn ($q, $t) => $q->where('slug', $t)->orWhere('id', $t))
            ->orderBy('id')
            ->each(function (Tenant $tenant) use ($accrual, $years, $tenants, $asOf) {
                $tenants->runAs($tenant, function () use ($accrual, $years, $tenant, $asOf) {
                    $entries = 0;
                    $closed = 0;

                    // One audit operation per tenant run: every ledger entry carries its id.
                    app(AuditRecorder::class)->operation('leave', 'Leave accrual '.$asOf->toDateString(), function () use ($accrual, $years, $asOf, &$entries, &$closed) {
                        Employee::query()->with('person')->employed()->orderBy('id')->each(function (Employee $employee) use ($accrual, $years, $asOf, &$entries, &$closed) {
                            if ($this->option('close-year')) {
                                $closed += $accrual->closeYear($employee, (int) $this->option('close-year'));
                            }

                            // Auto-close the previous year on the first run after it ended.
                            $previous = $years->periodFor($asOf) - 1;

                            if ($asOf->gte($years->start($previous + 1)) && ! $this->option('close-year')) {
                                $closed += $accrual->closeYear($employee, $previous);
                            }

                            $entries += $accrual->accrue($employee, $asOf);
                            $closed += $accrual->expireCarryForward($employee, $asOf);
                        });

                        return ['succeeded' => $entries + $closed];
                    }, entityType: LeaveLedgerEntry::class);

                    $this->info("{$tenant->slug}: {$entries} accrual(s), {$closed} year-end movement(s)");
                });
            });

        return self::SUCCESS;
    }
}
