<?php

namespace App\Console\Commands;

use App\Domain\Identity\Models\User;
use App\Domain\Identity\Services\MultiFactor;
use Illuminate\Console\Command;
use RuntimeException;

/**
 * SaaS.2: the last-resort recovery for someone who has lost both their authenticator and their recovery
 * codes and has nobody in the panel who can reset it (in practice, a platform operator). Run by a person
 * with server access; the reset is audited (source console, no actor) and ends the person's sessions.
 */
class ResetMultiFactor extends Command
{
    protected $signature = 'peopleos:mfa:reset {email} {--reason= : Why the authenticator is being removed (required)}';

    protected $description = 'Remove a person\'s authenticator and recovery codes after identity has been confirmed out of band (audited)';

    public function handle(MultiFactor $mfa): int
    {
        $reason = trim((string) $this->option('reason'));
        $user = User::query()->where('email', strtolower(trim((string) $this->argument('email'))))->first();

        if ($user === null || ! $user->hasMfaEnabled()) {
            $this->error('No account with an authenticator uses that address.');

            return self::FAILURE;
        }
        try {
            $mfa->reset($user, $reason, null);
        } catch (RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
        $this->info('Authenticator removed and sessions ended. The person sets up a new one at their next sign-in if it is required.');

        return self::SUCCESS;
    }
}
