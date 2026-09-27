<?php

namespace App\Console\Commands;

use App\Domain\Enterprise\Services\WarehouseExport;
use App\Domain\Platform\Models\Tenant;
use App\Support\Tenancy\TenantContext;
use Illuminate\Console\Command;

class ExportWarehouse extends Command
{
    protected $signature = 'peopleos:warehouse:export {--tenant=} {--dataset=*}';

    protected $description = 'Export every dataset as JSON Lines to the warehouse disk';

    public function handle(WarehouseExport $export, TenantContext $tenants): int
    {
        $only = $this->option('dataset') ?: null;
        Tenant::query()->when($this->option('tenant'), fn ($q, $t) => $q->where('slug', $t)->orWhere('id', $t))->orderBy('id')
            ->each(fn (Tenant $tenant) => $tenants->runAs($tenant, fn () => $this->info("{$tenant->slug}: ".json_encode($export->run(null, $only)))));

        return self::SUCCESS;
    }
}
