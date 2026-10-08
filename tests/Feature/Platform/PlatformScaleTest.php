<?php

use App\Domain\Employment\Actions\HireEmployeeAction;
use App\Domain\Integration\Services\ApiKeys;
use App\Domain\Organisation\Models\Company;
use App\Domain\Organisation\Models\Location;
use Illuminate\Support\Facades\Storage;

require_once __DIR__.'/PlatformScaleHelpers.php';

/*
 | Phase 14 §14 scale (SQLite, query counts): the Phase 14 read surfaces cost the same number of
 | queries at 15, 150 and 1,500 employees. Wall time is printed for the report only; SQLite timings say
 | nothing about MySQL production latency (tests/MySql/PlatformScaleTest.php runs 10,000 on MySQL).
 */

it('keeps Employee 360, people analytics, change intelligence, readiness and the employee API constant in queries from 15 to 1,500 employees', function () {
    Storage::fake('local');
    $tenant = provisionTenant();
    actAsTenant($tenant);
    $hr = tenantUser($tenant, ['*']);
    $this->actingAs($hr);
    $company = Company::factory()->create(['code' => 'SCALE']);
    $locations = Location::factory()->count(3)->create(['company_id' => $company->id])->pluck('id')->all();
    $subject = app(HireEmployeeAction::class)->handle(['first_name' => 'Subject', 'last_name' => 'One'], ['joining_date' => '2025-01-01'], ['company_id' => $company->id, 'location_id' => $locations[0]]);
    $key = app(ApiKeys::class)->issue('Scale', ['employees.read'])['plaintext'];
    $http = function (string $url, string $apiKey) use ($tenant, $hr) {
        auth()->logout();
        actAsTenant(null);
        $this->withHeader('X-Api-Key', $apiKey)->getJson($url)->assertOk();
        $this->flushHeaders();
        actAsTenant($tenant);
        $this->actingAs($hr);
    };

    $results = [];
    foreach ([15, 150, 1500] as $tier) {
        platformSeedPopulation($tier, $company, $locations);
        platformMeasureSurfaces($hr, $subject, $key, $http); // warm caches
        $results[$tier] = platformMeasureSurfaces($hr, $subject, $key, $http);
    }
    fwrite(STDERR, 'platform scale (SQLite) '.json_encode($results).PHP_EOL);

    foreach (array_keys($results[15]) as $surface) {
        expect($results[150][$surface]['queries'])->toBe($results[15][$surface]['queries'], "{$surface} at 150")
            ->and($results[1500][$surface]['queries'])->toBe($results[15][$surface]['queries'], "{$surface} at 1500");
    }
})->group('scale');
