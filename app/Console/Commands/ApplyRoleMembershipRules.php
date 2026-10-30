<?php

namespace App\Console\Commands;

use App\Domain\Identity\Services\RoleMembershipSync;
use App\Domain\Platform\Models\Tenant;
use App\Support\Tenancy\TenantContext;
use App\Support\Tenancy\TenantRunner;
use Illuminate\Console\Command;

/**
 * HROne parity SV-7: the nightly catch-up of role membership rules (moves effective today, conditions such as tenure,
 * accounts activated or suspended since). Hires, moves and exits apply the rules at once (QueueRoleMembershipSync).
 */
class ApplyRoleMembershipRules extends Command
{
    protected $signature = 'peopleos:access:role-rules {--tenant=}';

    protected $description = 'Grant and remove roles by each tenant\'s role membership rules (only the grants the rules made themselves)';

    public function handle(TenantContext $tenants, RoleMembershipSync $sync): int
    {
        $runner = TenantRunner::for($this);
        Tenant::query()
            ->when($this->option('tenant'), fn ($q, $t) => $q->where('slug', $t)->orWhere('id', $t))
            ->orderBy('id')
            ->each($runner->isolate(fn (Tenant $tenant) => $tenants->runAs($tenant, function () use ($tenant, $sync) {
                if (! $sync->hasRules()) {
                    return;
                }
                $counts = $sync->run();
                $this->info("{$tenant->slug}: {$counts['granted']} granted, {$counts['removed']} removed, {$counts['reassigned']} moved to another rule");
            })));

        return $runner->exitCode();
    }
}
