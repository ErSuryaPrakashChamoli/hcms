<?php

use App\Domain\Attendance\Models\AttendanceRecord;
use App\Domain\Employment\Models\Employee;
use App\Domain\Identity\Models\User;
use App\Domain\Integration\Services\ApiKeys;
use App\Domain\Leave\Models\LeaveRequest;
use App\Domain\Payroll\Models\PayrollRun;
use App\Domain\Payroll\Models\Payslip;
use App\Support\Tenancy\TenantContext;
use Database\Seeders\Demo\DemoOrganisation;
use Illuminate\Routing\Route;
use Illuminate\Support\Str;

require_once __DIR__.'/../Admin/PopulatedSweepHelpers.php';

/*
| QA sweep of the integration API (§87): every route, not only the ones a module's own tests call.
| 1. No key, or a key without the route's scope, never reaches a controller.
| 2. With every scope, each list endpoint of a populated organisation answers 200 with a JSON body.
| 3. Differential tenant isolation: an identifier that the owning tenant's key reads (200) is "not found" (404) for
|    another tenant's key holding every scope, and nothing of the first tenant appears in the second tenant's lists.
*/

/** @return list<Route> every route of the integration API (v1 and SCIM) */
function apiRoutes(): array
{
    return collect(app('router')->getRoutes()->getRoutes())
        ->filter(fn (Route $route) => Str::startsWith($route->uri(), 'api/'))
        ->values()->all();
}

/** The scopes a route demands through api.key:<scope> (one or more). */
function apiRouteScopes(Route $route): array
{
    return collect($route->gatherMiddleware())
        ->map(fn ($middleware) => is_string($middleware) ? $middleware : '')
        ->filter(fn (string $middleware) => Str::startsWith($middleware, ['api.key:', 'App\\Http\\Middleware\\AuthenticateApiKey:']))
        ->flatMap(fn (string $middleware) => explode(',', Str::after($middleware, ':')))
        ->values()->all();
}

/** The route's URI with every {parameter} filled with a harmless placeholder. */
function apiPlaceholderUri(Route $route): string
{
    return '/'.preg_replace('/\{[^}]+\}/', '1', $route->uri());
}

/** @return list<string> the HTTP methods to call a route with (HEAD is GET's twin) */
function apiRouteMethods(Route $route): array
{
    return array_values(array_diff($route->methods(), ['HEAD']));
}

function everyApiScope(): array
{
    return array_keys(config('peopleos.api.scopes'));
}

// A sweep sends a few hundred calls from one address and key: lift the per-minute limit, keep the limiter itself.
beforeEach(fn () => config(['peopleos.api.rate_limit_per_minute' => 100000]));

it('refuses every API route without a key or with a malformed one', function () {
    $routes = apiRoutes();
    $answers = [];
    foreach ($routes as $route) {
        foreach (apiRouteMethods($route) as $method) {
            $uri = apiPlaceholderUri($route);
            $answers["{$method} {$uri} (no key)"] = $this->json($method, $uri)->getStatusCode();
            $answers["{$method} {$uri} (bad key)"] = $this->withHeader('X-Api-Key', 'pk_nope.nope')->json($method, $uri)->getStatusCode();
            $this->flushHeaders();
        }
    }

    expect(count($routes))->toBeGreaterThan(120)
        ->and(array_filter($answers, fn (int $status) => $status !== 401))->toBe([]);
});

it('declares a scope on every API route and refuses a valid key that lacks it', function () {
    $tenant = provisionTenant();
    actAsTenant($tenant);
    // A real, active key of the tenant holding only a scope no route asks for.
    $key = app(ApiKeys::class)->issue('Unrelated scope', ['webhooks.read'])['plaintext'];

    $unscoped = [];
    $answers = [];
    foreach (apiRoutes() as $route) {
        $scopes = apiRouteScopes($route);
        if ($scopes === []) {
            $unscoped[] = $route->uri();
        }
        foreach (apiRouteMethods($route) as $method) {
            $uri = apiPlaceholderUri($route);
            $answers["{$method} {$uri}"] = $this->withHeader('X-Api-Key', $key)->json($method, $uri)->getStatusCode();
        }
    }

    expect($unscoped)->toBe([])
        ->and(array_filter($answers, fn (int $status) => $status !== 403))->toBe([]);
});

it('answers every list endpoint of a populated organisation, and keeps each tenant\'s records to itself', function () {
    $this->travelTo('2026-11-11 10:00:00');
    seedPopulatedOrganisation();
    $demo = DemoOrganisation::tenant();
    $ownKey = app(ApiKeys::class)->issue('Demo integration', everyApiScope())['plaintext'];

    $other = provisionTenant('Other Org');
    $otherKey = app(TenantContext::class)->runAs($other, fn () => app(ApiKeys::class)->issue('Other integration', everyApiScope())['plaintext']);
    actAsTenant($demo);

    // 2. Every list endpoint (GET without a route parameter) answers 200 with JSON for the owner.
    $lists = collect(apiRoutes())
        ->filter(fn (Route $route) => in_array('GET', $route->methods(), true) && $route->parameterNames() === [])
        ->map(fn (Route $route) => '/'.$route->uri())->values();
    $failures = [];
    $demoCodes = Employee::query()->pluck('employee_code')->filter()->all();
    $leaks = [];
    foreach ($lists as $uri) {
        $response = $this->withHeader('X-Api-Key', $ownKey)->getJson($uri);
        if ($response->getStatusCode() !== 200 || ! is_array($response->json())) {
            $failures[$uri] = $response->getStatusCode().' '.Str::limit((string) ($response->exception?->getMessage() ?? $response->getContent()), 200);
        }
        $body = $this->withHeader('X-Api-Key', $otherKey)->getJson($uri)->getContent();
        foreach ($demoCodes as $code) {
            if (str_contains((string) $body, '"'.$code.'"')) {
                $leaks[] = "{$uri} shows {$code}";
            }
        }
    }

    // 3. Record endpoints: what the owner reads (200) is not found (404) for the other tenant's all-scope key.
    $employee = Employee::query()->whereNotNull('employee_code')->orderBy('id')->first();
    $attendance = AttendanceRecord::query()->with('employee')->orderBy('id')->first();
    $records = array_filter([
        'employee' => "/api/v1/employees/{$employee->id}",
        'attendance day' => $attendance ? "/api/v1/attendance/records/{$attendance->employee->employee_code}/{$attendance->date->toDateString()}" : null,
        'leave request' => ($leave = LeaveRequest::query()->orderBy('id')->first()) ? "/api/v1/leave/requests/{$leave->id}" : null,
        'payroll run' => ($run = PayrollRun::query()->orderBy('id')->first()) ? "/api/v1/payroll/runs/{$run->id}" : null,
        'payslip' => ($slip = Payslip::query()->orderBy('id')->first()) ? "/api/v1/payroll/payslips/{$slip->number}" : null,
        'compensation' => "/api/v1/compensation/employees/{$employee->employee_code}",
        'compensation history' => "/api/v1/compensation/employees/{$employee->employee_code}/history",
        'scim user' => ($user = User::query()->where('tenant_id', $demo->id)->orderBy('id')->first()) ? "/api/scim/v2/Users/{$user->id}" : null,
    ]);
    $isolation = [];
    foreach ($records as $label => $uri) {
        $own = $this->withHeader('X-Api-Key', $ownKey)->getJson($uri)->getStatusCode();
        $foreign = $this->withHeader('X-Api-Key', $otherKey)->getJson($uri)->getStatusCode();
        if ($own !== 200 || $foreign !== 404) {
            $isolation[$label] = "owner {$own}, other tenant {$foreign} ({$uri})";
        }
    }

    expect($lists->count())->toBeGreaterThan(80)
        ->and(count($records))->toBeGreaterThanOrEqual(6)
        ->and($failures)->toBe([])
        ->and($leaks)->toBe([])
        ->and($isolation)->toBe([]);
});
