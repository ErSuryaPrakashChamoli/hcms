<?php

namespace App\Console\Commands;

use App\Domain\Documents\Services\DocumentDefaults;
use App\Domain\Platform\Models\Tenant;
use App\Support\Tenancy\TenantContext;
use App\Support\Tenancy\TenantRunner;
use Illuminate\Console\Command;

/**
 * Adds the default document types an organisation does not have yet (DocumentDefaults; 2026-11-27: 10th and 12th
 * marksheets, graduation and post-graduation papers, government IDs and the rest). Never changes a type it already has.
 * Idempotent.
 */
class SeedDocumentTypes extends Command
{
    protected $signature = 'peopleos:documents:seed-types {--tenant= : One organisation, by slug or id}';

    protected $description = 'Add the default document types an organisation does not have yet';

    public function handle(DocumentDefaults $defaults, TenantContext $tenants): int
    {
        $runner = TenantRunner::for($this);

        Tenant::query()
            ->when($this->option('tenant'), fn ($q, $t) => $q->where('slug', $t)->orWhere('id', $t))
            ->orderBy('id')
            ->each($runner->isolate(fn (Tenant $tenant) => $tenants->runAs($tenant, function () use ($defaults, $tenant) {
                $this->info("{$tenant->slug}: ".$defaults->seed().' document type(s) added');
            })));

        return $runner->exitCode();
    }
}
