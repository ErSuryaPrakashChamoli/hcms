<?php

namespace App\Console\Commands;

use App\Domain\Entitlements\Enums\Capability;
use App\Domain\Entitlements\Services\EntitlementDiagnostics;
use App\Domain\Platform\Models\Tenant;
use Illuminate\Console\Command;

/** SaaS.3: why a tenant gets each entitlement decision on a business date (platform diagnostics, read-only). */
class ExplainEntitlements extends Command
{
    protected $signature = 'peopleos:entitlements:explain {tenant : Tenant id or slug} {capability? : One capability key (all when omitted)} {--at= : Business date YYYY-MM-DD (default today, UTC)}';

    protected $description = 'Explain a tenant\'s commercial entitlement decisions (shadow mode; changes nothing)';

    public function handle(EntitlementDiagnostics $diagnostics): int
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

        $rows = [];
        foreach ($capabilities as $capability) {
            $e = $diagnostics->explain($tenant, $capability, $this->option('at'));
            $d = $e['decision'];
            $rows[] = [$capability->value, $capability->type()->value, $d->outcome->value, $d->reason->value, $d->source->value,
                $e['override'] ? '#'.$e['override']['id'] : '', $e['configuration'] ? '#'.$e['configuration']['id'] : '', $d->limit ?? ''];
        }
        $first = $diagnostics->explain($tenant, Capability::Core, $this->option('at'));
        $this->info("{$tenant->slug}: ".($first['configured_from'] ? "configured from {$first['configured_from']}" : 'unconfigured')." · version {$first['version']} · on ".($this->option('at') ?? now()->toDateString()).' · mode shadow (not enforced)');
        $this->table(['Capability', 'Type', 'Decision', 'Reason', 'Source', 'Override', 'Configuration', 'Limit'], $rows);

        return self::SUCCESS;
    }
}
