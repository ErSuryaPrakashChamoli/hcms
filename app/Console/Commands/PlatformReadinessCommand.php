<?php

namespace App\Console\Commands;

use App\Support\Observability\PlatformReadiness;
use Illuminate\Console\Command;

/**
 * Phase 14: the production-readiness report (configuration, runtime health, audit chains, statutory
 * gate, operator evidence). Exits non-zero on any FAIL. It never declares production readiness: that
 * also needs the statutory gate open, a verified restore / DR exercise, and operator sign-off.
 */
class PlatformReadinessCommand extends Command
{
    protected $signature = 'peopleos:readiness {--json : Print the report as JSON} {--skip-audit : Skip the audit-chain verification (large databases)}';

    protected $description = 'Production readiness report: configuration, health, audit chains, statutory gate and operator evidence (never declares readiness on its own)';

    public function handle(PlatformReadiness $readiness): int
    {
        $report = $readiness->report(! $this->option('skip-audit'));
        if ($this->option('json')) {
            $this->line(json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        } else {
            $this->table(['Area', 'Check', 'Status', 'Detail'], array_map(fn ($c) => [$c['area'], $c['check'], $c['status'], $c['detail']], $report['checks']));
            $s = $report['summary'];
            $this->line('Configuration: '.$s['configuration'].'; warnings: '.$s['warnings']);
            $this->line(sprintf('Statutory production gate: %s (%d rules / %d verified / %d open notices)', $s['statutory']['blocked'] ? 'BLOCKED' : 'OPEN', $s['statutory']['rules'], $s['statutory']['verified'], $s['statutory']['open_notices']));
            $this->warn('Production readiness: '.$s['verdict']);
        }

        return $report['summary']['fails'] === 0 ? self::SUCCESS : self::FAILURE;
    }
}
