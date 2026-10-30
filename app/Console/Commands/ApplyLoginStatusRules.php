<?php

namespace App\Console\Commands;

use App\Domain\Identity\Listeners\DisableLoginOnStatus;
use App\Domain\Platform\Models\Tenant;
use App\Support\Tenancy\TenantContext;
use App\Support\Tenancy\TenantRunner;
use Illuminate\Console\Command;

/**
 * HROne setup parity (Security → Disable login): applies the tenant's "no sign-in in these lifecycle states" rule to
 * every employee, so states listed after employees entered them, and states removed from the list, take effect without
 * a new lifecycle change. Transitions apply the rule at once (DisableLoginOnStatus); this is the daily catch-up.
 */
class ApplyLoginStatusRules extends Command
{
    protected $signature = 'peopleos:security:login-status {--tenant=}';

    protected $description = 'Turn sign-in off (or back on) for employees by the lifecycle states listed in each tenant\'s security policy';

    public function handle(TenantContext $tenants, DisableLoginOnStatus $rule): int
    {
        $runner = TenantRunner::for($this);
        Tenant::query()
            ->when($this->option('tenant'), fn ($q, $t) => $q->where('slug', $t)->orWhere('id', $t))
            ->orderBy('id')
            ->each($runner->isolate(fn (Tenant $tenant) => $tenants->runAs($tenant, function () use ($tenant, $rule) {
                $counts = $rule->reconcile();
                $this->info("{$tenant->slug}: sign-in turned off for {$counts['disabled']}, back on for {$counts['enabled']}");
            })));

        return $runner->exitCode();
    }
}
