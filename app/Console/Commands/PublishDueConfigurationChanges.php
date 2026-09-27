<?php

namespace App\Console\Commands;

use App\Domain\Configuration\Services\ConfigurationChanges;
use App\Domain\Platform\Models\Tenant;
use App\Support\Tenancy\TenantContext;
use Illuminate\Console\Command;

class PublishDueConfigurationChanges extends Command
{
    protected $signature = 'peopleos:configuration:publish-due';

    protected $description = 'Publish approved configuration changes whose effective date has arrived (all tenants)';

    public function handle(ConfigurationChanges $changes, TenantContext $tenants): int
    {
        $total = 0;

        Tenant::query()->orderBy('id')->each(function (Tenant $tenant) use ($changes, $tenants, &$total) {
            $published = $tenants->runAs($tenant, fn () => $changes->publishDue());
            $total += $published;

            if ($published > 0) {
                $this->info("{$tenant->slug}: published {$published} change(s)");
            }
        });

        $this->info("Done. {$total} change(s) published.");

        return self::SUCCESS;
    }
}
