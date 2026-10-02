<?php

namespace App\Console\Commands;

use App\Domain\Compensation\Jobs\EffectDueCompensation;
use App\Domain\Compensation\Services\CompensationChanges;
use App\Domain\Compensation\Services\CompensationStructures;
use App\Domain\Platform\Models\Tenant;
use App\Support\Tenancy\TenantContext;
use Illuminate\Console\Command;

/** Phase 11: make scheduled compensation changes effective on their date (each exactly once). */
class CompensationEffectCommand extends Command
{
    protected $signature = 'peopleos:compensation:effect {--tenant=} {--on= : Process as of this date (default today)} {--queue : Dispatch one tenant-bound job per tenant instead of running inline}';

    protected $description = 'Make scheduled compensation changes and structure versions effective when their date arrives';

    public function handle(CompensationChanges $changes, CompensationStructures $structures, TenantContext $tenants): int
    {
        Tenant::query()
            ->when($this->option('tenant'), fn ($q, $t) => $q->where('slug', $t)->orWhere('id', $t))
            ->orderBy('id')
            ->each(function (Tenant $tenant) use ($changes, $structures, $tenants) {
                $tenants->runAs($tenant, function () use ($changes, $structures, $tenant) {
                    if ($this->option('queue')) {
                        EffectDueCompensation::dispatch($this->option('on'));
                        $this->info("{$tenant->slug}: queued");

                        return;
                    }
                    $versions = $structures->promoteDue($this->option('on'));
                    $this->info("{$tenant->slug}: ".$changes->effectDue($this->option('on'))." change(s) made effective, {$versions} structure version(s) now active");
                });
            });

        return self::SUCCESS;
    }
}
