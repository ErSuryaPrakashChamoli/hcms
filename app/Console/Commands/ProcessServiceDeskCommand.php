<?php

namespace App\Console\Commands;

use App\Domain\Platform\Models\Tenant;
use App\Domain\ServiceDesk\Jobs\ProcessServiceDesk;
use App\Domain\ServiceDesk\Services\ServiceDeskProcessor;
use App\Support\Tenancy\TenantContext;
use App\Support\Tenancy\TenantRunner;
use Illuminate\Console\Command;

class ProcessServiceDeskCommand extends Command
{
    protected $signature = 'peopleos:service-desk:process {--tenant=} {--queue : Dispatch one tenant-bound job per tenant instead of running inline}';

    protected $description = 'Service desk: promote due catalogue versions, warn and escalate SLAs, remind waiting requests, auto-close, escalate overdue grievances, remind policy acknowledgements (idempotent)';

    public function handle(ServiceDeskProcessor $processor, TenantContext $tenants): int
    {
        $runner = TenantRunner::for($this);
        Tenant::query()
            ->when($this->option('tenant'), fn ($q, $t) => $q->where('slug', $t)->orWhere('id', $t))
            ->orderBy('id')
            ->each($runner->isolate(function (Tenant $tenant) use ($processor, $tenants) {
                $tenants->runAs($tenant, function () use ($processor, $tenant) {
                    if ($this->option('queue')) {
                        ProcessServiceDesk::dispatch();
                        $this->info("{$tenant->slug}: queued");

                        return;
                    }
                    $r = $processor->run();
                    $this->info("{$tenant->slug}: ".collect($r)->map(fn ($n, $k) => "{$k} {$n}")->implode(', '));
                });
            }));

        return $runner->exitCode();
    }
}
