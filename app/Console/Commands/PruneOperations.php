<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

/**
 * SaaS.8: failed jobs and job batches were never pruned (no queue:prune-* was scheduled). Daily, both are pruned after
 * peopleos.health.failed_jobs_retention_hours (default 30 days). Platform-level: the queue tables have no tenant.
 */
class PruneOperations extends Command
{
    protected $signature = 'peopleos:operations:prune';

    protected $description = 'Prune failed jobs and job batches older than the configured operational window';

    public function handle(): int
    {
        $hours = (int) config('peopleos.health.failed_jobs_retention_hours', 720);
        $failed = $this->call('queue:prune-failed', ['--hours' => $hours]);
        $batches = $this->call('queue:prune-batches', ['--hours' => $hours, '--unfinished' => $hours, '--cancelled' => $hours]);

        return $failed === self::SUCCESS && $batches === self::SUCCESS ? self::SUCCESS : self::FAILURE;
    }
}
