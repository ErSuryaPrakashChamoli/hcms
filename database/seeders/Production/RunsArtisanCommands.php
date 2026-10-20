<?php

namespace Database\Seeders\Production;

use Illuminate\Contracts\Console\Kernel;
use RuntimeException;

/** Runs a deploy command from a seeder, showing its output when the seeder runs under `db:seed`. */
trait RunsArtisanCommands
{
    private function artisan(string $command): void
    {
        $exit = $this->command !== null ? $this->command->call($command) : app(Kernel::class)->call($command);

        if ($exit !== 0) {
            throw new RuntimeException("{$command} failed (exit code {$exit}); the production baseline is incomplete.");
        }
    }
}
