<?php

namespace App\Console\Commands;

use App\Domain\Offboarding\Services\DeletionReconciliation;
use Illuminate\Console\Command;

/**
 * SaaS.12: run after every restore, before the application serves traffic and before workers and the scheduler start.
 * Compares the deletion register (outside the database) with the restored database. `--contain` suspends each tenant
 * the restore brought back past a certified deletion and records that the deletion must be re-applied; nothing is
 * deleted. Exits non-zero while anything needs a person.
 */
class ReconcileDeletions extends Command
{
    protected $signature = 'peopleos:deletions:reconcile
        {--contain : Suspend tenants restored past a certified deletion and record the re-application (never deletes)}
        {--json : Machine-readable output}';

    protected $description = 'Compare the deletion register with the database (after a restore): restored past a deletion, doubtful entries, unregistered deletions';

    public function handle(DeletionReconciliation $reconciliation): int
    {
        $contained = $this->option('contain') ? $reconciliation->contain(null) : null;
        $report = $reconciliation->check();
        $clean = DeletionReconciliation::clean($report['summary']);

        if ($this->option('json')) {
            $this->line(json_encode($report + ['contained' => $contained, 'clean' => $clean], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return $clean ? self::SUCCESS : self::FAILURE;
        }

        if ($report['entries'] !== []) {
            $this->table(['Deletion', 'Tenant', 'Outcome', 'Detail'], array_map(fn (array $e) => [$e['reference'], isset($e['tenant_id']) ? "{$e['tenant_id']} ({$e['tenant_slug']})" : '-',
                strtoupper($e['outcome']).($e['outcome'] === DeletionReconciliation::RESURRECTED ? ($e['operating'] ? ' (OPERATING)' : ' (contained)') : ''), $e['detail']], $report['entries']));
        }
        foreach ($report['unregistered'] as $reference) {
            $this->warn("Completed deletion {$reference} has no register entry (the offboarding tick writes it).");
        }
        if ($contained !== null) {
            $this->info(sprintf('Contained: %d tenant(s) suspended, %d re-application(s) recorded.', $contained['suspended'], $contained['recorded']));
        }
        $s = $report['summary'];
        $this->line(sprintf('%d entries: %d consistent, %d re-applied, %d not present, %d restored past a deletion (%d operating), %d identity mismatch, %d invalid; %d unregistered.',
            count($report['entries']), $s['consistent'], $s['reapplied'], $s['not_present'], $s['resurrected'], $s['resurrected_operating'], $s['identity_mismatch'], $s['invalid'], $s['unregistered']));
        $clean ? $this->info('Nothing to reconcile.') : $this->error('Reconciliation needed: see docs/operations/runbooks/recovery.md (restore from backup).');

        return $clean ? self::SUCCESS : self::FAILURE;
    }
}
