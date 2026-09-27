<?php

namespace App\Console\Commands;

use App\Domain\Analytics\Services\ReportSchedules;
use App\Domain\Platform\Models\Tenant;
use App\Support\Tenancy\TenantContext;
use Illuminate\Console\Command;

class RunScheduledReports extends Command
{
    protected $signature = 'peopleos:reports:run-due {--tenant=}';

    protected $description = 'Run report schedules that are due, store the export and notify recipients';

    public function handle(ReportSchedules $schedules, TenantContext $tenants): int
    {
        Tenant::query()
            ->when($this->option('tenant'), fn ($q, $t) => $q->where('slug', $t)->orWhere('id', $t))
            ->orderBy('id')
            ->each(fn (Tenant $tenant) => $tenants->runAs($tenant, fn () => $this->info("{$tenant->slug}: ".$schedules->runDue().' schedule(s) run')));

        return self::SUCCESS;
    }
}
