<?php

use App\Domain\Audit\Models\AuditEvent;
use App\Domain\Audit\Services\AuditIntegrityVerifier;
use App\Domain\Compliance\Models\ComplianceRule;
use App\Domain\Compliance\Models\ComplianceRuleVerification;
use App\Domain\Compliance\Services\ComplianceRules;
use App\Domain\Compliance\Services\RuleVerifications;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

require_once __DIR__.'/../Payroll/PayrollTestHelpers.php';

beforeEach(function () {
    Storage::fake('local');
    syncComplianceRules();
    // These cases exercise the verification mechanics on EPF v1; the Phase 6 regulatory notice that
    // blocks EPF v1 is covered in RegulatoryReadinessTest, so it is closed here as test setup.
    DB::table('compliance_rule_notices')->update(['status' => 'resolved']);
    $this->workflow = app(RuleVerifications::class);
    $this->submitter = platformAdmin();
    $this->reviewer = platformAdmin();
    $this->epf = ComplianceRule::query()->where('code', 'EPF')->sole();
    $this->evidence = fn (ComplianceRule $rule, array $overrides = []) => $overrides + [
        'authority' => 'EPFO',
        'source_url' => 'https://www.epfindia.gov.in/site_docs/PDFs/Circulars/test.pdf',
        'source_title' => 'EPFO circular (test)',
        'source_published_date' => '2014-08-22',
        'effective_date' => '2014-09-01',
        'requirement_text' => 'Quoted requirement',
        'mapping' => array_fill_keys(array_keys($rule->payload()), 'mapped'),
    ];
    $this->bypass = fn (callable $fn) => app(TenantContext::class)->bypass($fn);
    $this->attach = fn (ComplianceRule $rule) => $this->workflow->attachEvidence($rule, '%PDF-1.4 official circular '.$rule->id, 'circular.pdf', '2026-09-28', 'https://www.epfindia.gov.in/x.pdf', $this->submitter);
});

it('records creation history and a checksum for every published version', function () {
    expect($this->epf->checksum)->toHaveLength(64)
        ->and($this->epf->checksumIntact())->toBeTrue()
        ->and($this->epf->verifications()->where('action', 'created')->exists())->toBeTrue()
        ->and(ComplianceRuleVerification::query()->where('action', 'submitted')->count())->toBe(1); // ESI, from official evidence in the pack
});

it('keeps rule versions immutable: no payload edits, no deletion, no pack rewrite', function () {
    expect(fn () => $this->epf->update(['parameters' => ['employee_rate' => 0.10] + $this->epf->parameters]))->toThrow(RuntimeException::class, 'immutable');
    expect(fn () => $this->epf->update(['effective_from' => '2015-01-01']))->toThrow(RuntimeException::class, 'immutable');
    expect(fn () => $this->epf->delete())->toThrow(RuntimeException::class, 'never deleted');

    // A stored version that differs from the pack is refused rather than overwritten.
    DB::table('compliance_rules')->where('id', $this->epf->id)->update(['checksum' => str_repeat('0', 64)]);
    expect(fn () => ($this->bypass)(fn () => app(ComplianceRules::class)->sync()))->toThrow(RuntimeException::class, 'Publish it as version 2');
});

it('accepts only official, complete evidence', function () {
    expect(fn () => $this->workflow->submit($this->epf, ($this->evidence)($this->epf, ['source_url' => 'https://www.some-payroll-vendor.com/epf-rates']), $this->submitter))
        ->toThrow(RuntimeException::class, 'not an official source');
    expect(fn () => $this->workflow->submit($this->epf, ($this->evidence)($this->epf, ['source_url' => 'http://epfindia.gov.in/x']), $this->submitter))
        ->toThrow(RuntimeException::class, 'not an official source');
    expect(fn () => $this->workflow->submit($this->epf, ($this->evidence)($this->epf, ['source_url' => 'https://epfindia.gov.in.evil.com/x']), $this->submitter))
        ->toThrow(RuntimeException::class, 'not an official source');
    expect(fn () => $this->workflow->submit($this->epf, ($this->evidence)($this->epf, ['mapping' => ['employee_rate' => 'x']]), $this->submitter))
        ->toThrow(RuntimeException::class, 'unmapped');
    expect(fn () => $this->workflow->submit($this->epf, ($this->evidence)($this->epf, ['requirement_text' => '']), $this->submitter))
        ->toThrow(RuntimeException::class, 'requirement_text');
    expect(fn () => $this->workflow->submit($this->epf, ($this->evidence)($this->epf), tenantUser(provisionTenant(), ['*'])))
        ->toThrow(RuntimeException::class, 'platform administrators');
    expect($this->epf->refresh()->verification_status)->toBe('draft');
});

it('verifies only through review, by a different platform administrator, and then freezes the evidence', function () {
    expect(fn () => $this->workflow->verify($this->epf, $this->reviewer, 'x'))->toThrow(RuntimeException::class, 'in review');

    $this->workflow->submit($this->epf, ($this->evidence)($this->epf), $this->submitter);
    expect($this->epf->refresh()->verification_status)->toBe('review')
        ->and($this->epf->source_url)->toContain('epfindia.gov.in');

    expect(fn () => $this->workflow->verify($this->epf, $this->reviewer, 'no document'))->toThrow(RuntimeException::class, 'evidence document');
    ($this->attach)($this->epf);
    expect(fn () => $this->workflow->verify($this->epf, $this->submitter, 'self'))->toThrow(RuntimeException::class, 'cannot verify');
    expect(fn () => $this->workflow->verify($this->epf, tenantUser(provisionTenant(), ['*']), 'tenant'))->toThrow(RuntimeException::class, 'platform administrators');
    expect(fn () => $this->workflow->verify($this->epf, $this->reviewer, ''))->toThrow(RuntimeException::class, 'notes');

    $this->workflow->verify($this->epf, $this->reviewer, 'Checked against the circular');
    $epf = $this->epf->refresh();
    expect($epf->verification_status)->toBe('verified')
        ->and($epf->verified_by)->toBe($this->reviewer->id)
        ->and($epf->verified_at)->not->toBeNull()
        ->and($epf->verifications()->pluck('action')->all())->toBe(['created', 'submitted', 'verified'])
        ->and($epf->verifications()->where('action', 'verified')->value('source_url'))->toBe($epf->source_url);

    expect(fn () => $epf->update(['source_url' => 'https://www.epfindia.gov.in/other']))->toThrow(RuntimeException::class, 'cannot be changed');
    expect(fn () => $epf->verifications()->first()->update(['notes' => 'rewrite']))->toThrow(RuntimeException::class, 'append-only');
    expect(fn () => $epf->verifications()->first()->delete())->toThrow(RuntimeException::class, 'append-only');

    // Platform audit trail, hash chain intact.
    expect(AuditEvent::query()->withoutGlobalScopes()->whereNull('tenant_id')->whereIn('action', ['STATUTORY_RULE_CREATED', 'STATUTORY_RULE_REVIEWED', 'STATUTORY_RULE_VERIFIED'])->where('entity_id', (string) $epf->id)->count())->toBe(3)
        ->and(app(AuditIntegrityVerifier::class)->verify(null)['valid'])->toBeTrue();
});

it('rejects, supersedes corrections and never falls back to an older or rejected version', function () {
    $rules = app(ComplianceRules::class);
    $this->workflow->submit($this->epf, ($this->evidence)($this->epf), $this->submitter);
    ($this->attach)($this->epf);
    $this->workflow->verify($this->epf, $this->reviewer, 'ok');

    // A newer draft correction is what resolution returns (flagged as unverified), not the verified v1.
    $v2 = ($this->bypass)(fn () => ComplianceRule::query()->create(['jurisdiction' => 'IN', 'code' => 'EPF', 'name' => 'Employees Provident Fund', 'version' => 2, 'effective_from' => '2014-09-01', 'parameters' => ['employee_rate' => 0.12] + $this->epf->parameters]));
    $rules->forget();
    expect($rules->resolve('EPF', '2026-09-30')->id)->toBe($v2->id);

    // Rejected versions are never resolved.
    $this->workflow->reject($v2, $this->reviewer, 'Duplicate of v1');
    $rules->forget();
    expect($v2->refresh()->verification_status)->toBe('rejected')
        ->and($rules->resolve('EPF', '2026-09-30')->id)->toBe($this->epf->id);
    expect(fn () => $this->workflow->reject($v2, $this->reviewer, 'again'))->toThrow(RuntimeException::class, 'draft or in-review');

    // A verified correction supersedes the earlier version from the same date.
    $v3 = ($this->bypass)(fn () => ComplianceRule::query()->create(['jurisdiction' => 'IN', 'code' => 'EPF', 'name' => 'Employees Provident Fund', 'version' => 3, 'effective_from' => '2014-09-01', 'parameters' => $this->epf->parameters]));
    $this->workflow->submit($v3, ($this->evidence)($v3), $this->submitter);
    ($this->attach)($v3);
    $this->workflow->verify($v3, $this->reviewer, 'Correction');
    $rules->forget();
    expect($this->epf->refresh()->verification_status)->toBe('superseded')
        ->and($this->epf->superseded_by_id)->toBe($v3->id)
        ->and($rules->resolve('EPF', '2026-09-30')->id)->toBe($v3->id);
});
