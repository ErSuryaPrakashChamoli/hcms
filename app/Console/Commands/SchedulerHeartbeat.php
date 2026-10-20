<?php

namespace App\Console\Commands;

use App\Support\Observability\HealthChecks;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

/** Phase 14: proves the scheduler is running; /health/ready warns when the beat is older than peopleos.health.heartbeat_max_age. */
class SchedulerHeartbeat extends Command
{
    protected $signature = 'peopleos:scheduler:heartbeat';

    protected $description = 'Record the scheduler heartbeat (read by /health/ready and peopleos:readiness)';

    public function handle(): int
    {
        Cache::forever(HealthChecks::HEARTBEAT_KEY, now()->getTimestamp());

        return self::SUCCESS;
    }
}
