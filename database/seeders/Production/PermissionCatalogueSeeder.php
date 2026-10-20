<?php

namespace Database\Seeders\Production;

use Illuminate\Database\Seeder;

/**
 * The permission catalogue from config/peopleos.php, and every tenant's system roles topped up. The same code path as
 * the deploy step `php artisan peopleos:sync-permissions`: additive, audited per tenant, and a permission a tenant
 * removed from a system role stays removed. Custom roles, role assignments and passwords are never touched.
 */
class PermissionCatalogueSeeder extends Seeder
{
    use RunsArtisanCommands;

    public function run(): void
    {
        $this->artisan('peopleos:sync-permissions');
    }
}
