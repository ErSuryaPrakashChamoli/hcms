<?php

namespace Database\Seeders;

use App\Domain\Identity\Models\User;
use Database\Seeders\Demo\DemoOrganisationSeeder;
use Database\Seeders\Demo\DemoTenantSeeder;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class DatabaseSeeder extends Seeder
{
    /**
     * The production baseline (ProductionSeeder) first, in every environment. In production that is all it seeds:
     * `php artisan db:seed --force` is the deploy step, and demo data never reaches production by it. A demo
     * organisation on a server is a separate, deliberate step: `--class=Database\Seeders\Demo\DemoOrganisationSeeder`.
     *
     * SaaS.2: the rest is development and demo data only, and it contains no password: every seeded account
     * uses PEOPLEOS_SEED_PASSWORD, or a random password generated for this run and printed once to the console.
     */
    public function run(): void
    {
        $this->call(ProductionSeeder::class);

        if (app()->environment('production')) {
            $this->command?->info('Production: demo data is never seeded by db:seed. For a demo organisation run: php artisan db:seed --class="'.DemoOrganisationSeeder::class.'" --force');

            return;
        }
        $password = $this->seedPassword();

        User::query()->firstOrCreate(
            ['email' => 'platform@markedge.local'],
            [
                'name' => 'Markedge Platform Admin',
                'password' => $password,
                'is_platform_admin' => true,
                'email_verified_at' => now(),
            ],
        );

        $this->call(DemoTenantSeeder::class, false, ['password' => $password]);
    }

    /** The password every seeded account gets this run (never a literal in the repository). */
    private function seedPassword(): string
    {
        $configured = (string) config('peopleos.seed.password', '');
        if ($configured !== '') {
            return $configured;
        }
        $generated = Str::password(20);
        $this->command?->warn("Seeded accounts use this generated password (shown once; set PEOPLEOS_SEED_PASSWORD to choose one): {$generated}");

        return $generated;
    }
}
