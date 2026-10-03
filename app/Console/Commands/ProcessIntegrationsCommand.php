<?php

namespace App\Console\Commands;

use App\Domain\Integration\Services\InboundEvents;
use App\Domain\Platform\Models\Tenant;
use App\Support\Tenancy\TenantContext;
use Illuminate\Console\Command;

class ProcessIntegrationsCommand extends Command
{
    protected $signature = 'peopleos:integrations:process {--tenant=}';

    protected $description = 'Integration Hub: apply due inbound events (new, retrying, expired leases) through their handlers (idempotent claims)';

    public function handle(InboundEvents $events, TenantContext $tenants): int
    {
        Tenant::query()
            ->when($this->option('tenant'), fn ($q, $t) => $q->where('slug', $t)->orWhere('id', $t))
            ->orderBy('id')
            ->each(function (Tenant $tenant) use ($events, $tenants) {
                $tenants->runAs($tenant, function () use ($events, $tenant) {
                    $r = $events->processDue();
                    $this->info("{$tenant->slug}: ".collect($r)->map(fn ($n, $k) => "{$k} {$n}")->implode(', '));
                });
            });

        return self::SUCCESS;
    }
}
