<?php

namespace App\Console\Commands;

use App\Domain\Enterprise\Services\Webhooks;
use App\Domain\Platform\Models\Tenant;
use App\Support\Tenancy\TenantContext;
use Illuminate\Console\Command;

class DeliverWebhooks extends Command
{
    protected $signature = 'peopleos:webhooks:deliver {--tenant=}';

    protected $description = 'Attempt pending webhook deliveries (with retries and backoff)';

    public function handle(Webhooks $webhooks, TenantContext $tenants): int
    {
        Tenant::query()->when($this->option('tenant'), fn ($q, $t) => $q->where('slug', $t)->orWhere('id', $t))->orderBy('id')
            ->each(fn (Tenant $tenant) => $tenants->runAs($tenant, function () use ($webhooks, $tenant) {
                $r = $webhooks->deliverDue();
                if (array_sum($r) > 0) {
                    $this->info("{$tenant->slug}: {$r['delivered']} delivered, {$r['retrying']} retrying, {$r['failed']} failed");
                }
            }));

        return self::SUCCESS;
    }
}
