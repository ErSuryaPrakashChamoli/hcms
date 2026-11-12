<?php

namespace Database\Seeders;

use Database\Seeders\Demo\DemoPeopleSeeder;
use Illuminate\Database\Seeder;
use RuntimeException;

/**
 * Experience Transformation: a richer, fictional demo population for UX review and the visual
 * regression references (docs/ux/visual-regression). It adds people across departments, one sign-in per
 * experience lens (employee, manager, HR, HR admin, payroll, executive) and a few open requests so the
 * Home, My work, Approvals, People and Org map screens have something to show.
 *
 * It refuses to run against anything but a disposable database whose name ends in "_showcase" (or the
 * test database), so it can never add fictional people to a real tenant. Every person is invented.
 */
class UxShowcaseSeeder extends Seeder
{
    public function run(): void
    {
        $database = (string) config('database.connections.'.config('database.default').'.database');
        // SaaS.12: production is refused outright, whatever the database is called (demo personas share one password).
        if (app()->isProduction() || (! app()->environment('testing') && ! str_ends_with($database, '_showcase'))) {
            throw new RuntimeException("UxShowcaseSeeder only runs on a disposable *_showcase database (current: {$database}).");
        }

        $this->call(DatabaseSeeder::class);
        // A fixed, test-only fixture password: the visual and browser suites sign the personas in with it
        // (SHOWCASE_PASSWORD), and this seeder refuses any database not named *_showcase (above).
        $this->call(DemoPeopleSeeder::class, false, ['password' => 'password']);
        // UX.17: realistic in-app notifications for the personas, through the real delivery path.
        $this->call(UxShowcaseNotificationsSeeder::class);
    }
}
