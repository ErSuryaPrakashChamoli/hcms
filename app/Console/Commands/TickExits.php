<?php

namespace App\Console\Commands;

use App\Domain\Exit\Services\Exits;
use App\Domain\Platform\Models\Tenant;
use App\Support\Tenancy\TenantContext;
use App\Support\Tenancy\TenantRunner;
use Illuminate\Console\Command;

class TickExits extends Command
{
    protected $signature = 'peopleos:exit:tick {--tenant=}';

    protected $description = 'Start clearance for exits whose last working day is within the lead window';

    public function handle(Exits $exits, TenantContext $tenants): int
    {
        $runner = TenantRunner::for($this);
        Tenant::query()
            ->when($this->option('tenant'), fn ($q, $t) => $q->where('slug', $t)->orWhere('id', $t))
            ->orderBy('id')
            ->each($runner->isolate(fn (Tenant $tenant) => $tenants->runAs($tenant, fn () => $this->info("{$tenant->slug}: ".$exits->tick().' clearance(s) started'))));

        return $runner->exitCode();
    }
}
