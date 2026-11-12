<?php

namespace Database\Seeders;

use Database\Seeders\Demo\DemoNotificationsSeeder;
use Illuminate\Database\Seeder;
use RuntimeException;

/**
 * UX.17: realistic in-app notifications for the showcase personas, so the notification surfaces have something true to
 * show. Each one describes a record that exists in the showcase (a pending leave request, a missed-punch correction,
 * an HR request, an expiring passport, a proposed configuration change, an announcement) and goes through the real
 * delivery path (Notifier, in-app channel) with the event names the platform emits. Recipients are the people the
 * record concerns. Deep links stay the notification center's own, checked by each record's policy.
 *
 * Disposable *_showcase databases only (or tests); idempotent (one correlation id per run). Every person is fictional.
 */
class UxShowcaseNotificationsSeeder extends Seeder
{
    public const CORRELATION = DemoNotificationsSeeder::CORRELATION;

    public function run(): void
    {
        $database = (string) config('database.connections.'.config('database.default').'.database');
        // SaaS.12: production is refused outright, whatever the database is called (demo personas share one password).
        if (app()->isProduction() || (! app()->environment('testing') && ! str_ends_with($database, '_showcase'))) {
            throw new RuntimeException("UxShowcaseNotificationsSeeder only runs on a disposable *_showcase database (current: {$database}).");
        }

        $this->call(DemoNotificationsSeeder::class);
    }
}
