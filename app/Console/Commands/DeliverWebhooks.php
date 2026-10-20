<?php

namespace App\Console\Commands;

use App\Domain\Enterprise\Services\Webhooks;
use App\Domain\Platform\Models\Tenant;
use App\Support\Tenancy\TenantContext;
use App\Support\Tenancy\TenantRunner;
use Illuminate\Console\Command;

class DeliverWebhooks extends Command
{
    protected $signature = 'peopleos:webhooks:deliver {--tenant=}';

    protected $description = 'Attempt pending webhook deliveries (with retries and backoff)';

    public function handle(Webhooks $webhooks, TenantContext $tenants): int
    {
        $runner = TenantRunner::for($this);
        Tenant::query()->when($this->option('tenant'), fn ($q, $t) => $q->where('slug', $t)->orWhere('id', $t))->orderBy('id')
            ->each($runner->isolate(fn (Tenant $tenant) => $tenants->runAs($tenant, function () use ($webhooks, $tenant) {
                $r = $webhooks->deliverDue();
                if (array_sum($r) > 0) {
                    $this->info("{$tenant->slug}: {$r['delivered']} delivered, {$r['retrying']} retrying, {$r['dead_letter']} dead-lettered");
                }
            })));

        return $runner->exitCode();
    }
}
