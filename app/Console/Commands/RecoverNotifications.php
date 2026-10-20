<?php

namespace App\Console\Commands;

use App\Domain\Notifications\Services\NotificationRecovery;
use App\Domain\Platform\Models\Tenant;
use App\Support\Tenancy\TenantContext;
use App\Support\Tenancy\TenantRunner;
use Illuminate\Console\Command;

/** SaaS.8: deliveries stuck in `sending` (worker lost) or left `queued` (job lost), per tenant; suspended tenants wait. */
class RecoverNotifications extends Command
{
    protected $signature = 'peopleos:notifications:recover {--tenant=}';

    protected $description = 'Mark deliveries stuck in sending as failed (outcome unknown) and dispatch again deliveries whose job was lost';

    public function handle(NotificationRecovery $recovery, TenantContext $tenants): int
    {
        $runner = TenantRunner::for($this);
        Tenant::query()->when($this->option('tenant'), fn ($q, $t) => $q->where('slug', $t)->orWhere('id', $t))->orderBy('id')
            ->each($runner->isolate(fn (Tenant $tenant) => $tenants->runAs($tenant, function () use ($recovery, $tenant) {
                $r = $recovery->recover();
                if (array_sum($r) > 0) {
                    $this->info("{$tenant->slug}: {$r['stale_sending']} stuck in sending marked failed, {$r['requeued']} dispatched again");
                }
            })));

        return $runner->exitCode();
    }
}
