<?php

use App\Domain\Compliance\Models\ComplianceRule;
use App\Domain\Compliance\Models\ComplianceRuleNotice;
use App\Domain\Compliance\Models\ComplianceRuleParameter;
use App\Domain\Compliance\Models\ComplianceRuleVerification;
use App\Domain\Compliance\Services\ComplianceRules;
use App\Domain\Compliance\Services\RuleVerifications;
use Illuminate\Support\Facades\Storage;

require_once __DIR__.'/../Workflow/WorkflowTestHelpers.php';
require_once __DIR__.'/../Payroll/PayrollTestHelpers.php';

/*
 | Phase 8 statutory evidence reconciliation: the gazette S.O. 5109(E), EPFO's circular and FAQs, the
 | Income-tax Act, 2025 text and the ITD transition FAQs are recorded as append-only evidence
 | revisions of EPF v2 and TDS v3. Nothing is verified, no notice is resolved, enforcement is unchanged.
 */

beforeEach(function () {
    Storage::fake('local');
    $this->travelTo('2026-10-05 09:00:00');
    syncComplianceRules();
    $this->epf = ComplianceRule::query()->where('code', 'EPF')->where('version', 2)->sole();
    $this->tds = ComplianceRule::query()->where('code', 'TDS')->where('version', 3)->sole();
    $this->latest = fn (ComplianceRule $rule) => $rule->verifications()->where('action', 'submitted')->reorder()->latest('id')->first();
    $this->coverage = fn (ComplianceRule $rule) => ComplianceRuleParameter::query()->where('compliance_rule_verification_id', ($this->latest)($rule)->id)->get()->keyBy('parameter');
});

it('records the Phase 8 evidence as an append-only revision, once, keeping the Phase 7 submission', function () {
    foreach ([$this->epf, $this->tds] as $rule) {
        $submissions = $rule->verifications()->where('action', 'submitted')->reorder()->orderBy('id')->get();
        expect($submissions)->toHaveCount(2)
            ->and($submissions->last()->actor_label)->toBe('pack:in.php#phase-8-2026-10-01')
            ->and($rule->refresh()->verification_status)->toBe(ComplianceRule::REVIEW);
    }

    syncComplianceRules(); // idempotent
    expect(ComplianceRuleVerification::query()->where('actor_label', 'like', '%phase-8%')->count())->toBe(2)
        ->and(ComplianceRuleVerification::query()->whereIn('action', ['verified', 'superseded'])->count())->toBe(0);
});

it('covers the EPF wage ceiling from the gazette and records contradicted carried-forward values', function () {
    $coverage = ($this->coverage)($this->epf);

    expect($coverage['wage_ceiling']->status)->toBe(ComplianceRuleParameter::COVERED)
        ->and($coverage['wage_ceiling']->requirement_excerpt)->toContain('rupees twenty-five thousand (₹25,000) per month as the wage ceiling for the purposes of Chapter III')
        ->and($coverage['edli_wage_ceiling']->status)->toBe(ComplianceRuleParameter::NOT_CONFIRMED)
        ->and($coverage['edli_wage_ceiling']->note)->toContain('CONTRADICTED')
        ->and($coverage['admin_minimum']->note)->toContain('₹500')
        ->and($this->epf->param('edli_wage_ceiling'))->toBe(15000) // not changed on an explanatory FAQ
        ->and($this->epf->evidenceDocuments()->pluck('sha256')->all())->toContain(
            '970c2ea088c808a2427f630ba344ab8cfc7a26a10ba9babee2f150b0451c70df', // S.O. 5109(E)
            '47c22e56faaf6735bb8bd0b70db502e2f86efd4adb654cc4593ff85be6eb1052', // EPFO FAQs
        )
        ->and(app(RuleVerifications::class)->coverageGaps($this->epf))->toHaveCount(9)
        ->and(fn () => app(RuleVerifications::class)->verify($this->epf, platformAdmin(), 'Gazette retrieved'))->toThrow(RuntimeException::class);

    $notice = ComplianceRuleNotice::query()->where('code', 'EPF')->where('status', 'open')->whereJsonLength('affects_versions', 1)->whereJsonContains('affects_versions', 2)->sole();
    expect($notice->summary)->toContain('day-proportionate wages')->toContain('applies the version effective on the period end to the whole wage month')
        ->and(ComplianceRuleNotice::query()->where('code', 'EPF')->where('status', 'open')->count())->toBe(3);
});

it('cites the salary-specific TDS rule and the Finance Act section 3, keeping open questions as gaps', function () {
    $coverage = ($this->coverage)($this->tds);

    expect($coverage['deduction_trigger']->status)->toBe(ComplianceRuleParameter::COVERED)
        ->and($coverage['deduction_trigger']->requirement_excerpt)->toContain('based on the date of payment of salary')->toContain('not the general')
        ->and($coverage['legal_basis']->requirement_excerpt)->toContain('s.392(1)')->toContain('s.3(10)(ii)')
        ->and($coverage['cess_rate']->requirement_excerpt)->toContain('s.3(16)')
        ->and($coverage['new']->status)->toBe(ComplianceRuleParameter::NOT_CONFIRMED)
        ->and($coverage['new']->note)->toContain('s.202(1)')->toContain('rates in force')
        ->and($coverage['old']->note)->toContain('1961')
        ->and(app(RuleVerifications::class)->coverageGaps($this->tds))->toEqualCanonicalizing(['new not confirmed', 'old not confirmed', 'surcharge_new_regime_cap not confirmed'])
        ->and(ComplianceRuleNotice::query()->where('code', 'TDS')->where('status', 'open')->whereJsonContains('affects_versions', 3)->exists())->toBeTrue()
        ->and(fn () => app(RuleVerifications::class)->verify($this->tds, platformAdmin(), 'Act retrieved'))->toThrow(RuntimeException::class);

    // Salary TDS still resolves by payment date; nothing about the engine changed.
    expect(app(ComplianceRules::class)->resolve('TDS', '2026-03-31')->version)->toBe(1)
        ->and(app(ComplianceRules::class)->resolve('TDS', '2026-04-01')->version)->toBe(3);
});

it('refuses evidence documents whose bytes do not match their recorded SHA-256', function () {
    $path = database_path('data/compliance/evidence/epf/s-o-5109e-gazette-276299-2026-09-17.pdf');
    expect(hash_file('sha256', $path))->toBe('970c2ea088c808a2427f630ba344ab8cfc7a26a10ba9babee2f150b0451c70df')
        ->and(hash_file('sha256', database_path('data/compliance/evidence/tds/income-tax-act-2025-as-amended-fa-2026-itd.pdf')))->toBe('d54a0ed6a91673d1a4fbcef9b5e472c3efe441139fae38f85a5a0c5b2cfc998b')
        ->and(hash_file('sha256', database_path('data/compliance/evidence/tds/itd-faqs-interplay-transition-2026.pdf')))->toBe('0b21c7063d19a81c91b5e2a8911458c003589c2a023c262954dea7fb968917ce');
});
