<?php

namespace App\Console\Commands;

use App\Domain\Signup\Services\SignupMaintenance;
use Illuminate\Console\Command;

/**
 * SaaS.10: the self-service signup tick. Every minute on one server: expire, recover interrupted set-ups, provision
 * what is due, retry refused owner invitations, mark activated applications. Runs whether or not public registration
 * is open, so applications already submitted still finish (or expire) after the switch is turned off.
 */
class SignupTick extends Command
{
    protected $signature = 'peopleos:signups:tick';

    protected $description = 'Advance self-service signup applications: expiry, provisioning, retries, activation';

    public function handle(SignupMaintenance $maintenance): int
    {
        $result = $maintenance->run();
        $this->line(collect($result)->map(fn (int $count, string $step) => "{$step}: {$count}")->implode(', '));

        return self::SUCCESS;
    }
}
