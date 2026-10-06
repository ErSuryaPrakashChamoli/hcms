<?php

namespace App\Console\Commands;

use App\Domain\Platform\Models\Tenant;
use App\Domain\Subscriptions\Services\CommercialSubscriptions;
use App\Support\Tenancy\TenantRunner;
use Illuminate\Console\Command;

/**
 * SaaS.6: records the expiries the dates already decided (a trial, term or grace that ended with no successor),
 * effective the day after the end however late this runs. Idempotent, safe to retry and to run twice; one tenant's
 * failure never stops the others. Suspended tenants are included: a technical suspension does not stop commercial
 * dates. Changes nothing else (no tenant status, no entitlement, no notice).
 */
class SettleSubscriptions extends Command
{
    protected $signature = 'peopleos:subscriptions:settle {--tenant= : Tenant id or slug (all when omitted)}';

    protected $description = 'Record commercial subscription expiries whose dates have passed (no money, no enforcement)';

    public function handle(CommercialSubscriptions $subscriptions): int
    {
        $runner = TenantRunner::for($this);
        Tenant::query()->when($this->option('tenant'), fn ($q, $t) => $q->where('slug', $t)->orWhere('id', $t))->orderBy('id')
            ->each($runner->isolate(function (Tenant $tenant) use ($subscriptions) {
                $recorded = $subscriptions->settle($tenant);
                if ($recorded !== []) {
                    $this->info("{$tenant->slug}: ".count($recorded).' expiry recorded');
                }
            }, includeSuspended: true));

        return $runner->exitCode();
    }
}
