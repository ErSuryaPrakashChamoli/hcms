<?php

use App\Domain\Ai\Models\AiInteraction;
use App\Domain\Audit\Models\AuditEvent;
use App\Domain\Enterprise\Services\Retention;
use App\Domain\Offboarding\Enums\LegalHoldStatus;
use App\Domain\Offboarding\Enums\RetentionPolicyStatus;
use App\Domain\Offboarding\Models\RetentionPolicy;
use App\Domain\Offboarding\Services\DeletionPlanner;
use App\Domain\Offboarding\Services\LegalHolds;
use App\Domain\Offboarding\Services\RetentionPolicies;
use App\Domain\Offboarding\Services\TenantOffboardings;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;

require_once __DIR__.'/OffboardingTestHelpers.php';
require_once __DIR__.'/../Workflow/WorkflowTestHelpers.php';

/*
| SaaS.9: retention is configuration (versioned, effective-dated, per jurisdiction, approved by a second operator, never
| below a configured legal minimum, never assumed) and a legal hold stops every deletion of what it covers.
*/

beforeEach(function () {
    Storage::fake('local');
    [$this->maker, $this->checker] = exitOperators();
    $this->policies = app(RetentionPolicies::class);
    $this->holds = app(LegalHolds::class);
    $this->tenant = provisionTenant('Holding Co');
});

it('applies a retention policy only once another operator approves it, and never below a configured legal minimum', function () {
    $policy = $this->policies->propose('payroll', 'IN', 96, 'Statutory payroll records (fictional test basis)', 'TEST-ACT s.1', 120, 'Company policy', today()->toDateString(), 'Retain payroll', $this->maker);
    expect($policy->status)->toBe(RetentionPolicyStatus::Pending)
        ->and($this->policies->inForce('payroll', 'IN', today()->toDateString()))->toBeNull()
        ->and(fn () => $this->policies->approve($policy, $this->maker, 'My own'))->toThrow(RuntimeException::class, 'other than the one who proposed');

    $this->policies->approve($policy, $this->checker, 'Checked against the source');
    expect($this->policies->inForce('payroll', 'IN', today()->toDateString())?->id)->toBe($policy->id);

    expect(fn () => $this->policies->propose('payroll', 'IN', 96, 'Basis', 'TEST-ACT s.1', 60, null, today()->toDateString(), 'Too short', $this->maker))
        ->toThrow(RuntimeException::class, 'cannot be below the legal minimum')
        ->and(fn () => $this->policies->propose('payroll', 'IN', 96, null, null, 120, null, today()->toDateString(), 'No reference', $this->maker))
        ->toThrow(RuntimeException::class, 'legal basis and reference')
        ->and(fn () => $this->policies->propose('payroll', 'IN', null, null, null, 12, null, today()->subDay()->toDateString(), 'In the past', $this->maker))
        ->toThrow(RuntimeException::class, 'today or later');
});

it('says LEGAL_MINIMUM_NOT_CONFIGURED instead of assuming one, and refuses a tenant user', function () {
    $policy = $this->policies->propose('leave', '*', null, null, null, 24, 'Company decision', today()->toDateString(), 'Leave kept 2 years', $this->maker);
    $this->policies->approve($policy, $this->checker, 'Approved');

    $retention = $this->policies->retentionFor('leave', 'IN', Carbon::parse('2026-01-15'));
    expect($retention)->toMatchArray(['configured' => true, 'months' => 24, 'until' => '2028-01-15', 'legal_minimum' => RetentionPolicy::LEGAL_MINIMUM_NOT_CONFIGURED]);
    expect($this->policies->retentionFor('payroll', 'IN', Carbon::parse('2026-01-15')))->toMatchArray(['configured' => false]);

    actAsTenant($this->tenant);
    $tenantAdmin = tenantUser($this->tenant, ['*']);
    actAsTenant(null);
    expect(fn () => $this->policies->propose('leave', '*', null, null, null, 1, null, today()->toDateString(), 'Shorten it', $tenantAdmin))
        ->toThrow(RuntimeException::class, 'Only platform operators');
});

it('keeps data for the longer of the version at closure and the version today, and prefers the tenant\'s own jurisdiction', function () {
    $this->travelTo('2026-10-10');
    $v1 = $this->policies->propose('attendance', '*', null, null, null, 36, null, '2026-10-10', 'Global three years', $this->maker);
    $this->policies->approve($v1, $this->checker, 'Approved');
    $closedAt = Carbon::parse('2026-10-20');
    $this->travelTo('2026-11-01');
    $v2 = $this->policies->propose('attendance', '*', null, null, null, 12, null, '2026-11-01', 'Shorter later', $this->maker);
    $this->policies->approve($v2, $this->checker, 'Approved');

    // A later, shorter version never shortens what applied at closure.
    expect($this->policies->retentionFor('attendance', 'IN', $closedAt)['months'])->toBe(36);
    $own = $this->policies->propose('attendance', 'IN', null, null, null, 60, null, '2026-11-01', 'India: five years', $this->maker);
    $this->policies->approve($own, $this->checker, 'Approved');
    expect($this->policies->retentionFor('attendance', 'IN', $closedAt)['months'])->toBe(60)
        ->and($this->policies->retentionFor('attendance', 'GB', $closedAt)['months'])->toBe(36);
});

it('places a hold at once, releases it only with a second operator, and the plan keeps what it covers', function () {
    retentionFor(null, 0, $this->maker, $this->checker);
    actAsTenant($this->tenant);
    employeeWithUser();
    actAsTenant(null);
    $case = offboardAndClose($this->tenant, $this->maker);

    $hold = $this->holds->place($this->tenant, ['people', 'employment'], 'Litigation matter (fictional)', 'MATTER-1', null, $this->maker);
    expect($hold->status)->toBe(LegalHoldStatus::Active)
        ->and($this->holds->covering($this->tenant->id, 'people'))->toBe([$hold->reference])
        ->and($this->holds->covering($this->tenant->id, 'leave'))->toBe([]);
    $plan = app(DeletionPlanner::class)->plan($this->tenant, $case);
    expect($plan['categories']['people']['reason'])->toBe('LEGAL_HOLD')
        ->and($plan['categories']['employment']['reason'])->toBe('LEGAL_HOLD');

    $hold = $this->holds->requestRelease($hold, $this->maker, 'Matter settled');
    expect($hold->status)->toBe(LegalHoldStatus::ReleaseRequested)
        ->and($this->holds->covering($this->tenant->id, 'people'))->toBe([$hold->reference]) // still holding
        ->and(fn () => $this->holds->approveRelease($hold, $this->maker, 'Self release'))->toThrow(RuntimeException::class, 'other than the one who asked');
    $hold = $this->holds->approveRelease($hold, $this->checker, 'Confirmed with counsel');
    expect($hold->status)->toBe(LegalHoldStatus::Released)
        ->and($this->holds->covering($this->tenant->id, 'people'))->toBe([]);
    $actions = AuditEvent::query()->withoutTenancy()->whereNull('tenant_id')->where('metadata->hold', $hold->reference)->pluck('action')->map->value->all();
    expect($actions)->toBe(['LEGAL_HOLD_PLACED', 'LEGAL_HOLD_RELEASE_REQUESTED', 'LEGAL_HOLD_RELEASED']);
});

it('stops the retention purge of a held category, and refuses a hold from a tenant user', function () {
    app(TenantContext::class)->runAs($this->tenant, function () {
        $user = tenantUser($this->tenant, ['ai.use']);
        AiInteraction::create(['user_id' => $user->id, 'assistant' => 'employee', 'question' => 'old', 'answer' => 'x'])->forceFill(['created_at' => now()->subDays(400)])->save();
    });
    $this->holds->place($this->tenant, ['analytics'], 'Regulator inquiry (fictional)', null, null, $this->maker);

    $purged = app(TenantContext::class)->runAs($this->tenant, fn () => app(Retention::class)->purge());
    expect($purged['ai_interactions'])->toBe('held')
        ->and(app(TenantContext::class)->runAs($this->tenant, fn () => AiInteraction::query()->count()))->toBe(1);

    actAsTenant($this->tenant);
    $tenantAdmin = tenantUser($this->tenant, ['*']);
    actAsTenant(null);
    expect(fn () => $this->holds->place($this->tenant, [], 'Not mine to hold', null, null, $tenantAdmin))->toThrow(RuntimeException::class, 'Only platform operators');
});

it('lets the case reopen while only a hold exists, because a hold destroys nothing', function () {
    $case = offboardAndClose($this->tenant, $this->maker);
    $this->holds->place($this->tenant, [], 'Precaution', null, null, $this->maker);

    app(TenantOffboardings::class)->reopen($case, $this->maker, 'Customer stays after all');
    expect($this->tenant->fresh()->status->value)->toBe('active');
});
