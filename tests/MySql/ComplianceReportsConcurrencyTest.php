<?php

use App\Domain\ComplianceReports\Models\ComplianceReportPack;
use App\Domain\ComplianceReports\Services\ComplianceReports;
use App\Domain\ComplianceReports\Support\ReportPeriod;
use App\Domain\Identity\Scopes\AccessScope;
use App\Domain\Organisation\Models\Establishment;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;

require_once __DIR__.'/../Feature/Compliance/ComplianceTestHelpers.php';
require_once __DIR__.'/ConcurrencyHelpers.php';

/*
 | Compliance reports on MySQL: the pack and establishment migrations, and the per-person lock that keeps two
 | simultaneous requests (a double click, two tabs) from queueing the same pack twice. Same opt-in as the other
 | suites (PEOPLEOS_MYSQL_CONCURRENCY_DB, name containing "concurrency"); SQLite runs skip these and claim nothing
 | about MySQL.
 */

beforeEach(function () {
    $database = (string) env('PEOPLEOS_MYSQL_CONCURRENCY_DB', '');
    if ($database === '' || ! str_contains($database, 'concurrency') || ! function_exists('pcntl_fork')) {
        $this->markTestSkipped('Set PEOPLEOS_MYSQL_CONCURRENCY_DB to a disposable MySQL database (name containing "concurrency") and enable pcntl.');
    }
    config(['database.connections.concurrency' => array_merge(config('database.connections.mysql'), ['database' => $database]), 'database.default' => 'concurrency', 'queue.default' => 'sync']);
    DB::purge('concurrency');
    if (! ($GLOBALS['peopleos_concurrency_migrated'] ?? false)) {
        Artisan::call('migrate:fresh', ['--database' => 'concurrency', '--force' => true]);
        $GLOBALS['peopleos_concurrency_migrated'] = true;
    }
    $this->travelTo('2026-10-05 09:00:00');
    if (! ($GLOBALS['peopleos_concurrency_rules'] ?? false)) {
        syncComplianceRules();
        $GLOBALS['peopleos_concurrency_rules'] = true;
    }
    $this->tenant = provisionTenant('Race '.uniqid());
    actAsTenant($this->tenant);
    $this->actingAs(tenantUser($this->tenant, ['*']));
    ['company' => $this->company] = complianceCompany();
    $this->hr = tenantUser($this->tenant, ['compliance.reports.view', 'compliance.reports.generate']);
});

it('creates the compliance report and establishment columns on MySQL', function () {
    expect(Schema::hasColumns('compliance_report_packs', ['scope_fingerprint', 'metadata', 'reviewed_by', 'reviewed_at', 'review_note', 'manifest', 'options']))->toBeTrue()
        ->and(Schema::hasColumns('establishments', ['appropriate_government', 'ss_appropriate_government', 'industry']))->toBeTrue();

    // The catalogue's longest values fit the columns as MySQL enforces them (SQLite does not).
    $establishment = Establishment::query()->where('company_id', $this->company->id)->firstOrFail();
    $establishment->update(['appropriate_government' => 'central', 'ss_appropriate_government' => 'state', 'industry' => str_repeat('x', 32)]);
    expect($establishment->refresh()->ss_appropriate_government)->toBe('state');
});

it('queues one pack when the same person asks twice at the same moment', function () {
    $results = race(array_fill(0, 3, function () {
        Queue::fake();
        actAsTenant($this->tenant);
        $this->actingAs($this->hr);
        app(ComplianceReports::class)->request($this->company, ReportPeriod::month(2026, 9), $this->hr);
    }), 0.4, ['eloquent.creating: '.ComplianceReportPack::class]);

    expect($results)->each->toBe('ok')
        ->and(ComplianceReportPack::query()->withoutGlobalScope(AccessScope::class)->where('requested_by', $this->hr->id)->count())->toBe(1);
});
