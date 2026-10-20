<?php

use App\Domain\Offboarding\Enums\DeletionStatus;
use App\Domain\Offboarding\Models\RetentionPolicy;
use App\Domain\Offboarding\Models\TenantDeletionStep;
use App\Domain\Offboarding\Services\DeletionExecutor;
use App\Domain\Offboarding\Services\DeletionPlanner;
use App\Domain\Offboarding\Services\RetentionPolicies;
use App\Domain\Offboarding\Services\TenantDeletions;
use App\Domain\Offboarding\Services\TenantOffboardings;
use App\Domain\Offboarding\Services\TenantRetentionTerms;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

require_once __DIR__.'/OffboardingTestHelpers.php';
require_once __DIR__.'/../Workflow/WorkflowTestHelpers.php';

/*
| SaaS.9 closure: the three retention inputs stay distinct and safe. A: a legal minimum is verified only with its
| authoritative source, reviewer and date, and an unverified one keeps the category from deletion. B: Markedge's policy
| counts from its start event. C: a customer's contract can extend retention, and a contractual deletion deadline it runs
| past is a recorded conflict, never an automatic deletion. Decided versions never change, and an approved deletion plan is
| never rewritten by a later policy.
*/

beforeEach(function () {
    Storage::fake('local');
    config(['peopleos.offboarding.deletion_enabled' => true]);
    [$this->maker, $this->checker] = exitOperators();
    $this->policies = app(RetentionPolicies::class);
    $this->terms = app(TenantRetentionTerms::class);
    $this->tenant = provisionTenant('Closure Co');
    app(TenantContext::class)->runAs($this->tenant, fn () => employeeWithUser());
});

/** Approves a policy for one category with the given legal verification. */
function approvedPolicy(RetentionPolicies $policies, string $category, int $months, ?int $legal, array $verification, $maker, $checker, string $start = RetentionPolicy::START_TENANT_CLOSURE): RetentionPolicy
{
    $policy = $policies->propose($category, '*', $legal, $legal === null ? null : 'Fictional statute (test)', $legal === null ? null : 'TEST-ACT s.1', $months, 'Test policy',
        today()->toDateString(), 'Closure test policy', $maker, ...$verification, startEvent: $start);

    return $policies->approve($policy, $checker, 'Checked in the test');
}

it('keeps an entered but unverified legal minimum explicit, and never deletes its category until it is verified', function () {
    retentionFor(null, 0, $this->maker, $this->checker);
    $pending = approvedPolicy($this->policies, 'people', 0, 0, [], $this->maker, $this->checker);
    expect($pending->legal_minimum_status)->toBe(RetentionPolicy::LEGAL_PENDING_VERIFICATION);

    $case = offboardAndClose($this->tenant, $this->maker);
    $plan = app(DeletionPlanner::class)->plan($this->tenant->fresh(), $case->fresh());
    expect($plan['categories']['people']['reason'])->toBe(RetentionPolicy::LEGAL_MINIMUM_PENDING_VERIFICATION)
        ->and($plan['categories']['people']['action'])->toBe('retain')
        ->and($plan['categories']['people']['retention']['legal_minimum'])->toBe(RetentionPolicy::LEGAL_MINIMUM_PENDING_VERIFICATION);

    // Verified: source, reviewer and date, and the category is deletable again (its 0-month minimum has passed).
    $verified = approvedPolicy($this->policies, 'people', 0, 0, ['legalSource' => 'https://example.test/official-text', 'legalVerifiedBy' => 'Counsel (fictional)',
        'legalVerifiedOn' => today()->toDateString()], $this->maker, $this->checker);
    expect($verified->legal_minimum_status)->toBe(RetentionPolicy::LEGAL_VERIFIED);
    $plan = app(DeletionPlanner::class)->plan($this->tenant->fresh(), $case->fresh());
    expect($plan['categories']['people']['retention']['legal_minimum'])->toBe('0 months (verified)');
});

it('refuses half a verification, a verification without a minimum, a future verification date and a policy below a verified minimum', function () {
    $propose = fn (?int $legal, array $verification, int $months = 24) => fn () => $this->policies->propose('leave', '*', $legal, $legal === null ? null : 'Basis', $legal === null ? null : 'Ref',
        $months, null, today()->toDateString(), 'Leave policy test', $this->maker, ...$verification);

    expect($propose(12, ['legalSource' => 'https://example.test/law']))->toThrow(RuntimeException::class, 'needs its authoritative source, its reviewer and the verification date')
        ->and($propose(null, ['legalVerifiedBy' => 'Counsel']))->toThrow(RuntimeException::class, 'belong to a legal minimum')
        ->and($propose(12, ['legalSource' => 'https://example.test/law', 'legalVerifiedBy' => 'Counsel', 'legalVerifiedOn' => today()->addDay()->toDateString()]))
        ->toThrow(RuntimeException::class, 'future date')
        ->and($propose(36, ['legalSource' => 'https://example.test/law', 'legalVerifiedBy' => 'Counsel', 'legalVerifiedOn' => today()->toDateString()], 24))
        ->toThrow(RuntimeException::class, 'cannot be below the legal minimum')
        ->and($propose(null, [])())->legal_minimum_status->toBe(RetentionPolicy::LEGAL_NOT_CONFIGURED);
});

it('counts a policy from the end of the financial year in which the tenant closed', function () {
    $policy = approvedPolicy($this->policies, 'payroll', 12, null, [], $this->maker, $this->checker, RetentionPolicy::START_FINANCIAL_YEAR_END);
    expect($policy->financial_year_end)->toBe('03-31')
        ->and($this->policies->retentionFor('payroll', 'IN', Carbon::parse('2026-10-20'))['until'])->toBe('2028-03-31')
        ->and($this->policies->retentionFor('payroll', 'IN', Carbon::parse('2027-03-31'))['until'])->toBe('2028-03-31')
        ->and($this->policies->retentionFor('payroll', 'IN', Carbon::parse('2027-04-01'))['until'])->toBe('2029-03-31');
});

it('never changes or deletes a decided policy version or contract term', function () {
    $policy = approvedPolicy($this->policies, 'leave', 6, null, [], $this->maker, $this->checker);
    expect(fn () => $policy->forceFill(['policy_months' => 1])->save())->toThrow(LogicException::class, 'cannot be changed')
        ->and(fn () => $policy->delete())->toThrow(LogicException::class, 'never deleted');

    $term = $this->terms->approve($this->terms->propose($this->tenant, '*', 12, null, 'MSA 2026 cl. 14.2 (fictional)', today()->toDateString(), 'Contract requires it', $this->maker),
        $this->checker, 'Contract checked');
    expect(fn () => app(TenantContext::class)->runAs($this->tenant, fn () => $term->forceFill(['minimum_months' => 1])->save()))->toThrow(LogicException::class, 'cannot be changed');
});

it('extends retention by the customer\'s contractual minimum, separately from Markedge\'s policy, once a second operator approves it', function () {
    $this->travelTo('2026-10-12 10:00:00');
    retentionFor(null, 0, $this->maker, $this->checker);
    $term = $this->terms->propose($this->tenant, 'people', 12, null, 'MSA 2026 cl. 9 (fictional)', today()->toDateString(), 'Customer keeps a year', $this->maker);
    expect(fn () => $this->terms->approve($term, $this->maker, 'My own'))->toThrow(RuntimeException::class, 'other than the one who proposed');
    $case = offboardAndClose($this->tenant, $this->maker);
    // Pending: no effect.
    expect(app(DeletionPlanner::class)->plan($this->tenant->fresh(), $case->fresh())['categories']['people']['reason'])->toBe(DeletionPlanner::ELIGIBLE);

    $this->terms->approve($term, $this->checker, 'Contract checked');
    $people = app(DeletionPlanner::class)->plan($this->tenant->fresh(), $case->fresh())['categories']['people'];
    expect($people['reason'])->toBe('RETENTION_RUNNING')
        ->and($people['retention']['until'])->toBe('2027-10-12')
        ->and($people['retention']['contract']['extends'])->toBeTrue()
        ->and($people['detail'])->toContain('MSA 2026 cl. 9')
        // Markedge's policy (0 months) is unchanged: the contract is its own input.
        ->and($people['retention']['months'])->toBe(0);

    $this->travelTo('2027-10-13 10:00:00');
    expect(app(DeletionPlanner::class)->plan($this->tenant->fresh(), $case->fresh())['categories']['people']['reason'])->toBe(DeletionPlanner::ELIGIBLE);
});

it('records a contractual deletion deadline the retention runs past as a conflict, and deletes nothing because of it', function () {
    retentionFor(null, 24, $this->maker, $this->checker);
    $this->terms->approve($this->terms->propose($this->tenant, '*', null, 30, 'DPA 2026 cl. 11 (fictional)', today()->toDateString(), 'Delete within 30 days', $this->maker),
        $this->checker, 'Contract checked');
    $case = offboardAndClose($this->tenant, $this->maker);
    $plan = app(DeletionPlanner::class)->plan($this->tenant->fresh(), $case->fresh());

    expect(collect($plan['conflicts'])->pluck('code')->unique()->all())->toBe(['CONTRACT_DELETION_DEADLINE_CONFLICT'])
        ->and(collect($plan['conflicts'])->pluck('category'))->toContain('people')
        ->and($plan['categories']['people']['reason'])->toBe('RETENTION_RUNNING')
        ->and($plan['eligible'])->toBe([]);
    expect(fn () => $this->terms->propose($this->tenant, 'leave', 12, 30, 'Contradiction', today()->toDateString(), 'Self contradicting', $this->maker))
        ->toThrow(RuntimeException::class, 'contradicts itself');
    $admin = app(TenantContext::class)->runAs($this->tenant, fn () => tenantUser($this->tenant, ['*']));
    expect(fn () => $this->terms->propose($this->tenant, '*', 1, null, 'Customer-made', today()->toDateString(), 'From inside', $admin))
        ->toThrow(RuntimeException::class, 'Only platform operators');
});

it('runs nothing without approval, and a later policy never rewrites an approved plan: what it now keeps is skipped, not deleted', function () {
    retentionFor(null, 0, $this->maker, $this->checker);
    $case = offboardAndClose($this->tenant, $this->maker);
    app(TenantOffboardings::class)->waiveExport($case, $this->maker, 'Customer took a copy');
    $deletion = app(TenantDeletions::class)->request($case->fresh(), $this->maker, 'Delete');
    expect(app(DeletionExecutor::class)->execute($deletion->id))->toBeFalse()
        ->and(app(TenantDeletions::class)->find($deletion->id)->status)->toBe(DeletionStatus::Planned)
        ->and(DB::table('employees')->where('tenant_id', $this->tenant->id)->count())->toBe(1);

    $deletion = app(TenantDeletions::class)->approve($deletion, $this->checker, 'Plan reviewed');
    $approvedPlan = $deletion->plan_sha256;
    // After approval Markedge lengthens the people policy (a new version, today).
    approvedPolicy($this->policies, 'people', 120, null, [], $this->maker, $this->checker);
    $this->travel(15)->days();
    app(DeletionExecutor::class)->execute($deletion->id);

    $deletion = app(TenantDeletions::class)->find($deletion->id);
    $people = TenantDeletionStep::query()->withoutTenancy()->where('tenant_deletion_id', $deletion->id)->where('category', 'people')->pluck('status')->unique()->values()->all();
    expect($deletion->status)->toBe(DeletionStatus::Completed, (string) $deletion->failure)
        ->and($deletion->plan_sha256)->toBe($approvedPlan)
        ->and($people)->toBe([TenantDeletionStep::SKIPPED])
        ->and(DB::table('people')->where('tenant_id', $this->tenant->id)->count())->toBeGreaterThan(0)
        ->and(collect($deletion->certificate['categories']['retained'])->firstWhere('category', 'people')['reason'])->toBe('RETENTION_RUNNING');
});

it('tracks external copies as pending, requested, failed, retention required, unsupported or confirmed, and never claims them deleted', function () {
    retentionFor(null, 0, $this->maker, $this->checker);
    $case = offboardAndClose($this->tenant, $this->maker);
    app(TenantOffboardings::class)->waiveExport($case, $this->maker, 'Customer took a copy');
    $deletion = approvedDeletion($case->fresh(), $this->maker, $this->checker);
    $this->travel(15)->days();
    app(DeletionExecutor::class)->execute($deletion->id);
    $deletion = app(TenantDeletions::class)->find($deletion->id);
    $step = fn (string $target) => TenantDeletionStep::query()->withoutTenancy()->where('tenant_deletion_id', $deletion->id)->where('target', $target)->firstOrFail();

    expect($deletion->certificate['complete_including_external_copies'])->toBeFalse()
        ->and($deletion->certificate['external_copies_outstanding'])->toContain('database backups (PENDING)', 'file storage backups and replicas (PENDING)', 'e-mail delivered (UNSUPPORTED)');

    $deletions = app(TenantDeletions::class);
    $deletions->recordExternal($step('database backups'), TenantDeletionStep::EXTERNAL_FAILED, $this->maker, 'Provider ticket rejected (fictional)');
    expect(fn () => $deletions->recordExternal($step('database backups'), TenantDeletionStep::EXTERNAL_RETENTION_REQUIRED, $this->maker, 'Backup policy keeps 35 days'))
        ->toThrow(RuntimeException::class, 'until which it is kept')
        ->and(fn () => $deletions->recordExternal($step('database backups'), TenantDeletionStep::EXTERNAL_RETENTION_REQUIRED, $this->maker, 'Backup policy', today()->subDay()->toDateString()))
        ->toThrow(RuntimeException::class, 'until which it is kept');
    $deletions->recordExternal($step('database backups'), TenantDeletionStep::EXTERNAL_RETENTION_REQUIRED, $this->maker, 'Backup policy keeps 35 days', today()->addDays(35)->toDateString());
    expect($step('database backups')->status)->toBe(TenantDeletionStep::EXTERNAL_RETENTION_REQUIRED)
        ->and($step('database backups')->detail['retained_until'])->toBe(today()->addDays(35)->toDateString());
    $deletions->recordExternal($step('database backups'), TenantDeletionStep::EXTERNAL_CONFIRMED, $this->checker, 'Backups aged out (fictional ticket)');
    expect(fn () => $deletions->recordExternal($step('database backups'), TenantDeletionStep::EXTERNAL_FAILED, $this->maker, 'Undo the confirmation'))->toThrow(RuntimeException::class, 'already confirmed');
});
