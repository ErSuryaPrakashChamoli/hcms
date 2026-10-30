<?php

namespace App\Console\Commands;

use App\Domain\Assets\Services\Assets;
use App\Domain\Platform\Models\Tenant;
use App\Support\Tenancy\TenantContext;
use App\Support\Tenancy\TenantRunner;
use Illuminate\Console\Command;

/**
 * HROne setup parity AS-A: remind employees to acknowledge assets whose sub type needs an acknowledgement, once every
 * `asset.acknowledgement.reminder_days` days per assignment (stamped on the assignment, so a second run sends nothing).
 */
class RemindAssetAcknowledgements extends Command
{
    protected $signature = 'peopleos:assets:acknowledgement-reminders {--tenant=}';

    protected $description = 'Remind employees to acknowledge assets that need an acknowledgement';

    public function handle(TenantContext $tenants, Assets $assets): int
    {
        $runner = TenantRunner::for($this);

        Tenant::query()
            ->when($this->option('tenant'), fn ($q, $t) => $q->where('slug', $t)->orWhere('id', $t))
            ->orderBy('id')
            ->each($runner->isolate(function (Tenant $tenant) use ($tenants, $assets) {
                $tenants->runAs($tenant, function () use ($tenant, $assets) {
                    $this->info("{$tenant->slug}: ".$assets->remindAcknowledgements().' acknowledgement reminder(s)');
                });
            }));

        return $runner->exitCode();
    }
}
