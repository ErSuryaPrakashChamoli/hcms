<?php

use App\Domain\Employment\Actions\HireEmployeeAction;
use App\Domain\Integration\Services\ApiKeys;
use App\Domain\Organisation\Models\Company;
use App\Domain\Organisation\Models\Designation;
use App\Support\Api\OpenApiGenerator;

/*
| The five API lookups that need query parameters (OpenApiGenerator::QUERY, docs/api/openapi.json): leave calendar (a
| date window), career gaps and movements, a person's surveys and communication preferences (an employee code).
| - Without a required parameter: 422 validation_failed naming it, never a bare 404 or a 500.
| - With an unknown code, or another tenant's employee: 404 not_found.
| - With their parameters: 200 in the documented shape.
| - No key: 401; a key without the scope: 403.
*/

beforeEach(function () {
    $this->travelTo('2026-11-25 10:00:00');
    $this->tenant = provisionTenant();
    actAsTenant($this->tenant);
    $company = Company::factory()->create(['code' => 'ALPHA']);
    $this->designation = Designation::query()->create(['name' => 'Analyst', 'code' => 'ANL']);
    $this->employee = app(HireEmployeeAction::class)->handle(['first_name' => 'Asha', 'last_name' => 'Rao'], ['joining_date' => '2025-01-01', 'employee_code' => 'A-1'], ['company_id' => $company->id, 'designation_id' => $this->designation->id]);
    $this->key = app(ApiKeys::class)->issue('Integration', ['leave.read', 'career.read', 'engagement.read', 'communications.read'])['plaintext'];
    $this->narrow = app(ApiKeys::class)->issue('Leave only', ['leave.read'])['plaintext'];

    $other = provisionTenant('Other');
    actAsTenant($other);
    app(HireEmployeeAction::class)->handle(['first_name' => 'Bina', 'last_name' => 'Das'], ['joining_date' => '2025-01-01', 'employee_code' => 'B-1'], ['company_id' => Company::factory()->create(['code' => 'BETA'])->id]);
    $this->otherKey = app(ApiKeys::class)->issue('Other integration', ['career.read', 'engagement.read', 'communications.read'])['plaintext'];
    actAsTenant(null);
});

/** route => [uri, the query that satisfies it] */
function lookupEndpoints(): array
{
    return [
        'api.v1.leave.calendar' => ['/api/v1/leave/calendar', ['from' => '2026-11-01', 'to' => '2026-11-30']],
        'api.v1.career.gaps' => ['/api/v1/career/gaps', ['employee_code' => 'A-1', 'designation_code' => 'ANL']],
        'api.v1.career.movements' => ['/api/v1/career/movements', ['employee_code' => 'A-1']],
        'api.v1.engagement.my-surveys' => ['/api/v1/engagement/my-surveys', ['employee' => 'A-1']],
        'api.v1.communications.preferences' => ['/api/v1/communications/preferences', ['employee' => 'A-1']],
    ];
}

it('covers exactly the operations whose contract declares required query parameters', function () {
    expect(array_keys(lookupEndpoints()))->toEqualCanonicalizing(array_keys(OpenApiGenerator::QUERY));
    foreach (OpenApiGenerator::QUERY as $route => $parameters) {
        expect(array_map(fn (array $p) => $p[0], array_filter($parameters, fn (array $p) => $p[1])))
            ->toEqualCanonicalizing(array_keys(lookupEndpoints()[$route][1]));
    }
});

it('answers 422 naming each missing required parameter', function () {
    foreach (lookupEndpoints() as [$uri, $query]) {
        $response = $this->withHeader('X-Api-Key', $this->key)->getJson($uri)->assertStatus(422)->assertJsonPath('code', 'validation_failed');
        expect(array_keys($response->json('errors')))->toEqualCanonicalizing(array_keys($query));
        // One parameter given, the other still named.
        if (count($query) > 1) {
            $first = array_key_first($query);
            $partial = $this->withHeader('X-Api-Key', $this->key)->getJson($uri.'?'.http_build_query([$first => $query[$first]]))->assertStatus(422);
            expect(array_keys($partial->json('errors')))->toBe(array_values(array_diff(array_keys($query), [$first])));
        }
    }
    // A window that ends before it starts is a validation error too.
    $this->withHeader('X-Api-Key', $this->key)->getJson('/api/v1/leave/calendar?from=2026-11-30&to=2026-11-01')->assertStatus(422)->assertJsonValidationErrors('to');
});

it('answers each lookup with its parameters in the documented shape', function () {
    $this->withHeader('X-Api-Key', $this->key)->getJson('/api/v1/leave/calendar?from=2026-11-01&to=2026-11-30')->assertOk()
        ->assertJsonStructure(['data', 'meta' => ['page', 'per_page', 'total', 'last_page']]);
    $this->withHeader('X-Api-Key', $this->key)->getJson('/api/v1/career/gaps?employee_code=A-1&designation_code=ANL')->assertOk()
        ->assertJsonPath('data.employee_code', 'A-1')->assertJsonPath('data.designation_code', 'ANL')
        ->assertJsonStructure(['data' => ['skills', 'learning', 'certifications', 'experience']])->assertJsonMissingPath('meta');
    $movements = $this->withHeader('X-Api-Key', $this->key)->getJson('/api/v1/career/movements?employee_code=A-1')->assertOk()->assertJsonMissingPath('meta');
    expect($movements->json('data'))->toBeArray();
    $this->withHeader('X-Api-Key', $this->key)->getJson('/api/v1/engagement/my-surveys?employee=A-1')->assertOk()->assertExactJson(['data' => []]);
    $this->withHeader('X-Api-Key', $this->key)->getJson('/api/v1/communications/preferences?employee=A-1')->assertOk()
        ->assertJsonPath('data.employee_code', 'A-1')->assertJsonStructure(['data' => ['employee_code', 'optional', 'mandatory']]);
});

it('answers 404 for an unknown code or another tenant\'s employee, never their data', function () {
    $employeeLookups = array_filter(lookupEndpoints(), fn (array $e) => $e[0] !== '/api/v1/leave/calendar');
    foreach ($employeeLookups as [$uri, $query]) {
        $unknown = array_map(fn ($v) => $v === 'A-1' ? 'NOPE-9' : $v, $query);
        $this->withHeader('X-Api-Key', $this->key)->getJson($uri.'?'.http_build_query($unknown))->assertNotFound()->assertJsonPath('code', 'not_found');
        $foreign = array_map(fn ($v) => $v === 'A-1' ? 'B-1' : $v, $query);
        $this->withHeader('X-Api-Key', $this->key)->getJson($uri.'?'.http_build_query($foreign))->assertNotFound();
        // The other tenant's key cannot read this tenant's employee either.
        $this->withHeader('X-Api-Key', $this->otherKey)->getJson($uri.'?'.http_build_query($query))->assertNotFound();
    }
    $this->withHeader('X-Api-Key', $this->key)->getJson('/api/v1/career/gaps?employee_code=A-1&designation_code=NOPE')->assertNotFound();
});

it('refuses each lookup without a key (401) or without its scope (403)', function () {
    foreach (lookupEndpoints() as $route => [$uri, $query]) {
        $this->flushHeaders()->getJson($uri.'?'.http_build_query($query))->assertUnauthorized();
        if ($route !== 'api.v1.leave.calendar') {
            $this->withHeader('X-Api-Key', $this->narrow)->getJson($uri.'?'.http_build_query($query))->assertForbidden();
        }
    }
});
