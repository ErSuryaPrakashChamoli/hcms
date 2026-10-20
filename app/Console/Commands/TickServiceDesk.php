<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

/** Pre-Phase 12 entry point, kept for existing cron entries: runs `peopleos:service-desk:process`. */
class TickServiceDesk extends Command
{
    protected $signature = 'peopleos:servicedesk:tick {--tenant=}';

    protected $description = 'Deprecated alias of peopleos:service-desk:process';

    public function handle(): int
    {
        return $this->call('peopleos:service-desk:process', array_filter(['--tenant' => $this->option('tenant')]));
    }
}
