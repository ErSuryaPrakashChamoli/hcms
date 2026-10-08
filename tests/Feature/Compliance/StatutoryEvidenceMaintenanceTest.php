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
 | Phase 9 statutory evidence maintenance: the EPF / EPS / EDLI Schemes, 2026, the rate notifications,
 | their corrigenda, S.O. 2702(E) and the Income-tax Rules, 2026 are recorded as a further evidence
 | revision of EPF v2 and TDS v3. No payload changes, no new version, nothing verified, no notice
 | resolved or added, payroll unchanged: 24 versions, 0 verified, 5 open notices.
 */

beforeEach(function () {
    Storage::fake('local');
    $this->travelTo('2026-10-05 09:00:00');
    syncComplianceRules();
    $this->epf = ComplianceRule::query()->where('code', 'EPF')->where('version', 2)->sole();
    $this->tds = ComplianceRule::query()->where('code', 'TDS')->where('version', 3)->sole();
    $this->phase9 = fn (ComplianceRule $rule) => $rule->verifications()->where('action', 'submitted')->where('actor_label', 'pack:in.php#phase-9-2026-10-01')->sole();
    $this->coverage = fn (ComplianceRule $rule) => ComplianceRuleParameter::query()->where('compliance_rule_verification_id', ($this->phase9)($rule)->id)->get()->keyBy('parameter');
});

it('keeps the statutory status: 24 versions, none verified, the same 5 open notices, enforcement untouched', function () {
    expect(ComplianceRule::query()->count())->toBe(24)
        ->and(ComplianceRule::query()->where('verification_status', ComplianceRule::VERIFIED)->count())->toBe(0)
        ->and(ComplianceRuleNotice::query()->where('status', ComplianceRuleNotice::OPEN)->count())->toBe(5)
        ->and($this->epf->refresh()->verification_status)->toBe(ComplianceRule::REVIEW)
        ->and($this->tds->refresh()->verification_status)->toBe(ComplianceRule::REVIEW);

    syncComplianceRules(); // idempotent: the revision is submitted once
    expect(ComplianceRuleVerification::query()->where('actor_label', 'pack:in.php#phase-9-2026-10-01')->where('action', 'submitted')->count())->toBe(2)
        ->and(ComplianceRuleVerification::query()->whereIn('action', ['verified', 'superseded'])->count())->toBe(0)
        ->and(fn () => app(RuleVerifications::class)->verify($this->epf, platformAdmin(), 'Schemes retrieved'))->toThrow(RuntimeException::class)
        ->and(fn () => app(RuleVerifications::class)->verify($this->tds, platformAdmin(), 'Rules retrieved'))->toThrow(RuntimeException::class);
});

it('records what the 2026 Schemes establish for EPF v2 without changing its values or deciding conflicts', function () {
    $coverage = ($this->coverage)($this->epf);

    foreach (['wage_ceiling', 'eps_wage_ceiling', 'employee_rate', 'employer_rate', 'edli_rate', 'round'] as $covered) {
        expect($coverage[$covered]->status)->toBe(ComplianceRuleParameter::COVERED, "{$covered} should be covered");
    }
    expect($coverage['employee_rate']->requirement_excerpt)->toContain('twelve per cent')->toContain('not modelled')
        ->and($coverage['round']->requirement_excerpt)->toContain('fifty paise or more to be counted as the next higher rupee')
        ->and($coverage['edli_rate']->requirement_excerpt)->toContain('one-half per cent')
        // Conflicts are recorded for a qualified reviewer, not decided.
        ->and($coverage['edli_wage_ceiling']->status)->toBe(ComplianceRuleParameter::NOT_CONFIRMED)
        ->and($coverage['edli_wage_ceiling']->note)->toContain('CONTRADICTED BY NOTIFIED TEXT')->toContain('clause (89)')
        ->and($coverage['eps_rate']->note)->toContain('TWO NOTIFIED TEXTS DIFFER')->toContain('eight and one-third')
        ->and($coverage['admin_rate']->status)->toBe(ComplianceRuleParameter::NOT_CONFIRMED)
        ->and($coverage['admin_minimum']->note)->toContain('late fee')
        ->and(app(RuleVerifications::class)->coverageGaps($this->epf))->toEqualCanonicalizing(['eps_rate not confirmed', 'edli_wage_ceiling not confirmed', 'admin_rate not confirmed', 'admin_minimum not confirmed'])
        ->and($this->epf->param('edli_wage_ceiling'))->toBe(15000)
        ->and($this->epf->param('eps_rate'))->toBe(0.0833)
        ->and($this->epf->checksumIntact())->toBeTrue()
        ->and($this->epf->evidenceDocuments()->pluck('sha256')->all())->toContain(
            '4e062db5bf5d8b904ae1c0d4af10950dc01de7df8360398dfe197d6d06aef489', // EPF Scheme, 2026
            'a4a61bcf182dcaab026ad49ab50044d088f91930b9967494ac559054daecc957', // EDLI Scheme, 2026
            '6bd9d6fb82a1e0e6efcc6dff3901485aaf101dd621a68b8592409585e18a6592', // EPS, 2026
            '62dbc6c22949eed3ccd0cde11488312c4db4480623078254a558770d14cb3893', // S.O. 2702(E)
        );

    // The September split-period notice stays open, and payroll still applies one version per month.
    expect(ComplianceRuleNotice::query()->where('code', 'EPF')->where('status', 'open')->count())->toBe(3)
        ->and(($this->phase9)($this->epf)->requirement_text)->toContain('wages actually drawn or payable during the month')
        ->and(($this->phase9)($this->epf)->source_url)->toStartWith('https://egazette.gov.in/');
});

it('attaches the Income-tax Rules, 2026 to TDS v3 and leaves the three open questions blocked', function () {
    $coverage = ($this->coverage)($this->tds);

    expect($coverage['deduction_trigger']->requirement_excerpt)->toContain('based on the date of payment of salary')
        ->and($coverage['legal_basis']->requirement_excerpt)->toContain('rules 204, 205, 215 and 219')
        ->and($coverage['new']->note)->toContain('prescribe no rates')
        ->and($coverage['old']->note)->toContain('No mapping is inferred')
        ->and($coverage['surcharge_new_regime_cap']->note)->toContain('Surcharge, wherever applicable')
        ->and(app(RuleVerifications::class)->coverageGaps($this->tds))->toEqualCanonicalizing(['new not confirmed', 'old not confirmed', 'surcharge_new_regime_cap not confirmed'])
        ->and($this->tds->evidenceDocuments()->pluck('sha256')->all())->toContain('f565e0f5ff3bf8f717daeb507f5e8569e720abc3309e6719f6f085889c9b802f')
        ->and(ComplianceRule::query()->where('code', 'TDS')->count())->toBe(3) // no new version
        ->and(app(ComplianceRules::class)->resolve('TDS', '2026-04-01')->version)->toBe(3);
});

it('ships evidence files whose bytes match their recorded SHA-256', function () {
    foreach ([
        'epf/epf-scheme-2026-gsr-525e-gazette-273957.pdf' => '4e062db5bf5d8b904ae1c0d4af10950dc01de7df8360398dfe197d6d06aef489',
        'epf/edli-scheme-2026-gsr-526e-gazette-273942.pdf' => 'a4a61bcf182dcaab026ad49ab50044d088f91930b9967494ac559054daecc957',
        'epf/eps-2026-gsr-527e-gazette-273951.pdf' => '6bd9d6fb82a1e0e6efcc6dff3901485aaf101dd621a68b8592409585e18a6592',
        'epf/s-o-3580e-eps-rate-gazette-274111.pdf' => '893a07e9f802efee5809d9d3f6f6265808f6199dcbe360587f3b99584bbf4915',
        'epf/s-o-3581e-edli-rate-gazette-274104.pdf' => '17dd4c74680ec21fe1bdf03d662341e59c67556cf9f3d12464a2b00abd5cfa27',
        'epf/s-o-3582e-epf-rate-gazette-274112.pdf' => '9dfc45128ff76a5a08e599d7d17b3ff08b8d4f9da1c3fd0180e4e217d9050a79',
        'epf/s-o-2702e-gazette-273002-2026-05-29.pdf' => '62dbc6c22949eed3ccd0cde11488312c4db4480623078254a558770d14cb3893',
        'tds/income-tax-rules-2026-gsr-198e-itd.pdf' => 'f565e0f5ff3bf8f717daeb507f5e8569e720abc3309e6719f6f085889c9b802f',
    ] as $path => $sha256) {
        expect(hash_file('sha256', database_path("data/compliance/evidence/{$path}")))->toBe($sha256, $path);
    }
});

it('keeps every pack evidence field within the MySQL column limits (SQLite does not enforce them)', function () {
    $limits = ['source_title' => 255, 'evidence_reference' => 255, 'source_url' => 500, 'authority' => 32];
    $tooLong = [];
    foreach (require database_path('data/compliance/in.php') as $definition) {
        $submissions = array_merge(isset($definition['evidence']) ? [['revision' => 'initial', 'evidence' => $definition['evidence']]] : [], $definition['evidence_revisions'] ?? []);
        foreach ($submissions as $submission) {
            foreach ($limits as $field => $max) {
                if (mb_strlen((string) ($submission['evidence'][$field] ?? '')) > $max) {
                    $tooLong[] = "{$definition['code']} v{$definition['version']} {$submission['revision']}: {$field}";
                }
            }
            expect(mb_strlen('pack:in.php#'.$submission['revision']))->toBeLessThanOrEqual(128);
        }
    }
    expect($tooLong)->toBe([]);
});
