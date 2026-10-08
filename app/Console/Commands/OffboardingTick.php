<?php

namespace App\Console\Commands;

use App\Domain\Offboarding\Services\TenantDeletions;
use App\Domain\Offboarding\Services\TenantExports;
use Illuminate\Console\Command;

/**
 * SaaS.9: the offboarding sweep. Exports: a stopped worker is replaced (resume from the checkpoint) or the export fails
 * after the maximum attempts; ready packages past their expiry are deleted. Deletions (only when deletion is enabled):
 * approved deletions whose cooling-off ended are dispatched; a stalled run is marked failed for an operator to retry.
 * Platform-level: it iterates exports and deletions, each bound to its own tenant in its job.
 */
class OffboardingTick extends Command
{
    protected $signature = 'peopleos:offboarding:tick';

    protected $description = 'Resume or fail stalled exports, expire export packages, dispatch due tenant deletions';

    public function handle(TenantExports $exports, TenantDeletions $deletions): int
    {
        $e = $exports->sweep();
        $d = $deletions->dispatchDue();
        if (array_sum($e) + array_sum($d) > 0) {
            $this->info(sprintf('exports: %d resumed, %d failed, %d expired; deletions: %d dispatched, %d stalled', $e['resumed'], $e['failed'], $e['expired'], $d['dispatched'], $d['stalled']));
        }

        return self::SUCCESS;
    }
}
