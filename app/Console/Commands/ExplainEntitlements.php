<?php

namespace App\Console\Commands;

use App\Domain\Entitlements\Enums\Capability;
use App\Domain\Entitlements\Services\EntitlementDiagnostics;
use App\Domain\Entitlements\Services\EntitlementStateStore;
use App\Domain\Entitlements\Support\PlanValues;
use App\Domain\Platform\Models\Tenant;
use Illuminate\Console\Command;

/**
 * SaaS.3: why a tenant gets each entitlement decision on a business date (platform diagnostics, read-only).
 * SaaS.4: with the plan in force (tenant → plan → version → capability) and each capability's value in that plan.
 * SaaS.5: in the engine's vocabulary (PlanValues), limits in their unit.
 */
class ExplainEntitlements extends Command
{
    protected $signature = 'peopleos:entitlements:explain {tenant : Tenant id or slug} {capability? : One capability key (all when omitted)} {--at= : Business date YYYY-MM-DD (default today, UTC)}';

    protected $description = 'Explain a tenant\'s commercial entitlement decisions (shadow mode; changes nothing)';

    public function handle(EntitlementDiagnostics $diagnostics, EntitlementStateStore $store): int
    {
        $tenant = Tenant::query()->where('slug', $this->argument('tenant'))->orWhere('id', $this->argument('tenant'))->first();
        if ($tenant === null) {
            $this->error('No such tenant.');

            return self::FAILURE;
        }
        $capabilities = $this->argument('capability') !== null ? [Capability::tryFrom((string) $this->argument('capability'))] : Capability::cases();
        if (in_array(null, $capabilities, true)) {
            $this->error('Unknown capability. Known: '.implode(', ', array_map(fn (Capability $c) => $c->value, Capability::cases())));

            return self::FAILURE;
        }

        $state = $store->load($tenant->id);
        $rows = [];
        foreach ($capabilities as $capability) {
            $e = $diagnostics->explain($tenant, $capability, $this->option('at'), $state);
            $d = $e['decision'];
            $rows[] = [$capability->value, $capability->type()->value, $d->outcome->value, $d->reason->value, $d->source->value,
                $e['override'] ? '#'.$e['override']['id'] : '', $e['configuration'] ? '#'.$e['configuration']['id'] : '',
                $e['plan_value'] ?? '', $d->limit === null ? '' : PlanValues::amount($capability, $d->limit)];
        }
        $first = $diagnostics->explain($tenant, Capability::Core, $this->option('at'), $state);
        $plan = $first['plan'] ? "plan {$first['plan']['code']} v{$first['plan']['version']} (assignment #{$first['assignment']['id']} from {$first['assignment']['from']}".($first['assignment']['to'] ? " to {$first['assignment']['to']}" : '').')' : 'no plan in force';
        $this->info("{$tenant->slug}: ".($first['configured_from'] ? "configured from {$first['configured_from']}" : 'unconfigured')." · {$plan} · version {$first['version']} · on ".($this->option('at') ?? now()->toDateString()).' · mode shadow (not enforced)');
        $this->table(['Capability', 'Type', 'Decision', 'Reason', 'Source', 'Override', 'Configuration', 'Plan', 'Limit'], $rows);

        return self::SUCCESS;
    }
}
