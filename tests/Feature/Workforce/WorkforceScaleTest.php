<?php

use App\Domain\Employment\Models\Employee;
use App\Domain\Employment\Models\EmployeePosition;
use App\Domain\Organisation\Services\OrganisationTree;
use App\Domain\Workforce\Models\Position;
use App\Domain\Workforce\Models\PositionVersion;
use App\Domain\Workforce\Models\WorkforcePlanLine;
use App\Domain\Workforce\Services\WorkforceAnalytics;
use App\Domain\Workforce\Services\WorkforcePlans;
use App\Domain\Workforce\Services\WorkforceSnapshot;
use App\Filament\Resources\Positions\PositionResource;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/../Workflow/WorkflowTestHelpers.php';
require_once __DIR__.'/../Performance/PerformanceTestHelpers.php';
require_once __DIR__.'/WorkforceTestHelpers.php';

/*
 | Phase 10 §58 scale checks (SQLite): workforce read paths run a constant number of queries whatever
 | the number of organisation units, positions, occupants or plan lines (database aggregation, no N+1,
 | nothing loaded wholesale into memory).
 */

beforeEach(function () {
    $this->travelTo('2026-10-05 09:00:00');
    $this->tenant = provisionTenant();
    actAsTenant($this->tenant);
    $this->admin = tenantUser($this->tenant, ['*']);
    $this->actingAs($this->admin);
    $this->org = workforceOrg();
    $this->count = function (callable $callback): int {
        $queries = 0;
        DB::listen(function () use (&$queries) {
            $queries++;
        });
        $callback();

        return $queries;
    };
    // Bulk fixture: N open positions, each under its own department node, half occupied — written
    // directly (the services are exercised elsewhere); one version each, effective since January.
    $this->seed = function (int $count, string $prefix): void {
        $tree = app(OrganisationTree::class);
        $employees = Employee::factory()->count(intdiv($count, 2))->create();
        foreach (range(1, $count) as $i) {
            $node = $tree->createUnit('department', ['name' => "{$prefix} unit {$i}", 'code' => "{$prefix}{$i}"], $this->org['root']);
            $position = Position::query()->create(['company_id' => $this->org['company']->id, 'code' => "{$prefix}-{$i}", 'title' => "{$prefix} role {$i}", 'status' => 'open', 'organisation_node_id' => $node->id, 'department_id' => $node->nodeable_id, 'first_effective_from' => '2026-01-01']);
            $version = PositionVersion::query()->create(['position_id' => $position->id, 'version' => 1, 'status' => 'open', 'effective_from' => '2026-01-01', 'title' => $position->title, 'company_id' => $this->org['company']->id, 'organisation_node_id' => $node->id, 'department_id' => $node->nodeable_id, 'occupancy_mode' => 'single', 'headcount' => 1, 'fte' => 1, 'fte_capacity' => 1, 'change_type' => 'created']);
            $position->update(['current_version_id' => $version->id]);
            if ($i % 2 === 0 && ($employee = $employees->get(intdiv($i, 2) - 1))) {
                EmployeePosition::query()->create(['employee_id' => $employee->id, 'company_id' => $this->org['company']->id, 'department_id' => $node->nodeable_id, 'position_id' => $position->id, 'change_type' => 'hire', 'effective_from' => '2026-02-01']);
            }
        }
    };
});

it('keeps headcount, organisation breakdown, vacancies and historical snapshots at a constant query count', function () {
    ($this->seed)(6, 'S');
    $snapshot = app(WorkforceSnapshot::class);
    $small = ($this->count)(fn () => [$snapshot->headcount(), $snapshot->by('department_id'), $snapshot->vacancies()->paginate(25), $snapshot->headcount('2026-03-31')]);

    ($this->seed)(150, 'L');
    $large = ($this->count)(fn () => $this->result = [$snapshot->headcount(), $snapshot->by('department_id'), $snapshot->vacancies()->paginate(25), $snapshot->headcount('2026-03-31')]);

    expect($this->result[0])->toMatchArray(['positions' => 156, 'approved_seats' => 156, 'occupied_seats' => 78, 'vacant_seats' => 78])
        ->and($this->result[1])->toHaveCount(156)
        ->and($this->result[2]->total())->toBe(78)
        ->and($this->result[3]['occupied_seats'])->toBe(78)
        ->and($snapshot->headcount('2026-01-15')['occupied_seats'])->toBe(0)   // before anyone was assigned
        ->and($large)->toBe($small);
});

it('aggregates a large workforce plan in the database and pages the positions list without per-row queries', function () {
    ($this->seed)(40, 'P');
    $plans = app(WorkforcePlans::class);
    $plan = $plans->create(['code' => 'BIG', 'name' => 'Big plan', 'company_id' => $this->org['company']->id, 'period_type' => 'annual', 'period_start' => '2027-01-01', 'period_end' => '2027-12-31'], $this->admin);
    $version = $plan->versions()->first();
    foreach (range(1, 600) as $i) {
        WorkforcePlanLine::query()->create(['workforce_plan_version_id' => $version->id, 'movement_type' => $i % 3 === 0 ? 'reduction' : 'new_position', 'headcount' => 1, 'fte' => 1, 'effective_date' => '2027-0'.(($i % 9) + 1).'-01']);
    }
    $queries = ($this->count)(fn () => $this->totals = $plans->totals($version));
    expect($this->totals['headcount'])->toBe(400 - 200)->and($queries)->toBeLessThanOrEqual(2);

    $listQueries = ($this->count)(fn () => $this->page = PositionResource::getEloquentQuery()->paginate(25));
    expect($this->page->total())->toBe(40)->and((int) $this->page->getCollection()->sum('occupied_seats'))->toBe(12)   // 20 occupied overall, 12 on the first page of 25 (by id)
        ->and($listQueries)->toBeLessThan(8);
});

it('builds organisation analytics with a bounded number of queries as positions grow', function () {
    ($this->seed)(5, 'A');
    $analytics = app(WorkforceAnalytics::class);
    $analytics->summary($this->admin); // warm-up: permission and role lookups are cached per request
    $small = ($this->count)(fn () => $analytics->summary($this->admin));
    ($this->seed)(80, 'B');
    $large = ($this->count)(fn () => $this->summary = $analytics->summary($this->admin));

    expect($this->summary['headcount']['positions'])->toBe(85)->and($this->summary['by_department'])->toHaveCount(85)->and($large)->toBe($small);
});
