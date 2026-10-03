<?php

use App\Domain\Employment\Actions\HireEmployeeAction;
use App\Domain\Integration\Services\ApiKeys;
use App\Domain\Organisation\Models\Company;
use App\Domain\Organisation\Models\Location;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

require_once __DIR__.'/../Feature/Platform/PlatformScaleHelpers.php';

/*
 | Phase 14 §14 scale on MySQL (opt-in, same disposable database as the concurrency suites): the Phase
 | 14 read surfaces at 15 / 150 / 1,500 / 10,000 employees. Query counts must stay constant; wall times
 | are recorded for the report (local hardware, not a production latency claim).
 */

beforeEach(function () {
    $database = (string) env('PEOPLEOS_MYSQL_CONCURRENCY_DB', '');
    if ($database === '' || ! str_contains($database, 'concurrency')) {
        $this->markTestSkipped('Set PEOPLEOS_MYSQL_CONCURRENCY_DB to a disposable MySQL database (name containing "concurrency").');
    }
    config(['database.connections.concurrency' => array_merge(config('database.connections.mysql'), ['database' => $database]), 'database.default' => 'concurrency', 'queue.default' => 'sync']);
    DB::purge('concurrency');
    if (! ($GLOBALS['peopleos_concurrency_migrated'] ?? false)) {
        Artisan::call('migrate:fresh', ['--database' => 'concurrency', '--force' => true]);
        $GLOBALS['peopleos_concurrency_migrated'] = true;
    }
});

it('keeps the Phase 14 read surfaces constant in queries up to 10,000 employees on MySQL', function () {
    Storage::fake('local');
    $tenant = provisionTenant('Scale '.uniqid());
    actAsTenant($tenant);
    $hr = tenantUser($tenant, ['*']);
    $this->actingAs($hr);
    $company = Company::factory()->create(['code' => 'S'.random_int(1000, 9999)]);
    $locations = Location::factory()->count(5)->create(['company_id' => $company->id])->pluck('id')->all();
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
    foreach ([15, 150, 1500, 10000] as $tier) {
        platformSeedPopulation($tier, $company, $locations);
        platformMeasureSurfaces($hr, $subject, $key, $http);
        $results[$tier] = platformMeasureSurfaces($hr, $subject, $key, $http);
    }
    fwrite(STDERR, 'platform scale (MySQL) '.json_encode($results).PHP_EOL);

    foreach (array_keys($results[15]) as $surface) {
        expect($results[10000][$surface]['queries'])->toBe($results[15][$surface]['queries'], "{$surface} at 10,000");
    }
})->group('scale');
