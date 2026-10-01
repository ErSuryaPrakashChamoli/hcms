<?php

use App\Domain\Compliance\Models\ComplianceRule;
use App\Domain\Compliance\Models\ComplianceRuleNotice;
use App\Domain\Compliance\Services\ComplianceRules;
use App\Domain\Compliance\Services\RuleVerifications;
use App\Domain\Payroll\Models\PayrollEntry;
use App\Domain\Payroll\Models\PayrollPeriod;
use App\Domain\Payroll\Services\PayrollCalculator;
use App\Domain\Payroll\Services\PayrollRuns;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\Storage;

require_once __DIR__.'/../Workflow/WorkflowTestHelpers.php';
require_once __DIR__.'/../Payroll/PayrollTestHelpers.php';

/*
 | Phase 7 controlled statutory updates: EPFO wage ceiling from 17 Sep 2026 (EPF v2) and salary TDS
 | under the Income-tax Act, 2025 from 1 Apr 2026 (TDS v3, chosen by payment date). Both are DRAFT /
 | REVIEW and cannot be verified on the evidence available; nothing here verifies them.
 */

beforeEach(function () {
    Storage::fake('local');
    $this->travelTo('2026-10-05 09:00:00');
    syncComplianceRules();
    $this->rules = app(ComplianceRules::class);
    $this->tenant = provisionTenant();
    actAsTenant($this->tenant);
    $this->preparer = tenantUser($this->tenant, ['payroll.*', 'employee.*']);
    $this->approver = tenantUser($this->tenant, ['payroll.*', 'employee.*']);
    $this->actingAs($this->preparer);
    $this->company = payrollCompany();
    $this->employee = salariedEmployee(600000); // basic 20,000
    $this->calc = fn (int $year, int $month, ?string $paid = null) => app(PayrollCalculator::class)->calculate($this->employee, tap(PayrollPeriod::for($this->company, $year, $month), fn ($p) => $paid ? $p->update(['payment_date' => $paid]) : null)->refresh());
    $this->line = fn ($c, string $code) => collect($c->lines)->firstWhere('code', $code);
});

it('keeps EPF v1 (₹15,000) before 17 Sep 2026 and resolves EPF v2 (₹25,000) from that date', function () {
    $v1 = ComplianceRule::query()->where('code', 'EPF')->where('version', 1)->sole();
    $v2 = ComplianceRule::query()->where('code', 'EPF')->where('version', 2)->sole();

    expect($v1->param('wage_ceiling'))->toBe(15000)->and($v1->effective_to)->toBeNull()->and($v1->checksumIntact())->toBeTrue()
        ->and($v2->param('wage_ceiling'))->toBe(25000)->and($v2->param('eps_wage_ceiling'))->toBe(25000)
        ->and($v2->effective_from->toDateString())->toBe('2026-09-17')
        ->and($v2->verification_status)->toBe('review')
        ->and($v2->evidenceDocuments()->pluck('sha256')->all())->toContain('a31038ee4fbc5541515336ebb245ab94194d224c9807a4dc7bdd3968e46e0677');

    foreach (['2026-09-16' => 1, '2026-09-17' => 2, '2026-09-30' => 2, '2026-10-01' => 2] as $day => $version) {
        expect($this->rules->resolve('EPF', $day)->version)->toBe($version);
    }
});

it('binds historical payroll to the old rule and new payroll to the new rule, flagging September', function () {
    $august = ($this->calc)(2026, 8);
    $september = ($this->calc)(2026, 9);
    $october = ($this->calc)(2026, 10);

    expect(($this->line)($august, 'PF_EE')['basis']['rule_version'])->toBe(1)
        ->and(($this->line)($august, 'PF_EE')['amount'])->toBe(1800.0)              // 12% of the ₹15,000 ceiling
        // August is not touched by the EPF change; it is flagged only by the Phase 8 TDS v3 notice (from 1 Apr 2026).
        ->and(collect($august->exceptions)->where('type', 'statutory_change_pending')->pluck('message')->implode(' '))->not->toContain('EPF')->toContain('TDS v3')
        ->and(($this->line)($september, 'PF_EE')['basis']['rule_version'])->toBe(2)
        ->and(($this->line)($september, 'PF_EE')['amount'])->toBe(2400.0)           // basic 20,000 within ₹25,000
        ->and(collect($september->exceptions)->where('type', 'statutory_change_pending')->pluck('message')->implode(' '))->toContain('September 2026 intra-month treatment')
        ->and(($this->line)($october, 'PF_EE')['basis']['rule_version'])->toBe(2)
        ->and(collect($october->exceptions)->pluck('type'))->toContain('statutory_change_pending', 'unverified_statutory_rule');

    // A finalized August run keeps v1 after v2 exists; it cannot be recalculated.
    $runs = app(PayrollRuns::class);
    $run = $runs->finalize($runs->approve($runs->validate($runs->calculate($runs->open($this->company, 2026, 8, $this->preparer), $this->preparer)), $this->approver, 'ok'), $this->approver);
    $epfRef = collect($run->rule_versions)->firstWhere('rule_code', 'EPF');
    expect($epfRef['rule_version'])->toBe(1)
        ->and(fn () => $runs->calculate($run, $this->preparer))->toThrow(RuntimeException::class, 'Reopen it first');
});

it('blocks EPF verification: carried-forward parameters are not confirmed and the September treatment is open', function () {
    $v2 = ComplianceRule::query()->where('code', 'EPF')->where('version', 2)->sole();
    $gaps = app(RuleVerifications::class)->coverageGaps($v2);

    // Phase 8: the wage ceiling is covered by S.O. 5109(E) itself. Phase 9: the 2026 Schemes cover the
    // rates and rounding; the EDLI ceiling, administrative charges and the EPS rate wording stay open.
    expect($gaps)->toContain('edli_wage_ceiling not confirmed', 'admin_minimum not confirmed', 'admin_rate not confirmed', 'eps_rate not confirmed')->not->toContain('wage_ceiling not confirmed', 'round not confirmed')
        ->and(ComplianceRuleNotice::query()->where('code', 'EPF')->where('status', 'open')->count())->toBe(3);
    expect(fn () => app(RuleVerifications::class)->verify($v2, platformAdmin(), 'Looks right'))->toThrow(RuntimeException::class, 'Parameter coverage is incomplete');

    config(['peopleos.payroll.enforce_verified_rules' => true]);
    $this->rules->forget();
    expect(($this->calc)(2026, 9)->blocking())->toBeTrue();
});

it('corrects a rule by a new version, never by editing it', function () {
    $v2 = ComplianceRule::query()->where('code', 'EPF')->where('version', 2)->sole();
    expect(fn () => $v2->update(['parameters' => ['wage_ceiling' => 30000] + $v2->payload()]))->toThrow(RuntimeException::class, 'immutable');

    $v3 = app(TenantContext::class)->bypass(fn () => app(RuleVerifications::class)->publishCorrection($v2, ['edli_wage_ceiling' => 25000] + $v2->payload(), '2026-09-17', null, 'EDLI ceiling per the gazette text', platformAdmin()));
    expect($v3->version)->toBe(3)->and($v3->corrects_rule_id)->toBe($v2->id)->and($v2->refresh()->checksumIntact())->toBeTrue()
        ->and($v2->param('edli_wage_ceiling'))->toBe(15000);
});

it('applies the Income-tax Act by salary payment date (cases A–D)', function () {
    $tds = fn ($c) => $c->inputs['tax'];   // present even when the month's TDS is nil

    // A / C: March 2026 salary paid 31-Mar-2026 → Income-tax Act, 1961 (TDS v1, FY 2025-26).
    $march = ($this->calc)(2026, 3, '2026-03-31');
    expect($tds($march)['rule_version'])->toBe(1)->and($tds($march)['financial_year'])->toBe('2025-26')->and($tds($march)['legal_basis'])->toBeNull();

    // B: March 2026 salary paid 01-Apr-2026 → Income-tax Act, 2025, s.392(1), tax year 2026-27.
    $paidLate = ($this->calc)(2026, 3, '2026-04-01');
    expect($tds($paidLate)['rule_version'])->toBe(3)->and($tds($paidLate)['financial_year'])->toBe('2026-27')
        ->and($tds($paidLate)['legal_basis']['act'])->toBe('Income-tax Act, 2025')->and($tds($paidLate)['legal_basis']['section'])->toBe('392(1)')
        ->and($tds($paidLate)['months_remaining'])->toBe(12);   // computation reset for the new tax year

    // D: April 2026 salary paid 30-Apr-2026 → new Act.
    $april = ($this->calc)(2026, 4, '2026-04-30');
    expect($tds($april)['rule_version'])->toBe(3)->and($tds($april)['payment_date'])->toBe('2026-04-30');
});

it('keeps historical TDS reproducible and corrects the tax rule only by a new version (cases E–F)', function () {
    $v2 = ComplianceRule::query()->where('code', 'TDS')->where('version', 2)->sole();
    $v3 = ComplianceRule::query()->where('code', 'TDS')->where('version', 3)->sole();

    expect($v3->corrects_rule_id)->toBe($v2->id)->and($v3->correction_reason)->toContain('392(1)')
        ->and($v3->param('legal_basis.section'))->toBe('392(1)')->and($v3->param('deduction_trigger'))->toBe('payment_date')
        ->and($v2->refresh()->verification_status)->toBe('draft')->and($v2->checksumIntact())->toBeTrue()
        ->and(app(RuleVerifications::class)->coverageGaps($v3))->toEqualCanonicalizing(['old not confirmed', 'new not confirmed', 'surcharge_new_regime_cap not confirmed']);

    // E: a finalized March 2026 run stays on the 1961-Act rule even after v3 exists.
    $runs = app(PayrollRuns::class);
    PayrollPeriod::for($this->company, 2026, 3)->update(['payment_date' => '2026-03-31']);
    $run = $runs->finalize($runs->approve($runs->validate($runs->calculate($runs->open($this->company, 2026, 3, $this->preparer), $this->preparer)), $this->approver, 'ok'), $this->approver);
    $entry = PayrollEntry::query()->where('payroll_run_id', $run->id)->sole();
    expect(collect($run->rule_versions)->firstWhere('rule_code', 'TDS')['rule_version'])->toBe(1)
        ->and($entry->inputs['tax']['financial_year'])->toBe('2025-26');
    expect(fn () => $runs->setPaymentDate($run, '2026-04-02', 'Try to move it'))->toThrow(RuntimeException::class, 'cannot change');

    // An editable run's payment date change returns it to draft for recalculation, audited.
    $april = $runs->calculate($runs->open($this->company, 2026, 4, $this->preparer), $this->preparer);
    $april = $runs->setPaymentDate($april, '2026-05-02', 'Paid on the 2nd', $this->preparer);
    expect($april->status)->toBe('draft')->and($april->period->payment_date->toDateString())->toBe('2026-05-02');

    // The unverified tax rule blocks production finalization.
    config(['peopleos.payroll.enforce_verified_rules' => true]);
    $this->rules->forget();
    expect(($this->calc)(2026, 4, '2026-04-30')->blocking())->toBeTrue();
});

it('exposes the rule reference needed to reproduce every statutory line', function () {
    $c = ($this->calc)(2026, 9);

    foreach (['PF_EE', 'TDS'] as $code) {
        expect(($this->line)($c, $code)['basis'])->toHaveKeys(['rule_id', 'rule_code', 'rule_version', 'effective_from', 'effective_to', 'verification_status', 'rule_checksum', 'evidence_reference']);
    }
    expect(PayrollCalculator::VERSION)->toBe('payroll-2.2');
});
