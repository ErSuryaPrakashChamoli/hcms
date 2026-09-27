<?php

use App\Domain\Compliance\Models\ComplianceRule;
use App\Domain\Compliance\Services\ComplianceRules;
use App\Domain\Compliance\Services\RuleVerifications;
use App\Domain\Payroll\Models\PayrollEntry;
use App\Domain\Payroll\Services\PayrollRuns;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Env;

require_once __DIR__.'/../Workflow/WorkflowTestHelpers.php';
require_once __DIR__.'/PayrollTestHelpers.php';

/*
 | Statutory safety. Phase 0.2 introduced the gate; Phase 5 (Parts E–G) replaced "illustrative"
 | with DRAFT/REVIEW/VERIFIED/SUPERSEDED/REJECTED, made the gate per run, flagged unverified and
 | missing rules during calculation, and turned enforcement on by default.
 */

beforeEach(function () {
    $this->travelTo('2026-09-21 09:00:00');
    syncComplianceRules();
    $this->tenant = provisionTenant();
    actAsTenant($this->tenant);
    $this->preparer = tenantUser($this->tenant, ['payroll.*', 'employee.*']);
    $this->approver = tenantUser($this->tenant, ['payroll.*', 'employee.*']);
    $this->actingAs($this->preparer);
    $this->company = payrollCompany();
    $this->runs = app(PayrollRuns::class);
});

/** Push a rule through the real workflow: submitted by one platform admin, verified by another. */
function verifyRuleForTest(ComplianceRule $rule): void
{
    $workflow = app(RuleVerifications::class);
    [$submitter, $reviewer] = [platformAdmin(), platformAdmin()];

    app(TenantContext::class)->bypass(function () use ($workflow, $rule, $submitter, $reviewer) {
        if ($rule->verification_status === ComplianceRule::DRAFT) {
            $workflow->submit($rule, [
                'source_url' => 'https://www.example.gov.in/notification',
                'source_title' => 'Test notification',
                'effective_date' => $rule->effective_from->toDateString(),
                'requirement_text' => 'Test requirement',
                'mapping' => array_fill_keys(array_keys($rule->payload()), 'test mapping'),
            ], $submitter);
        }
        $workflow->verify($rule->refresh(), $reviewer, 'Verified in test');
    });
}

it('publishes every pack rule as DRAFT (ESI in REVIEW on official evidence) and never as VERIFIED', function () {
    expect(ComplianceRule::query()->count())->toBeGreaterThan(0)
        ->and(ComplianceRule::query()->where('verification_status', 'verified')->count())->toBe(0)
        ->and(ComplianceRule::query()->where('code', 'ESI')->value('verification_status'))->toBe('review')
        ->and(ComplianceRule::query()->where('code', 'EPF')->value('verification_status'))->toBe('draft')
        ->and(app(ComplianceRules::class)->unverified('IN')->isNotEmpty())->toBeTrue();

    $this->artisan('peopleos:compliance:sync')->expectsOutputToContain('not VERIFIED')->assertSuccessful();
});

it('turns enforcement on by default when no environment override exists', function () {
    $key = 'PEOPLEOS_ENFORCE_VERIFIED_RULES';
    $saved = [$_ENV[$key] ?? null, $_SERVER[$key] ?? null, getenv($key)];
    unset($_ENV[$key], $_SERVER[$key]);
    putenv($key);
    Env::disablePutenv();
    Env::enablePutenv(); // rebuild the cached repository without the override

    try {
        $config = require config_path('peopleos.php');
        expect($config['payroll']['enforce_verified_rules'])->toBeTrue();
    } finally {
        [$_ENV[$key], $_SERVER[$key]] = [$saved[0] ?? 'false', $saved[1] ?? 'false'];
        putenv($key.'='.($saved[2] === false ? 'false' : $saved[2]));
        Env::disablePutenv();
        Env::enablePutenv();
    }
});

it('blocks unverified rules at calculation and finalization when enforced, and finalizes once the used rules are verified', function () {
    config(['peopleos.payroll.enforce_verified_rules' => true]);
    $employee = salariedEmployee(600000);
    $run = $this->runs->calculate($this->runs->open($this->company, 2026, 9, $this->preparer), $this->preparer);

    $entry = PayrollEntry::query()->where('payroll_run_id', $run->id)->where('employee_id', $employee->id)->sole();
    expect($entry->status)->toBe('exception')
        ->and(collect($entry->exceptions)->where('type', 'unverified_statutory_rule')->pluck('blocking')->unique()->all())->toBe([true]);

    // Verify exactly the rule versions this run used (EPF, ESI, PT/KA, TDS FY 2026-27).
    foreach (array_keys($run->rule_versions) as $id) {
        verifyRuleForTest(ComplianceRule::query()->findOrFail($id));
    }
    app(ComplianceRules::class)->forget();

    $run = $this->runs->calculate($run, $this->preparer);
    expect(PayrollEntry::query()->where('payroll_run_id', $run->id)->value('status'))->not->toBe('exception');
    $run = $this->runs->approve($this->runs->validate($run), $this->approver, 'ok');

    // A rule that stops being verified after calculation (a verified correction supersedes it) blocks finalization.
    $pt = ComplianceRule::query()->where('code', 'PT')->where('state', 'KA')->sole();
    $correction = app(TenantContext::class)->bypass(fn () => ComplianceRule::query()->create(['jurisdiction' => 'IN', 'code' => 'PT', 'state' => 'KA', 'name' => $pt->name, 'version' => 2, 'effective_from' => $pt->effective_from, 'parameters' => $pt->parameters]));
    verifyRuleForTest($correction);
    expect($pt->refresh()->verification_status)->toBe('superseded');
    expect(fn () => $this->runs->finalize($run, $this->approver))->toThrow(RuntimeException::class, 'superseded');
    expect($run->refresh()->status)->toBe('approved');

    $run = $this->runs->reopen($run, 'PT Karnataka corrected; recalculate', $this->approver);
    $run = $this->runs->approve($this->runs->validate($this->runs->calculate($run, $this->preparer)), $this->approver, 'ok');
    expect($this->runs->finalize($run, $this->approver)->status)->toBe('finalized')
        ->and($run->refresh()->rule_versions)->toHaveKey($correction->id);
});

it('never skips an applicable statute silently when its rule is missing', function () {
    payrollCompany(['pt_state' => 'RJ']); // no professional tax rule in the pack for Rajasthan
    $employee = salariedEmployee(600000);
    $run = $this->runs->calculate($this->runs->open($this->company, 2026, 9, $this->preparer), $this->preparer);

    $entry = PayrollEntry::query()->where('payroll_run_id', $run->id)->where('employee_id', $employee->id)->sole();
    expect(collect($entry->exceptions)->pluck('type')->all())->toContain('statutory_rule_missing')
        ->and($entry->status)->not->toBe('exception'); // warning only while enforcement is off

    config(['peopleos.payroll.enforce_verified_rules' => true]);
    $run = $this->runs->calculate($run, $this->preparer);
    expect(PayrollEntry::query()->where('payroll_run_id', $run->id)->value('status'))->toBe('exception');
});
