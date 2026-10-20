<?php

use App\Domain\Analytics\Services\PlatformAnalytics;
use App\Domain\Audit\Services\ChangeIntelligence;
use App\Domain\Employment\Models\Employee;
use App\Domain\Experience\Services\Employee360;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Scopes\AccessScope;
use App\Domain\Organisation\Models\Company;
use App\Support\Observability\HealthChecks;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

/*
 | Phase 14 scale helpers: bulk-seed a population (people, employees, current positions) without the
 | per-hire workflow, then measure the Phase 14 read surfaces by query count and wall time.
 */

/** Grow the bound tenant's active population to $total employees (bulk inserts, no audit rows). */
function platformSeedPopulation(int $total, Company $company, array $locationIds): void
{
    $tenantId = app(TenantContext::class)->id();
    $have = Employee::query()->withoutGlobalScope(AccessScope::class)->count();
    $now = now()->toDateTimeString();
    for ($start = $have; $start < $total; $start += 500) {
        $batch = range($start + 1, min($total, $start + 500));
        DB::table('people')->insert(array_map(fn ($i) => ['tenant_id' => $tenantId, 'first_name' => 'Scale', 'last_name' => 'P'.$i, 'created_at' => $now, 'updated_at' => $now], $batch));
        $people = DB::table('people')->where('tenant_id', $tenantId)->where('first_name', 'Scale')->whereIn('last_name', array_map(fn ($i) => 'P'.$i, $batch))->pluck('id', 'last_name');
        DB::table('employees')->insert(array_map(fn ($i) => ['tenant_id' => $tenantId, 'person_id' => $people['P'.$i], 'employee_code' => 'SC'.str_pad((string) $i, 6, '0', STR_PAD_LEFT),
            'lifecycle_state' => 'active', 'joining_date' => '2024-01-01', 'created_at' => $now, 'updated_at' => $now], $batch));
        $employees = DB::table('employees')->where('tenant_id', $tenantId)->whereIn('employee_code', array_map(fn ($i) => 'SC'.str_pad((string) $i, 6, '0', STR_PAD_LEFT), $batch))->pluck('id', 'employee_code');
        DB::table('employee_positions')->insert(array_map(fn ($i) => ['tenant_id' => $tenantId, 'employee_id' => $employees['SC'.str_pad((string) $i, 6, '0', STR_PAD_LEFT)], 'company_id' => $company->id,
            'location_id' => $locationIds[$i % count($locationIds)], 'change_type' => 'hire', 'effective_from' => '2024-01-01', 'created_at' => $now, 'updated_at' => $now], $batch));
    }
}

/**
 * Query count and milliseconds per Phase 14 read surface for the given viewer.
 *
 * @return array<string, array{queries: int, ms: float}>
 */
function platformMeasureSurfaces(User $viewer, Employee $subject, ?string $apiKey, callable $http): array
{
    $measure = function (callable $work): array {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $started = hrtime(true);
        $work();
        $ms = round((hrtime(true) - $started) / 1e6, 1);
        // The API key's last_used_at write is throttled by time, not driven by data volume: not counted.
        $queries = count(array_filter(DB::getQueryLog(), fn ($q) => ! str_starts_with($q['query'], 'update `api_keys` set `last_used_at`') && ! str_starts_with($q['query'], 'update "api_keys" set "last_used_at"')));
        DB::disableQueryLog();

        return ['queries' => $queries, 'ms' => $ms];
    };

    $out = [
        'employee_360' => $measure(fn () => app(Employee360::class)->for($viewer, $subject)),
        'people_analytics' => $measure(fn () => app(PlatformAnalytics::class)->overview($viewer)),
        'change_intelligence_page' => $measure(fn () => app(ChangeIntelligence::class)->query($viewer)->paginate(25)),
        'health_ready' => $measure(fn () => app(HealthChecks::class)->run()),
    ];
    if ($apiKey !== null) {
        $out['api_employees_page'] = $measure(fn () => $http('/api/v1/employees?per_page=25', $apiKey));
    }

    return $out;
}
