<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The platform's region list per country (states, provinces, emirates…; no tenant_id), pre-filled with the top-level
 * ISO 3166-2 subdivisions. Holiday library uploads may only name these regions; tenant locations are matched to them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('country_regions', function (Blueprint $table) {
            $table->id();
            $table->char('country_code', 2);
            // Without the country prefix: MH, not IN-MH.
            $table->string('code', 16);
            $table->string('name');
            $table->string('status', 16)->default('active');
            // iso_3166_2 (pre-filled) or platform (added by an operator).
            $table->string('source', 16)->default('platform');
            $table->timestamps();

            $table->unique(['country_code', 'code'], 'country_regions_code');
        });

        $now = now();
        $rows = [];
        foreach (require database_path('data/countries/regions.php') as $country => $regions) {
            foreach ($regions as $code => $name) {
                $rows[] = ['country_code' => $country, 'code' => (string) $code, 'name' => $name, 'status' => 'active', 'source' => 'iso_3166_2', 'created_at' => $now, 'updated_at' => $now];
            }
        }
        foreach (array_chunk($rows, 500) as $chunk) {
            DB::table('country_regions')->insert($chunk);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('country_regions');
    }
};
