<?php

namespace App\Console\Commands;

use App\Domain\Offboarding\Services\DeletionReconciliation;
use App\Domain\Offboarding\Services\DeletionRegister;
use App\Domain\Offboarding\Services\TenantDeletions;
use App\Domain\Offboarding\Services\TenantExports;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * SaaS.9: the offboarding sweep. Exports: a stopped worker is replaced (resume from the checkpoint) or the export fails
 * after the maximum attempts; ready packages past their expiry are deleted. Deletions (only when deletion is enabled):
 * approved deletions whose cooling-off ended are dispatched; a stalled run is marked failed for an operator to retry.
 * Platform-level: it iterates exports and deletions, each bound to its own tenant in its job.
 * SaaS.12: it writes missing deletion register entries and contains any tenant a restore brought back past a deletion.
 */
class OffboardingTick extends Command
{
    protected $signature = 'peopleos:offboarding:tick';

    protected $description = 'Resume or fail stalled exports, expire export packages, dispatch due tenant deletions';

    public function handle(TenantExports $exports, TenantDeletions $deletions, DeletionRegister $register, DeletionReconciliation $reconciliation): int
    {
        $e = $exports->sweep();
        $d = $deletions->dispatchDue();
        if (array_sum($e) + array_sum($d) > 0) {
            $this->info(sprintf('exports: %d resumed, %d failed, %d expired; deletions: %d dispatched, %d stalled', $e['resumed'], $e['failed'], $e['expired'], $d['dispatched'], $d['stalled']));
        }
        // SaaS.12: every certified deletion has its register entry; a tenant a restore brought back is contained (never deleted).
        // SaaS.13: an unreachable register is reported (critical log, failed run), never skipped silently.
        try {
            $r = $register->syncMissing();
            $c = $reconciliation->contain(null);
        } catch (Throwable $e) {
            Log::critical('The deletion register cannot be read: restored-tenant containment did not run.', ['error' => $e::class]);
            $this->error('Deletion register unreadable ('.$e::class.'): restored-tenant containment did not run.');

            return self::FAILURE;
        }
        if ($r['written'] + $r['failed'] + $c['suspended'] + $c['recorded'] > 0) {
            $this->warn(sprintf('deletion register: %d written, %d failed; restored past a deletion: %d tenant(s) suspended, %d re-application(s) recorded', $r['written'], $r['failed'], $c['suspended'], $c['recorded']));
        }

        return self::SUCCESS;
    }
}
