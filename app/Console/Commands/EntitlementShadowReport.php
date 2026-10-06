<?php

namespace App\Console\Commands;

use App\Domain\Entitlements\Services\EntitlementDiagnostics;
use App\Domain\Platform\Models\Tenant;
use Illuminate\Console\Command;

/** SaaS.3: what shadow mode observed: what would have been denied, and what could not be decided. */
class EntitlementShadowReport extends Command
{
    protected $signature = 'peopleos:entitlements:shadow-report {--days=7} {--tenant= : Tenant id or slug (all when omitted)} {--by-surface}';

    protected $description = 'Summarise shadow entitlement observations (counts are lower bounds)';

    public function handle(EntitlementDiagnostics $diagnostics): int
    {
        $tenantId = null;
        if ($this->option('tenant') !== null) {
            $tenantId = Tenant::query()->where('slug', $this->option('tenant'))->orWhere('id', $this->option('tenant'))->value('id');
            if ($tenantId === null) {
                $this->error('No such tenant.');

                return self::FAILURE;
            }
        }
        $rows = $diagnostics->shadowSummary((int) $this->option('days'), $tenantId, (bool) $this->option('by-surface'));
        $headers = $this->option('by-surface') ? ['Capability', 'Decision', 'Reason', 'Surface', 'Occurrences', 'Tenants', 'Last seen'] : ['Capability', 'Decision', 'Reason', 'Occurrences', 'Tenants', 'Last seen'];
        $this->table($headers, $rows->map(fn ($r) => array_values((array) $r))->all());

        return self::SUCCESS;
    }
}
