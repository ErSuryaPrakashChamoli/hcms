<?php

use App\Domain\Compliance\Models\ComplianceRule;
use App\Domain\Compliance\Services\RuleVerifications;
use App\Domain\ComplianceReports\Services\ComplianceReports;
use App\Domain\ComplianceReports\Support\ReportPeriod;
use App\Domain\Organisation\Models\EmploymentType;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

require_once __DIR__.'/ComplianceTestHelpers.php';

/**
 * Bonus and gratuity against hand-computed cases. The standard structure pays basic at 40% of CTC, so CTC ₹2,40,000
 * is basic ₹8,000 a month and CTC ₹9,00,000 is basic ₹30,000. Neither DA nor bonus-classified earnings are paid.
 */
beforeEach(function () {
    Storage::fake('local');
    $this->travelTo('2026-10-05 09:00:00');
    syncComplianceRules();
    $this->tenant = provisionTenant();
    actAsTenant($this->tenant);
    $this->actingAs(tenantUser($this->tenant, ['*']));
    ['company' => $this->company, 'establishment' => $this->establishment] = complianceCompany();
    $this->reports = app(ComplianceReports::class);
    $this->hr = tenantUser($this->tenant, ['compliance.reports.view', 'compliance.reports.generate']);
    $this->payroll = fn (int $year, int $month) => finalizedPayroll($this->company, $year, $month, tenantUser($this->tenant, ['payroll.*', 'employee.*']), tenantUser($this->tenant, ['payroll.*', 'employee.*']));
    $this->rows = function (string $key, string $period): array {
        $this->actingAs($this->hr);
        $sheet = firstSheet($this->reports->single($this->company, ReportPeriod::fromKey($period), "{$key}|establishment|{$this->establishment->id}", $this->hr)['content']);
        $header = collect($sheet)->search(fn ($row) => ($row[0] ?? null) === 'Sl.');

        return ['header' => $sheet[$header], 'rows' => collect(array_slice($sheet, $header + 1))->filter(fn ($r) => is_numeric($r[0] ?? null))->map(fn ($r) => array_combine(array_slice($sheet[$header], 0, count($r)), $r))->keyBy('Code')->all(), 'sheet' => $sheet];
    };
});

it('computes bonus under the proposed Code on Wages version and marks every result legally unverified', function () {
    $standard = statutoryEmployee(240000, $this->establishment, '100200300501');
    $high = statutoryEmployee(900000, $this->establishment, '100200300502');
    $crossing = statutoryEmployee(240000, $this->establishment, '100200300503');
    compensate($crossing, 900000, '2026-09-01', ['CONV' => 1600], 'revision', 'Promotion');
    foreach ([7, 8, 9] as $month) {
        ($this->payroll)(2026, $month);
    }

    ['rows' => $rows, 'sheet' => $sheet] = ($this->rows)('bonus_register', 'FY:2026');

    // Basic ₹8,000 is above the ₹7,000 calculation ceiling, so each month counts ₹7,000: 3 × 7,000 = 21,000.
    $s = $rows[$standard->employee_code];
    expect((float) $s['Wages earned (basic + DA)'])->toBe(24000.0)
        ->and((float) $s['Days paid in the year'])->toBe(92.0)
        ->and((float) $s['Wages for bonus (after ceiling)'])->toBe(21000.0)
        ->and((float) $s['Minimum bonus'])->toBe(1750.0) // 8⅓% of 21,000 (above the ₹100 floor)
        ->and((float) $s['Maximum bonus'])->toBe(4200.0) // 20% of 21,000
        ->and((float) $s['Short of minimum'])->toBe(1750.0)
        ->and($s['Status'])->toBe('Legally unverified')
        ->and($s['Provisional determination'])->toBe('Pending determination')
        ->and($s['Reason'])->toContain('minimum wage if higher');

    // Basic ₹30,000 is above the ₹21,000 eligibility ceiling every month: ineligible, provisionally.
    $h = $rows[$high->employee_code];
    expect($h['Status'])->toBe('Legally unverified')->and($h['Provisional determination'])->toBe('Ineligible')
        ->and($h['Reason'])->toContain('above ₹21,000 a month throughout')
        ->and($h['Wages for bonus (after ceiling)'] ?? '')->toBe('')->and($h['Minimum bonus'] ?? '')->toBe('');

    // Two months at basic ₹8,000 and one at ₹30,000: the ceiling was crossed, so eligibility is not decided.
    $c = $rows[$crossing->employee_code];
    expect($c['Provisional determination'])->toBe('Pending determination')
        ->and($c['Reason'])->toContain('crossed the ₹21,000 eligibility ceiling during the year (1 month(s) above)')
        ->and((float) $c['Wages for bonus (after ceiling)'])->toBe(21000.0);

    expect(sheetHas($sheet, 'Calculated with: Statutory bonus — Code on Wages, 2019 (central sphere) v2 (proposed, not in force)'))->toBeTrue()
        ->and(collect($sheet)->flatten()->contains(fn ($c) => str_contains((string) $c, 'every determination is legally unverified')))->toBeTrue();
});

it('measures each month of a year that straddles the change of law under the version then in force', function () {
    statutoryEmployee(240000, $this->establishment, '100200300511');
    ($this->payroll)(2025, 10);
    ($this->payroll)(2025, 12);

    ['sheet' => $sheet] = ($this->rows)('bonus_register', 'FY:2025');
    $notes = collect($sheet)->flatten()->implode(' | ');
    expect($notes)->toContain('The year straddles a change of law')
        ->toContain('Payment of Bonus Act, 1965 v1 from 2014-04-01')
        ->toContain('Code on Wages, 2019 (central sphere) v2 from 2025-11-21')
        ->toContain('needs legal review');
});

it('refuses to bring the proposed bonus version into force while a parameter is not confirmed from an official source', function () {
    $rule = ComplianceRule::query()->where('code', 'BONUS')->where('version', 2)->sole();
    $verifications = app(RuleVerifications::class);
    $verifications->attachEvidence($rule, '%PDF-1.4 gazette copy', 'so-4710.pdf', '2026-10-01', 'https://egazette.gov.in/x.pdf', platformAdmin());

    expect(fn () => $verifications->verify($rule, platformAdmin(), 'Looks right'))->toThrow(RuntimeException::class, 'eligibility_ceiling')
        ->and($rule->refresh()->status)->toBe(ComplianceRule::PROPOSED);
});

it('decides bonus eligibility outright only under a verified version in force', function () {
    // Test fixture: the version stood up as verified and active (in the product only verify() does this, after a
    // second platform administrator checks complete official evidence).
    DB::table('compliance_rules')->where('code', 'BONUS')->where('version', 2)->update(['status' => ComplianceRule::ACTIVE, 'verification_status' => ComplianceRule::VERIFIED]);
    $high = statutoryEmployee(900000, $this->establishment, '100200300521');
    ($this->payroll)(2026, 9);

    ['rows' => $rows, 'sheet' => $sheet] = ($this->rows)('bonus_register', 'FY:2026');
    expect($rows[$high->employee_code]['Status'])->toBe('Ineligible')
        ->and(collect($sheet)->flatten()->contains(fn ($c) => str_contains((string) $c, 'v2 (verified).')))->toBeTrue();
});

it('applies the fixed-term qualifying service only from the version that states it', function () {
    // Two employees: covered only because a factory is covered whatever its size (First Schedule, Chapter V (a)).
    $this->establishment->update(['establishment_type' => 'factory']);
    $permanent = statutoryEmployee(240000, $this->establishment, '100200300531');
    $fixedTerm = statutoryEmployee(240000, $this->establishment, '100200300532');
    $type = EmploymentType::query()->firstOrCreate(['code' => 'FIXED_TERM'], ['name' => 'Fixed-term', 'status' => 'active']);
    DB::table('employee_positions')->where('employee_id', $fixedTerm->id)->update(['employment_type_id' => $type->id]);
    ($this->payroll)(2026, 3);
    ($this->payroll)(2026, 9);

    // FY 2025-26 ends on 31 Mar 2026: GRATUITY v2 (the Code alone) sets no fixed-term minimum, so it is not decided.
    $before = ($this->rows)('gratuity_register', 'FY:2025')['rows'];
    expect($before[$fixedTerm->employee_code]['Status'])->toBe('Pending determination')
        ->and($before[$fixedTerm->employee_code]['Reason'])->toContain('does not state the fixed-term qualifying service');

    // FY 2026-27 ends on 31 Mar 2027: v3 (SS (Central) Rules r.33) qualifies fixed-term service of one year.
    // Joined 1 Jan 2025: 2 years 3 months, so 2 years counted; 8,000 × 15/26 × 2 = 9,230.77.
    $after = ($this->rows)('gratuity_register', 'FY:2026')['rows'];
    expect($after[$fixedTerm->employee_code]['Status'])->toBe('Legally unverified')
        ->and($after[$fixedTerm->employee_code]['Reason'])->toContain('Provisionally qualifies (2 year(s) counted, 1 needed, fixed-term)')
        ->and((float) $after[$fixedTerm->employee_code]['Gratuity on service so far'])->toBe(9230.77)
        ->and($after[$permanent->employee_code]['Reason'])->toContain('Provisionally not yet qualified (2 year(s) counted, 5 needed)');
});
