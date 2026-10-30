<?php

namespace App\Console\Commands;

use App\Domain\Analytics\Services\DashboardDeliveries;
use App\Domain\Analytics\Services\ReportSchedules;
use App\Domain\Platform\Models\Tenant;
use App\Support\Tenancy\TenantContext;
use App\Support\Tenancy\TenantRunner;
use Illuminate\Console\Command;

class RunScheduledReports extends Command
{
    protected $signature = 'peopleos:reports:run-due {--tenant=}';

    protected $description = 'Run report schedules that are due, store the export and notify recipients; send the shared dashboard views\' notices that are due';

    public function handle(ReportSchedules $schedules, TenantContext $tenants, DashboardDeliveries $views): int
    {
        $runner = TenantRunner::for($this);
        Tenant::query()
            ->when($this->option('tenant'), fn ($q, $t) => $q->where('slug', $t)->orWhere('id', $t))
            ->orderBy('id')
            ->each($runner->isolate(fn (Tenant $tenant) => $tenants->runAs($tenant, function () use ($tenant, $schedules, $views) {
                $this->info("{$tenant->slug}: ".$schedules->runDue().' schedule(s) run');
                // HROne parity CA-10: shared dashboard views e-mailed on a cadence (a link only; never figures).
                $sent = $views->runDue();
                if ($sent > 0) {
                    $this->info("{$tenant->slug}: {$sent} dashboard view notice(s) sent");
                }
            })));

        return $runner->exitCode();
    }
}
