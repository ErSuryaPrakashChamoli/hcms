<?php

namespace App\Console\Commands;

use App\Domain\Configuration\Services\Blueprints;
use App\Domain\Platform\Models\Tenant;
use App\Support\Tenancy\TenantContext;
use Illuminate\Console\Command;

class ExportBlueprint extends Command
{
    protected $signature = 'peopleos:blueprint:export {tenant : Tenant id or slug} {path : Where to write the JSON}';

    protected $description = 'Export a tenant\'s configuration (never employee data) as a blueprint';

    public function handle(Blueprints $blueprints, TenantContext $tenants): int
    {
        $tenant = Tenant::query()->where('id', $this->argument('tenant'))->orWhere('slug', $this->argument('tenant'))->firstOrFail();
        $data = $tenants->runAs($tenant, fn () => $blueprints->export());

        file_put_contents($this->argument('path'), json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        $this->info("Blueprint for {$tenant->slug} written to {$this->argument('path')}.");

        return self::SUCCESS;
    }
}
