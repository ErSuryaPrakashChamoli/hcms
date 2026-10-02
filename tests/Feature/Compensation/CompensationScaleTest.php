<?php

use App\Domain\Compensation\Contracts\CompensationOutput;
use App\Domain\Compensation\Services\CompensationAnalytics;
use App\Domain\Payroll\Models\PayrollPeriod;
use App\Filament\Resources\CompensationChanges\CompensationChangeResource;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/../Workflow/WorkflowTestHelpers.php';
require_once __DIR__.'/../Performance/PerformanceTestHelpers.php';
require_once __DIR__.'/../Payroll/PayrollTestHelpers.php';

/* Phase 11 §44: compensation read paths run a constant number of queries whatever the history or population size. */

beforeEach(function () {
    $this->travelTo('2026-09-21 09:00:00');
    syncComplianceRules();
    $this->tenant = provisionTenant();
    actAsTenant($this->tenant);
    $this->admin = tenantUser($this->tenant, ['*']);
    $this->actingAs($this->admin);
    $this->company = payrollCompany();
    $this->count = function (callable $callback): int {
        $queries = 0;
        DB::listen(function () use (&$queries) {
            $queries++;
        });
        $callback();

        return $queries;
    };
});

it('reads payroll compensation, history and fingerprints at a constant query count', function () {
    $output = app(CompensationOutput::class);
    $period = PayrollPeriod::for($this->company, 2026, 9);
    $short = salariedEmployee(600000, ['task.view'], '2026-01-01');
    $long = salariedEmployee(600000, ['task.view'], '2026-01-01');
    foreach (['2026-02-01', '2026-03-01', '2026-04-01', '2026-05-01', '2026-06-01', '2026-07-01', '2026-08-01', '2026-09-10', '2026-09-20'] as $i => $from) {
        compensate($long, 610000 + $i * 10000, $from, [], 'revision');
    }
    $output->forPayroll($short, $period->start_date, $period->end_date);   // warm-up

    $small = ($this->count)(fn () => [$output->forPayroll($short, $period->start_date, $period->end_date), $output->history($short)]);
    $large = ($this->count)(fn () => [$this->segments = $output->forPayroll($long, $period->start_date, $period->end_date), $output->history($long)]);
    expect($large)->toBe($small)->and($this->segments->segments)->toHaveCount(3);

    $few = ($this->count)(fn () => $output->fingerprints([$short->id], $period->start_date, $period->end_date));
    $many = collect(range(1, 12))->map(fn () => salariedEmployee(500000, ['task.view'], '2026-01-01')->id)->push($short->id, $long->id)->all();
    expect(($this->count)(fn () => $output->fingerprints($many, $period->start_date, $period->end_date)))->toBe($few);
});

it('builds analytics and pages the change queue without per-row queries', function () {
    $analytics = app(CompensationAnalytics::class);
    collect(range(1, 6))->each(fn ($i) => salariedEmployee(500000 + $i * 1000, ['task.view'], '2026-01-01'));
    $analytics->summary($this->admin);   // warm-up (permissions, roles)
    $small = ($this->count)(fn () => $analytics->summary($this->admin));
    collect(range(1, 30))->each(fn ($i) => salariedEmployee(600000 + $i * 1000, ['task.view'], '2026-01-01'));
    $large = ($this->count)(fn () => $this->summary = $analytics->summary($this->admin));
    expect($large)->toBe($small)->and($this->summary['population'])->toBe(36);

    $queries = ($this->count)(fn () => $this->page = CompensationChangeResource::getEloquentQuery()->paginate(25));
    expect($this->page->total())->toBe(36)->and($queries)->toBeLessThan(12);
});
