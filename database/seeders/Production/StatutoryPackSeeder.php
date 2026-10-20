<?php

namespace Database\Seeders\Production;

use Illuminate\Database\Seeder;

/**
 * The platform statutory packs (database/data/compliance): rule versions, regulatory notices and export layouts. The
 * same code path as `php artisan peopleos:compliance:sync` (also scheduled weekly): insert-only, a changed version is
 * refused, and a pack never marks a rule VERIFIED. Verification stays two platform administrators' act.
 */
class StatutoryPackSeeder extends Seeder
{
    use RunsArtisanCommands;

    public function run(): void
    {
        $this->artisan('peopleos:compliance:sync');
    }
}
