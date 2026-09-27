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

        $byStatus = $synced->countBy('verification_status');
        $this->line('By status: '.$byStatus->map(fn ($n, $s) => strtoupper($s).' '.$n)->implode(', '));

        $unverified = $synced->filter(fn ($rule) => ! $rule->isVerified())->count();
        if ($unverified > 0) {
            $this->warn("{$unverified} rule version(s) are not VERIFIED against an official source; payroll using them cannot be finalized while enforcement is on.");
        }

        return self::SUCCESS;
    }
}
