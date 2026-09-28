<?php

use App\Domain\Compliance\Models\ComplianceEvidenceDocument;
use App\Domain\Compliance\Models\ComplianceRule;
use App\Domain\Compliance\Models\ComplianceRuleNotice;
use App\Domain\Compliance\Models\ComplianceRuleParameter;
use App\Domain\Compliance\Services\ComplianceRules;
use App\Domain\Compliance\Services\RuleVerifications;
use App\Domain\Payroll\Models\PayrollPeriod;
use App\Domain\Payroll\Services\PayrollCalculator;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\Storage;

require_once __DIR__.'/../Workflow/WorkflowTestHelpers.php';
require_once __DIR__.'/../Payroll/PayrollTestHelpers.php';

/* Phase 6.1: evidence documents, parameter coverage, corrections, regulatory notices, production gate. */

beforeEach(function () {
    Storage::fake('local');
    $this->travelTo('2026-10-05 09:00:00');
    syncComplianceRules();
    $this->workflow = app(RuleVerifications::class);
    $this->submitter = platformAdmin();
    $this->reviewer = platformAdmin();
    $this->epf = ComplianceRule::query()->where('code', 'EPF')->where('version', 1)->sole();
    $this->pt = ComplianceRule::query()->where('code', 'PT')->where('state', 'KA')->sole();
    $this->evidence = fn (ComplianceRule $rule, array $mapping = []) => [
        'source_url' => 'https://www.example.gov.in/notification.pdf', 'source_title' => 'Official notification', 'effective_date' => $rule->effective_from->toDateString(),
        'retrieved_at' => '2026-09-28', 'requirement_text' => 'Quoted requirement',
        'mapping' => $mapping + array_fill_keys(array_keys($rule->payload()), 'Quoted clause'),
    ];
    $this->attach = fn (ComplianceRule $rule, $by = null) => $this->workflow->attachEvidence($rule, '%PDF-1.4 evidence '.$rule->id.uniqid(), 'notification.pdf', '2026-09-28', 'https://www.example.gov.in/notification.pdf', $by ?? $this->submitter);
    $this->bypass = fn (callable $fn) => app(TenantContext::class)->bypass($fn);
});

it('loads the EPF and TDS regulatory notices from official evidence and keeps them immutable', function () {
    $notices = ComplianceRuleNotice::query()->orderBy('code')->get();

    expect($notices->pluck('code')->all())->toBe(['EPF', 'EPF', 'TDS'])
        ->and($notices->firstWhere('code', 'EPF')->effective_date->toDateString())->toBe('2026-09-17')
        ->and($notices->firstWhere('code', 'EPF')->references[0]['sha256'])->toBe('a31038ee4fbc5541515336ebb245ab94194d224c9807a4dc7bdd3968e46e0677')
        ->and($notices->every(fn ($n) => $n->status === 'open'))->toBeTrue();
    expect(fn () => $notices->first()->update(['summary' => 'edited']))->toThrow(RuntimeException::class, 'immutable');

    syncComplianceRules();
    expect(ComplianceRuleNotice::query()->count())->toBe(3);
});

it('refuses to verify a version affected by an open notice, even with complete evidence', function () {
    $this->workflow->submit($this->epf, ($this->evidence)($this->epf), $this->submitter);
    ($this->attach)($this->epf);

    expect(fn () => $this->workflow->verify($this->epf, $this->reviewer, 'Looks fine'))->toThrow(RuntimeException::class, 'open regulatory notice');
    expect($this->epf->refresh()->verification_status)->toBe('review');
});

it('flags payroll on or after a notice date, blocking under enforcement', function () {
    $tenant = provisionTenant();
    actAsTenant($tenant);
    $this->actingAs(tenantUser($tenant, ['*']));
    $company = payrollCompany();
    $employee = salariedEmployee(600000);
    $calc = fn (int $month) => app(PayrollCalculator::class)->calculate($employee, PayrollPeriod::for($company, 2026, $month));

    $august = collect($calc(8)->exceptions)->where('type', 'statutory_change_pending');
    $september = collect($calc(9)->exceptions)->where('type', 'statutory_change_pending');
    expect($august->pluck('message')->implode(' '))->not->toContain('EPF')          // before 17 Sep 2026 EPF v1 is fine
        ->and($september->pluck('message')->implode(' '))->toContain('Employees Provident Fund')
        ->and($september->pluck('message')->implode(' '))->toContain('September 2026 intra-month treatment')
        ->and($calc(9)->inputs['tax']['legal_basis']['section'])->toBe('392(1)')   // TDS resolves v3 (Income-tax Act, 2025), not the affected v2
        ->and($september->pluck('blocking')->filter()->all())->toBe([]);

    config(['peopleos.payroll.enforce_verified_rules' => true]);
    app(ComplianceRules::class)->forget();
    expect($calc(9)->blocking())->toBeTrue();
});

it('resolves a notice only with a new, effective version published as a correction', function () {
    $notice = ComplianceRuleNotice::query()->where('code', 'EPF')->whereJsonLength('affects_versions', 1)->sole();

    expect(fn () => $this->workflow->publishCorrection($this->epf, $this->epf->payload(), '2026-09-17', null, '', $this->submitter))->toThrow(RuntimeException::class, 'reason');
    expect(fn () => $this->workflow->publishCorrection($this->epf, $this->epf->payload(), '2026-09-17', null, 'x', tenantUser(provisionTenant(), ['*'])))->toThrow(RuntimeException::class, 'platform administrators');

    $v2 = $this->workflow->publishCorrection($this->epf, ['wage_ceiling' => 25000] + $this->epf->payload(), '2026-09-17', null, 'Wage ceiling raised by S.O. 5109(E)', $this->submitter);
    expect($v2->version)->toBe(3) // the pack already holds EPF v2 (17 Sep 2026)
        ->and($v2->verification_status)->toBe('draft')
        ->and($v2->corrects_rule_id)->toBe($this->epf->id)
        ->and($v2->correction_reason)->toContain('5109(E)')
        ->and($this->epf->refresh()->verification_status)->toBe('draft');   // the corrected version is untouched
    expect(fn () => $v2->update(['correction_reason' => 'x']))->toThrow(RuntimeException::class, 'immutable');

    expect(fn () => $this->workflow->resolveNotice($notice, $this->epf, $this->submitter, 'x'))->toThrow(RuntimeException::class, 'not an affected one');
    $early = ($this->bypass)(fn () => $this->workflow->publishCorrection($this->epf, $this->epf->payload(), '2014-09-01', '2026-09-16', 'Close v1 period', $this->submitter));
    expect(fn () => $this->workflow->resolveNotice($notice, $early, $this->submitter, 'x'))->toThrow(RuntimeException::class, 'effective on 2026-09-17');

    $this->workflow->resolveNotice($notice, $v2, $this->submitter, 'EPF v2 authored from the gazette');
    expect($notice->refresh()->status)->toBe('resolved')->and($notice->resolved_by_rule_id)->toBe($v2->id);
    expect(fn () => $notice->update(['status' => 'open']))->toThrow(RuntimeException::class, 'only be resolved once');

    // The September 2026 treatment notice also affects v1 and must be resolved too (Phase 7).
    $september = ComplianceRuleNotice::query()->where('code', 'EPF')->where('status', 'open')->sole();
    expect(fn () => $this->workflow->verify($this->epf->refresh(), $this->reviewer, 'x'))->toThrow(RuntimeException::class);
    $this->workflow->resolveNotice($september, $v2, $this->submitter, 'September treatment established from the gazette');

    // With both notices resolved, EPF v1 can be verified for its own period on complete evidence.
    $this->workflow->submit($this->epf, ($this->evidence)($this->epf), $this->submitter);
    ($this->attach)($this->epf);
    expect($this->workflow->verify($this->epf, $this->reviewer, 'Verified for 2014-09-01 onward until v2')->verification_status)->toBe('verified');
});

it('requires parameter-level coverage: not confirmed blocks, not applicable needs a justification, covered needs an excerpt', function () {
    expect(fn () => $this->workflow->submit($this->pt, ($this->evidence)($this->pt, ['slabs' => ['status' => 'covered', 'excerpt' => '']]), $this->submitter))->toThrow(RuntimeException::class, 'without the requirement');

    $this->workflow->submit($this->pt, ($this->evidence)($this->pt, ['february_amount' => 'NOT CONFIRMED: the schedule page was not retrieved']), $this->submitter);
    ($this->attach)($this->pt);
    expect(ComplianceRuleParameter::query()->where('compliance_rule_id', $this->pt->id)->where('parameter', 'february_amount')->value('status'))->toBe('not_confirmed');
    expect(fn () => $this->workflow->verify($this->pt, $this->reviewer, 'x'))->toThrow(RuntimeException::class, 'february_amount not confirmed');

    $this->workflow->submit($this->pt, ($this->evidence)($this->pt, ['february_amount' => ['status' => 'not_applicable', 'note' => null]]), $this->submitter);
    expect(fn () => $this->workflow->verify($this->pt, $this->reviewer, 'x'))->toThrow(RuntimeException::class, 'without justification');

    $this->workflow->submit($this->pt, ($this->evidence)($this->pt), $this->submitter);
    expect($this->workflow->verify($this->pt, $this->reviewer, 'All parameters traced')->verification_status)->toBe('verified');
});

it('keeps evidence documents immutable and closed once a version is verified', function () {
    expect(fn () => $this->workflow->attachEvidence($this->pt, 'x', 'a.pdf', now()->addDays(2), null, $this->submitter))->toThrow(RuntimeException::class, 'future');

    $document = ($this->attach)($this->pt);
    expect($document->sha256)->toHaveLength(64)
        ->and(Storage::disk('local')->exists($document->path))->toBeTrue();
    expect(fn () => $document->update(['sha256' => 'x']))->toThrow(RuntimeException::class, 'immutable');
    expect(fn () => $document->delete())->toThrow(RuntimeException::class, 'never deleted');

    // The verifier cannot rely only on documents they uploaded themselves.
    $this->workflow->submit($this->epf, ($this->evidence)($this->epf), $this->submitter);
    $this->workflow->attachEvidence($this->epf, '%PDF reviewer copy', 'copy.pdf', '2026-09-28', null, $this->reviewer);
    expect(fn () => $this->workflow->verify($this->epf, $this->reviewer, 'x'))->toThrow(RuntimeException::class, 'uploaded themselves');

    $this->workflow->submit($this->pt, ($this->evidence)($this->pt), $this->submitter);
    $this->workflow->verify($this->pt, $this->reviewer, 'ok');
    expect(fn () => ($this->attach)($this->pt))->toThrow(RuntimeException::class, 'corrected version');
    expect(ComplianceEvidenceDocument::query()->where('compliance_rule_id', $this->pt->id)->count())->toBe(1);
});

it('forces verified-rule enforcement in production regardless of the development override', function () {
    config(['peopleos.payroll.enforce_verified_rules' => false]);
    expect(ComplianceRules::enforced())->toBeFalse();

    app()->detectEnvironment(fn () => 'production');
    try {
        expect(ComplianceRules::enforced())->toBeTrue();
    } finally {
        app()->detectEnvironment(fn () => 'testing');
    }
});
