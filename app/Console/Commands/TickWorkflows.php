<?php

namespace App\Console\Commands;

use App\Domain\Platform\Models\Tenant;
use App\Domain\Workflow\Services\EscalationEngine;
use App\Domain\Workflow\Services\WorkflowEngine;
use App\Support\Tenancy\TenantContext;
use Illuminate\Console\Command;

class TickWorkflows extends Command
{
    protected $signature = 'peopleos:workflows:tick';

    protected $description = 'Resume waiting workflows whose time has come and run task escalations (all tenants)';

    public function handle(WorkflowEngine $engine, EscalationEngine $escalations, TenantContext $tenants): int
    {
        Tenant::query()->orderBy('id')->each(function (Tenant $tenant) use ($engine, $escalations, $tenants) {
            [$resumed, $escalated] = $tenants->runAs($tenant, fn () => [$engine->tick(), $escalations->run()]);

            if ($resumed || $escalated) {
                $this->info("{$tenant->slug}: resumed {$resumed}, escalation actions {$escalated}");
            }
        });

        return self::SUCCESS;
    }
}
