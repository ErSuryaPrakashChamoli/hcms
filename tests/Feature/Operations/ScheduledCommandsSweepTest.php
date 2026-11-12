<?php

use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

require_once __DIR__.'/../Admin/PopulatedSweepHelpers.php';

/*
| QA sweep: every command the scheduler runs (routes/console.php) runs to completion over a populated organisation —
| twice, as a second run on the same day must find nothing left to do rather than fail on what the first one did.
| Commands are called in-process (never through the event's shell command, which would leave the test database).
| Outbound HTTP, mail, notifications and storage are faked.
*/

/** @return array<string, string> the artisan command line of every scheduled command, keyed by its description */
function scheduledArtisanCommands(): array
{
    return collect(app(Schedule::class)->events())
        ->filter(fn (Event $event) => is_string($event->command) && str_contains($event->command, 'artisan'))
        ->mapWithKeys(fn (Event $event) => [trim(Str::after($event->command, "'artisan'")) => trim(Str::after($event->command, "'artisan'"))])
        ->all();
}

it('runs every scheduled command over a populated organisation, twice, without a failure', function () {
    $this->travelTo('2026-11-11 07:00:00');
    seedPopulatedOrganisation();
    Http::fake();
    Mail::fake();
    Notification::fake();
    foreach (array_keys(config('filesystems.disks')) as $disk) {
        Storage::fake($disk);
    }
    // The commands run as the scheduler does: no signed-in user and no tenant bound.
    app('auth')->forgetGuards();
    actAsTenant(null);

    $commands = scheduledArtisanCommands();
    $failures = [];
    foreach ([1, 2] as $round) {
        foreach ($commands as $command) {
            try {
                $exit = Artisan::call($command);
                if ($exit !== 0) {
                    $failures["{$command} (run {$round})"] = "exit {$exit}: ".Str::limit(trim(Artisan::output()), 300);
                }
            } catch (Throwable $e) {
                $failures["{$command} (run {$round})"] = $e::class.': '.Str::limit($e->getMessage(), 300);
            }
        }
    }

    expect(count($commands))->toBeGreaterThan(45)
        ->and($failures)->toBe([]);
});
