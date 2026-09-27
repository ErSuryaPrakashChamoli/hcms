<?php

namespace App\Console\Commands;

use App\Domain\Compliance\Services\ComplianceRules;
use Illuminate\Console\Command;

class SyncComplianceRules extends Command
{
    protected $signature = 'peopleos:compliance:sync';

    protected $description = 'Load the platform statutory rule packs (database/data/compliance) into compliance_rules';

    public function handle(ComplianceRules $rules): int
    {
        $synced = $rules->sync();
        $this->info("Synced {$synced->count()} statutory rule version(s).");

        return self::SUCCESS;
    }
}
