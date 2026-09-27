<?php

namespace App\Console\Commands;

use App\Domain\Identity\Services\PermissionRegistry;
use App\Domain\Platform\Actions\ProvisionTenantAction;
use App\Domain\Platform\Models\Tenant;
use App\Support\Tenancy\TenantContext;
use Illuminate\Console\Command;

class SyncPermissions extends Command
{
    protected $signature = 'peopleos:sync-permissions {--no-roles : Only sync the catalogue, do not top up system roles}';

    protected $description = 'Sync the permission catalogue from config and top up every tenant\'s system roles';

    public function handle(PermissionRegistry $registry, ProvisionTenantAction $provisioner, TenantContext $tenants): int
    {
        $result = $registry->sync();
        $this->info(sprintf('Permissions synced: %d created, %d pruned.', $result['created'], $result['pruned']));

        if ($this->option('no-roles')) {
            return self::SUCCESS;
        }

        $count = 0;

        Tenant::query()->orderBy('id')->each(function (Tenant $tenant) use ($provisioner, $tenants, &$count) {
            $tenants->runAs($tenant, fn () => $provisioner->seedSystemRoles());
            $count++;
        });

        $this->info("System roles topped up for {$count} tenant(s).");

        return self::SUCCESS;
    }
}
