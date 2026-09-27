<?php

namespace App\Console\Commands;

use App\Domain\Configuration\Services\Blueprints;
use App\Domain\Platform\Models\Tenant;
use App\Support\Tenancy\TenantContext;
use Illuminate\Console\Command;

class ImportBlueprint extends Command
{
    protected $signature = 'peopleos:blueprint:import {tenant : Tenant id or slug} {path : Blueprint JSON, or a pack name with --pack}
                            {--pack : Treat path as the name of a bundled configuration pack}';

    protected $description = 'Import a configuration blueprint or apply a bundled pack to a tenant';

    public function handle(Blueprints $blueprints, TenantContext $tenants): int
    {
        $tenant = Tenant::query()->where('id', $this->argument('tenant'))->orWhere('slug', $this->argument('tenant'))->firstOrFail();

        $counts = $tenants->runAs($tenant, function () use ($blueprints) {
            if ($this->option('pack')) {
                return $blueprints->applyPack($this->argument('path'), 'Applied pack via console');
            }

            $data = json_decode((string) file_get_contents($this->argument('path')), true, 512, JSON_THROW_ON_ERROR);

            return $blueprints->import($data, 'Blueprint import via console');
        });

        foreach ($counts as $section => $count) {
            $this->line(sprintf('  %-20s %d', $section, $count));
        }

        return self::SUCCESS;
    }
}
