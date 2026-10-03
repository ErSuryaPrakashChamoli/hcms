<?php

namespace App\Console\Commands;

use App\Domain\Attendance\Services\AttendanceProcessor;
use App\Domain\Platform\Models\Tenant;
use App\Support\Tenancy\TenantContext;
use App\Support\Tenancy\TenantRunner;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

class ProcessAttendance extends Command
{
    protected $signature = 'peopleos:attendance:process {--date= : Date to process (default yesterday)} {--to= : Process a range up to this date} {--tenant=}';

    protected $description = 'Compute attendance records from punches, shifts, holidays and policies for every tenant';

    public function handle(AttendanceProcessor $processor, TenantContext $tenants): int
    {
        $runner = TenantRunner::for($this);
        $from = Carbon::parse($this->option('date') ?? now()->subDay())->startOfDay();
        $to = Carbon::parse($this->option('to') ?? $from)->startOfDay();

        Tenant::query()
            ->when($this->option('tenant'), fn ($q, $t) => $q->where('slug', $t)->orWhere('id', $t))
            ->orderBy('id')
            ->each($runner->isolate(function (Tenant $tenant) use ($processor, $tenants, $from, $to) {
                $tenants->runAs($tenant, function () use ($processor, $tenant, $from, $to) {
                    $total = 0;

                    for ($day = $from->copy(); $day->lte($to); $day->addDay()) {
                        $total += $processor->processAll($day->copy());
                    }

                    $this->info("{$tenant->slug}: {$total} employee-day(s) processed");
                });
            }));

        return $runner->exitCode();
    }
}
