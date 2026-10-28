<?php

use App\Domain\Compliance\Models\ComplianceRule;
use App\Domain\Compliance\Services\ComplianceRules;
use App\Domain\Compliance\Services\GratuityEntitlements;
use App\Domain\Compliance\Support\GratuityDetermination;
use App\Domain\Exit\Services\Exits;
use App\Domain\Exit\Services\FinalSettlements;
use App\Domain\Lifecycle\Enums\LifecycleState;
use App\Domain\Organisation\Models\EmploymentType;
use App\Domain\Organisation\Models\Establishment;
use App\Domain\Organisation\Services\EstablishmentAssignments;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/../Workflow/WorkflowTestHelpers.php';
require_once __DIR__.'/../Performance/PerformanceTestHelpers.php';
require_once __DIR__.'/../Payroll/PayrollTestHelpers.php';
require_once __DIR__.'/../Compliance/ComplianceTestHelpers.php';

/*
 | Gratuity entitlement at exit (Code on Social Security, 2020 Chapter V; the Payment of Gratuity Act, 1972 before
 | 21 Nov 2025), under the GRATUITY rule version that governs the last working day. Hand-computed: last drawn wages
 | ₹20,000 × 15/26 = ₹11,538.46 per year counted.
 */

beforeEach(function () {
    $this->travelTo('2026-10-27 09:00:00');
    syncComplianceRules();
    $this->tenant = provisionTenant();
    actAsTenant($this->tenant);
    $this->hr = tenantUser($this->tenant, ['*']);
    $this->actingAs($this->hr);
    ['company' => $this->company, 'establishment' => $this->establishment] = complianceCompany();
    $this->establishment->update(['establishment_type' => 'factory']); // covered whatever its size
    $this->gratuity = app(GratuityEntitlements::class);
    $this->joined = function (string $joining, ?Establishment $at = null, ?string $type = null) {
        $employee = forceLifecycle(activeEmployee(), LifecycleState::Active, ['joining_date' => $joining]);
        app(EstablishmentAssignments::class)->assign($employee, $at ?? $this->establishment, max($joining, '2020-01-01'), 'Test assignment');
        if ($type !== null) {
            DB::table('employee_positions')->where('employee_id', $employee->id)->update(['employment_type_id' => EmploymentType::query()->firstOrCreate(['code' => $type], ['name' => 'Fixed-term', 'status' => 'active'])->id]);
        }

        return $employee->refresh();
    };
    $this->onExit = fn ($employee, string $day, string $type = 'resignation', ?float $wages = 20000, ?float $remuneration = null) => $this->gratuity->onExit($employee, $day, $type, $wages, $remuneration);
});

it('pays under the Payment of Gratuity Act before the Code, counting a part year only in excess of six months', function () {
    $employee = ($this->joined)('2018-04-01');
    $g = ($this->onExit)($employee, '2025-10-31');

    expect($g->status)->toBe(GratuityDetermination::ELIGIBLE)
        ->and($g->rule->version)->toBe(1)
        ->and($g->serviceText)->toBe('7 y 7 m')
        ->and($g->yearsCounted)->toBe(8)
        ->and($g->amount)->toBe(92307.69)
        ->and($g->payable())->toBeTrue()
        ->and($g->paymentDue)->toContain('Payment of Gratuity Act, 1972 s.7(3)')
        ->and($g->coverage['status'])->toBe('covered');

    // Exactly six months over a completed year does not count; one day more does.
    expect(($this->onExit)(($this->joined)('2019-05-01'), '2025-10-31')->yearsCounted)->toBe(6)
        ->and(($this->onExit)(($this->joined)('2019-04-30'), '2025-10-31')->yearsCounted)->toBe(7);
});

it('decides qualifying service by the reason of exit', function () {
    $short = ($this->joined)('2021-08-01');
    expect(($this->onExit)($short, '2025-10-31')->status)->toBe(GratuityDetermination::NOT_ELIGIBLE)
        ->and(($this->onExit)($short, '2025-10-31')->payable())->toBeFalse();

    // Death waives the five years: 2 years 8 months counts as 3.
    $death = ($this->onExit)(($this->joined)('2023-03-01'), '2025-10-31', 'death');
    expect($death->status)->toBe(GratuityDetermination::ELIGIBLE)->and($death->yearsCounted)->toBe(3)->and($death->amount)->toBe(34615.38)
        ->and($death->reason)->toContain('qualifying service waived on death');

    // Four years and 273 days: the 240-day rule is for a person to check against attendance.
    $near = ($this->onExit)(($this->joined)('2021-02-01'), '2025-10-31');
    expect($near->status)->toBe(GratuityDetermination::PENDING)->and($near->reason)->toContain('240 days were actually worked')->and($near->payable())->toBeFalse();

    // Termination: forfeiture is for a person to decide, with a written order.
    $terminated = ($this->onExit)(($this->joined)('2018-04-01'), '2025-10-31', 'termination');
    expect($terminated->status)->toBe(GratuityDetermination::PENDING)->and($terminated->reviews[0])->toContain('Payment of Gratuity Act, 1972 s.4(6)')->and($terminated->amount)->toBe(92307.69);

    // "Other": record whether it was death or disablement.
    expect(($this->onExit)($short, '2025-10-31', 'other')->reviews[0])->toContain('death or disablement');
});

it('caps at the ceiling and never treats missing wages as ineligibility', function () {
    $employee = ($this->joined)('2018-04-01');
    $capped = ($this->onExit)($employee, '2025-10-31', 'resignation', 700000);
    expect($capped->amount)->toBe(2000000.0)->and($capped->capped)->toBeTrue()->and($capped->status)->toBe(GratuityDetermination::ELIGIBLE);

    $unknown = ($this->onExit)($employee, '2025-10-31', 'resignation', null);
    expect($unknown->status)->toBe(GratuityDetermination::INSUFFICIENT_DATA)->and($unknown->amount)->toBeNull()->and($unknown->payable())->toBeFalse();
});

it('uses the version for the date: Code versions prepared for review are provisional, and enforcement needs a verified one', function () {
    $employee = ($this->joined)('2018-04-01');

    $v2 = ($this->onExit)($employee, '2026-01-31');
    expect($v2->status)->toBe(GratuityDetermination::LEGALLY_UNVERIFIED)->and($v2->rule->version)->toBe(2)
        ->and($v2->reason)->toContain('Provisionally eligible')->toContain('prepared for review and not in force')
        ->and($v2->amount)->toBe(92307.69)->and($v2->payable())->toBeFalse()
        ->and($v2->toArray())->toHaveKey('provisional_amount')->not->toHaveKey('amount');
    expect(($this->onExit)($employee, '2026-10-27')->rule->version)->toBe(3);

    config(['peopleos.payroll.enforce_verified_rules' => true]);
    $enforced = ($this->onExit)($employee, '2025-10-31');
    expect($enforced->status)->toBe(GratuityDetermination::LEGALLY_UNVERIFIED)->and($enforced->reason)->toContain('not verified against an official source')->and($enforced->payable())->toBeFalse();
});

it('applies the fixed-term qualifying service only from the version that states it', function () {
    $fixed = ($this->joined)('2025-03-01', null, 'FIXED_TERM');

    $v2 = ($this->onExit)($fixed, '2026-01-31', 'contract_expiry');
    expect($v2->status)->toBe(GratuityDetermination::PENDING)->and($v2->reason)->toContain('does not state the fixed-term qualifying service');

    // v3 (SS (Central) Rules r.33): one year; 1 year 7 months counts as 2.
    $v3 = ($this->onExit)($fixed, '2026-10-27', 'contract_expiry');
    expect($v3->status)->toBe(GratuityDetermination::LEGALLY_UNVERIFIED)->and($v3->reason)->toContain('Provisionally eligible (2 year(s) counted, 1 needed, fixed-term)')
        ->and($v3->amount)->toBe(23076.92);
    expect(($this->onExit)($fixed, '2026-10-27', 'resignation')->reviews)->toContain('Fixed-term employee leaving before the term ends: whether the fixed-term provision applies needs legal review.');
});

it('shows, never applies, the Code\'s 50% wage rule once a Code version is in force', function () {
    // Stand v3 up as verified and in force (in production only RuleVerifications::verify() does this).
    DB::table('compliance_rules')->where('code', 'GRATUITY')->where('version', 3)->update(['status' => ComplianceRule::ACTIVE, 'verification_status' => ComplianceRule::VERIFIED]);
    app(ComplianceRules::class)->forget();
    $employee = ($this->joined)('2018-04-01');

    $low = ($this->onExit)($employee, '2026-10-27', 'resignation', 20000, 50000);
    expect($low->status)->toBe(GratuityDetermination::PENDING)->and($low->decisive)->toBeTrue()
        ->and($low->amount)->toBe(103846.15) // 9 years on ₹20,000
        ->and($low->reason)->toContain('wages would be at least 25,000.00, giving 129,807.69')->toContain('owner decision C6')
        ->and($low->payable())->toBeFalse();

    $even = ($this->onExit)($employee, '2026-10-27', 'resignation', 20000, 30000);
    expect($even->status)->toBe(GratuityDetermination::ELIGIBLE)->and($even->payable())->toBeTrue()
        ->and($even->paymentDue)->toContain('Code on Social Security, 2020 s.56(3)');
});

it('requires a covered establishment, counted on its busiest day and covered once covered', function () {
    $office = Establishment::query()->create(['company_id' => $this->company->id, 'legal_entity_id' => $this->establishment->legal_entity_id, 'code' => 'BLR-2', 'name' => 'Bengaluru office',
        'state' => 'KA', 'country' => 'IN', 'establishment_type' => 'office', 'status' => 'active', 'effective_from' => '2020-01-01']);
    $subject = ($this->joined)('2018-04-01', $office);
    expect(($this->onExit)($subject, '2026-10-27')->status)->toBe(GratuityDetermination::NOT_COVERED)
        ->and(($this->onExit)($subject, '2026-10-27')->reason)->toContain('at most 1 employee(s)')->toContain('contract labour');

    // Eight more at the office, and others at the factory, which do not count for the office.
    foreach (range(1, 8) as $_) {
        ($this->joined)('2024-01-01', $office);
    }
    ($this->joined)('2024-01-01');
    expect($this->gratuity->coverage($office, '2026-10-27'))->toMatchArray(['status' => 'not_covered', 'headcount_max' => 9, 'threshold' => 10]);

    // A tenth for four months in 2025: ten on one day makes it covered, and it stays covered after (s.1(8)).
    $tenth = ($this->joined)('2025-05-01', $office);
    forceLifecycle($tenth, LifecycleState::Exited, ['exit_date' => '2025-08-31']);
    expect($this->gratuity->coverage($office, '2026-10-27'))->toMatchArray(['status' => 'covered', 'headcount_max' => 10, 'headcount_preceding_12_months' => 9])
        ->and($this->gratuity->coverage($office, '2025-04-30')['status'])->toBe('not_covered')
        ->and(($this->onExit)($subject, '2026-10-27')->status)->toBe(GratuityDetermination::LEGALLY_UNVERIFIED);

    // No establishment recorded: never the company's primary one.
    $unassigned = forceLifecycle(activeEmployee(), LifecycleState::Active, ['joining_date' => '2018-04-01']);
    $g = ($this->onExit)($unassigned->refresh(), '2025-10-31');
    expect($g->status)->toBe(GratuityDetermination::PENDING)->and($g->reason)->toContain('primary establishment is not assumed');
});

it('adds gratuity to the full and final only when the determination is decisive, and never twice', function () {
    $this->travelTo('2025-10-01 09:00:00');
    $employee = salariedEmployee(600000); // basic ₹20,000 a month
    forceLifecycle($employee, LifecycleState::Active, ['joining_date' => '2018-04-01']);
    app(EstablishmentAssignments::class)->assign($employee, $this->establishment, '2020-01-01', 'Test assignment');
    $case = app(Exits::class)->initiate($employee->refresh(), 'resignation', 'Relocating', '2025-10-01', '2025-10-31', 30, $this->hr);
    $settlements = app(FinalSettlements::class);

    $settlement = $settlements->calculate($case, $this->hr);
    $line = $settlement->lines->firstWhere('code', 'GRATUITY');
    expect((float) $line->amount)->toBe(92307.69)
        ->and($line->basis['rule'])->toContain('v1')
        ->and($settlement->inputs['gratuity']['status'])->toBe(GratuityDetermination::ELIGIBLE)
        ->and(GratuityDetermination::describe($settlement->inputs['gratuity']))->toContain('Amount: 92,307.69');

    $settlements->addLine($settlement, 'earning', 'Gratuity (as settled with the employee)', 95000, 'Agreed');
    $settlement = $settlements->calculate($case, $this->hr);
    expect($settlement->lines->where('code', 'GRATUITY'))->toHaveCount(0)
        ->and($settlement->inputs['gratuity']['manual_line'])->toContain('added by hand');
});

it('shows a provisional amount but adds no line when the version for the date is not in force', function () {
    $employee = salariedEmployee(600000);
    forceLifecycle($employee, LifecycleState::Active, ['joining_date' => '2018-04-01']);
    app(EstablishmentAssignments::class)->assign($employee, $this->establishment, '2020-01-01', 'Test assignment');
    $case = app(Exits::class)->initiate($employee->refresh(), 'retirement', 'Superannuation', '2026-10-27', '2026-10-31', 0, $this->hr);

    $settlement = app(FinalSettlements::class)->calculate($case, $this->hr);
    expect($settlement->lines->where('code', 'GRATUITY'))->toHaveCount(0)
        ->and($settlement->inputs['gratuity']['status'])->toBe(GratuityDetermination::LEGALLY_UNVERIFIED)
        ->and($settlement->inputs['gratuity']['provisional_amount'])->toBe(103846.15)
        ->and(implode(' ', GratuityDetermination::describe($settlement->inputs['gratuity'])))->toContain('Provisional amount (not paid automatically): 103,846.15')->toContain('Gratuity — Code on Social Security with the SS (Central) Rules, 2026 v3');
});
