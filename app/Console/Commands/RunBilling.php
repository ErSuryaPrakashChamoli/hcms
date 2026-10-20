<?php

namespace App\Console\Commands;

use App\Domain\Billing\Services\BillingPeriods;
use App\Domain\Platform\Models\Tenant;
use App\Support\Tenancy\TenantRunner;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * SaaS.7 completion (B-3): the billing run. Calculates the billing periods that are due for every subscription with
 * billing terms and drafts their invoices; it never issues, sends or collects anything (issue stays an operator
 * step, gated by a verified tax rule). Idempotent (one period per subscription, kind and start), safe to retry and
 * to run twice; one tenant's failure never stops the others. Suspended tenants are included: a technical suspension
 * does not stop commercial dates. Scheduled daily only when peopleos.billing.run_enabled is on.
 */
class RunBilling extends Command
{
    protected $signature = 'peopleos:billing:run {--tenant= : Tenant id or slug (all when omitted)} {--as-of= : Business date (YYYY-MM-DD), today when omitted}';

    protected $description = 'Calculate due billing periods and draft their invoices (drafts only; SaaS.7)';

    public function handle(BillingPeriods $periods): int
    {
        $asOf = $this->option('as-of') ? Carbon::createFromFormat('!Y-m-d', (string) $this->option('as-of'))->toDateString() : now()->toDateString();
        $runner = TenantRunner::for($this);
        Tenant::query()->when($this->option('tenant'), fn ($q, $t) => $q->where('slug', $t)->orWhere('id', $t))->orderBy('id')
            ->each($runner->isolate(function (Tenant $tenant) use ($periods, $asOf) {
                $summary = $periods->run($tenant, $asOf);
                if ($summary['created'] > 0) {
                    $this->info("{$tenant->slug}: {$summary['created']} period(s): {$summary['drafted']} drafted, {$summary['nothing_due']} nothing due, {$summary['exceptions']} exception(s)");
                }
            }, includeSuspended: true));

        return $runner->exitCode();
    }
}
