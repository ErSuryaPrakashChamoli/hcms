<?php

namespace App\Console\Commands;

use App\Domain\Enterprise\Services\Retention;
use App\Domain\Platform\Models\Tenant;
use App\Support\Tenancy\TenantContext;
use Illuminate\Console\Command;

class PurgeRetention extends Command
{
    protected $signature = 'peopleos:retention:purge {--tenant=}';

    protected $description = 'Purge operational logs beyond each tenant\'s retention window (audit events are never purged)';

    public function handle(Retention $retention, TenantContext $tenants): int
    {
        Tenant::query()->when($this->option('tenant'), fn ($q, $t) => $q->where('slug', $t)->orWhere('id', $t))->orderBy('id')
            ->each(fn (Tenant $tenant) => $tenants->runAs($tenant, fn () => $this->info("{$tenant->slug}: ".json_encode($retention->purge()))));

        return self::SUCCESS;
    }
}
