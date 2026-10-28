<?php

use App\Domain\ComplianceReports\Services\ComplianceReports;
use App\Domain\ComplianceReports\Support\ReportPeriod;
use App\Domain\Lifecycle\Enums\LifecycleState;
use App\Domain\Organisation\Models\Establishment;
use App\Domain\Organisation\Services\EstablishmentAssignments;
use Illuminate\Support\Facades\Storage;

require_once __DIR__.'/ComplianceTestHelpers.php';
require_once __DIR__.'/../Workflow/WorkflowTestHelpers.php';
require_once __DIR__.'/../Performance/PerformanceTestHelpers.php';

/*
 | Applicability scenarios the location test does not reach: a verified threshold that excludes (and the coverage
 | rules that keep a factory, or a once-covered establishment, in); an establishment whose state and Code on Wages
 | government are set but whose Code on Social Security government is not; and a registration that is missing.
 */

beforeEach(function () {
    Storage::fake('local');
    $this->travelTo('2026-10-27 09:00:00');
    syncComplianceRules();
    $this->tenant = provisionTenant();
    actAsTenant($this->tenant);
    $this->actingAs(tenantUser($this->tenant, ['*']));
    ['company' => $this->company, 'entity' => $this->entity, 'establishment' => $this->office] = complianceCompany();
    $this->office->update(['establishment_type' => 'office', 'appropriate_government' => 'state', 'ss_appropriate_government' => 'state']);
    $this->reports = app(ComplianceReports::class);
    $this->hr = tenantUser($this->tenant, ['compliance.reports.view', 'compliance.reports.generate']);
    $this->item = fn (string $period, string $key, Establishment $at) => collect($this->reports->plan($this->company, ReportPeriod::fromKey($period)))
        ->first(fn ($i) => $i['definition']->key === $key && $i['target']['type'] === 'establishment' && $i['target']['id'] === $at->id);
    $this->staff = function (Establishment $at, int $count, string $joined = '2025-01-01', ?string $left = null): void {
        foreach (range(1, $count) as $_) {
            $employee = forceLifecycle(activeEmployee(), $left ? LifecycleState::Exited : LifecycleState::Active, ['joining_date' => $joined] + ($left ? ['exit_date' => $left] : []));
            app(EstablishmentAssignments::class)->assign($employee, $at, $joined, 'Test assignment');
        }
    };
    $this->establishment = fn (string $code, string $type) => Establishment::query()->create(['company_id' => $this->company->id, 'legal_entity_id' => $this->entity->id, 'code' => $code, 'name' => "{$code} {$type}",
        'state' => 'KA', 'country' => 'IN', 'establishment_type' => $type, 'appropriate_government' => 'state', 'ss_appropriate_government' => 'state', 'status' => 'active', 'effective_from' => '2020-01-01']);
});

it('excludes an establishment below a verified threshold, and keeps a factory or a once-covered establishment in', function () {
    ($this->staff)($this->office, 3);
    $factory = ($this->establishment)('MYS', 'factory');
    ($this->staff)($factory, 1);

    // Gratuity: the First Schedule's ten employees, read in the official text, excludes a three-person office outright.
    $office = ($this->item)('FY:2026', 'gratuity_register', $this->office);
    expect($office['determination'])->toBe('NOT_APPLICABLE')
        ->and($office['reason'])->toContain('below the threshold of 10')->toContain('First Schedule, Chapter V')->toContain('contract labour');

    // A factory is covered whatever its size.
    expect(($this->item)('FY:2026', 'gratuity_register', $factory)['applicability'])->toBe('APPLIES');

    // Ten on one day in May–June 2025 (seven who then left): not covered in 2024-25, covered in 2025-26, and still
    // covered in 2026-27 with three (s.1(8)).
    ($this->staff)($this->office, 7, '2025-05-01', '2025-06-30');
    expect(($this->item)('FY:2024', 'gratuity_register', $this->office)['determination'])->toBe('NOT_APPLICABLE')
        ->and(($this->item)('FY:2025', 'gratuity_register', $this->office)['applicability'])->toBe('APPLIES')
        ->and(($this->item)('FY:2026', 'gratuity_register', $this->office)['applicability'])->toBe('APPLIES');

    // A threshold that rests on a partly verified source never excludes on its own: the bonus register's twenty.
    $bonus = ($this->item)('FY:2026', 'bonus_register', $this->office);
    expect($bonus['determination'])->toBe('PENDING_VERIFICATION')->and($bonus['reason'])->toStartWith('May not apply');
});

it('asks for the appropriate government of each Code separately', function () {
    $this->office->update(['ss_appropriate_government' => null]);

    // The Code on Wages registers resolve from the Code on Wages government ...
    expect(($this->item)('M:2026-06', 'wage_register', $this->office)['determination'])->not->toBe('MISSING_CONFIGURATION')
        // ... the maternity register needs the Code on Social Security one, which is not set.
        ->and(($this->item)('M:2026-06', 'maternity_register', $this->office)['determination'])->toBe('MISSING_CONFIGURATION')
        ->and(collect($this->reports->configurationGaps($this->company, ReportPeriod::month(2026, 6)))->pluck('missing')->all())
        ->toContain('Appropriate government (Code on Social Security)')->not->toContain('Appropriate government (Code on Wages, OSH Code)');

    $this->office->update(['ss_appropriate_government' => 'central']);
    expect(($this->item)('M:2026-06', 'maternity_register', $this->office)['determination'])->not->toBe('MISSING_CONFIGURATION');
});

it('produces a report for an establishment with no registration, marked incomplete with what is missing', function () {
    $branch = ($this->establishment)('HUB', 'office');

    $report = $this->reports->single($this->company, ReportPeriod::month(2026, 9), "pf_challan|establishment|{$branch->id}", $this->hr);
    expect($report['status']['warnings'])->toContain("No EPF establishment code is recorded for {$branch->name} (Compliance → Statutory registrations).")
        ->and($report['status']['readiness'])->not->toBeIn(['LEGAL_SOURCE_VERIFIED', 'INTERNAL_CONTROL']);

    // The establishment that has its registration does not carry the warning.
    expect($this->reports->single($this->company, ReportPeriod::month(2026, 9), "pf_challan|establishment|{$this->office->id}", $this->hr)['status']['warnings'])
        ->not->toContain("No EPF establishment code is recorded for {$this->office->name} (Compliance → Statutory registrations).");
});
