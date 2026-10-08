<?php

use App\Domain\Audit\Exceptions\ImmutableAuditRecordException;
use App\Domain\Audit\Models\AuditEvent;
use App\Domain\Audit\Services\AuditIntegrityVerifier;
use App\Domain\Entitlements\Enums\Capability;
use App\Domain\Entitlements\Enums\DecisionOutcome as O;
use App\Domain\Entitlements\Models\EntitlementShadowObservation;
use App\Domain\Entitlements\Models\Plan;
use App\Domain\Entitlements\Models\TenantPlanAssignment;
use App\Domain\Entitlements\Services\EntitlementConfiguration;
use App\Domain\Entitlements\Services\Entitlements;
use App\Domain\Entitlements\Services\EntitlementStateStore;
use App\Domain\Entitlements\Services\PlanCatalog;
use App\Domain\Entitlements\Services\ShadowRecorder;
use App\Domain\Identity\Models\User;
use App\Domain\Payroll\Models\Payslip;
use App\Domain\Payroll\Services\PayrollRuns;
use App\Filament\Pages\PlatformEntitlementsPage;
use App\Filament\Pages\PlatformPlansPage;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use Tests\Support\EntitlementProbeJob;

require_once __DIR__.'/PlanTestHelpers.php';
require_once __DIR__.'/../Workflow/WorkflowTestHelpers.php';
require_once __DIR__.'/../Payroll/PayrollTestHelpers.php';
require_once __DIR__.'/../Performance/PerformanceTestHelpers.php';

/*
| SaaS.4: plans and assignments are platform operations. Tenants are isolated (assignments, decisions, cache,
| observations, queued work); tenant administrators cannot touch commercial state; the audit trail cannot be
| rewritten; and no plan, and no plan failure, ever stops payroll.
*/

beforeEach(function () {
    $this->travelTo('2027-01-04 09:00:00');
    $this->a = provisionTenant('Alpha');
    $this->b = provisionTenant('Beta');
    $this->operator = platformAdmin();
    $this->config = app(EntitlementConfiguration::class);
    $this->growth = publishedPlan($this->operator, 'growth', ['payroll' => true, 'leave' => true], '2027-01-04');
    $this->config->assignPlan($this->b, $this->growth, '2027-01-04', null, 'Beta on Growth', $this->operator);
    actAsTenant($this->a);
    $this->admin = tenantUser($this->a, ['*']);
});

it('keeps tenants apart: assignments, decisions, cache and queued work', function () {
    expect(TenantPlanAssignment::query()->count())->toBe(0)
        ->and(app(Entitlements::class)->evaluate(Capability::Payroll)->reason->value)->toBe('TENANT_UNCONFIGURED');
    app(TenantContext::class)->runAs($this->b, fn () => expect(TenantPlanAssignment::query()->count())->toBe(1)
        ->and(app(Entitlements::class)->evaluate(Capability::Payroll)->outcome)->toBe(O::Allow));

    // Tenant A's cache key never holds tenant B's plan.
    $store = app(EntitlementStateStore::class);
    expect($store->for($this->a->id)->assignments)->toBe([])
        ->and($store->for($this->b->id)->assignments)->toHaveCount(1)
        ->and(cache()->get(EntitlementStateStore::cacheKey($this->a->id))['assignments'])->toBe([]);

    EntitlementProbeJob::$seen = [];
    foreach ([$this->b, $this->a, $this->b] as $tenant) {
        actAsTenant($tenant);
        Bus::dispatchSync(new EntitlementProbeJob);
    }
    expect(collect(EntitlementProbeJob::$seen)->map(fn ($d) => [$d['tenant_id'], $d['decision'], $d['source'] ?? null])->all())
        ->toBe([[$this->b->id, 'ALLOW', 'plan'], [$this->a->id, 'UNKNOWN', 'none'], [$this->b->id, 'ALLOW', 'plan']]);
});

it('refuses tenant administrators, whatever their roles, for any tenant, in the services and on the pages', function () {
    expect(fn () => $this->config->assignPlan($this->a, $this->growth, '2027-01-04', null, 'Self-assign attempt', $this->admin))->toThrow(RuntimeException::class, 'Only platform operators')
        ->and(fn () => $this->config->assignPlan($this->b, $this->growth, '2027-01-05', null, 'Change another tenant', $this->admin))->toThrow(RuntimeException::class, 'Only platform operators');
    $bAssignment = app(TenantContext::class)->runAs($this->b, fn () => TenantPlanAssignment::query()->sole());
    expect(fn () => $this->config->endPlanAssignment($bAssignment, '2027-01-04', 'End another tenant', $this->admin))->toThrow(RuntimeException::class, 'Only platform operators');

    $this->actingAs($this->admin);
    $this->get(PlatformPlansPage::getUrl())->assertForbidden();
    $this->get(PlatformEntitlementsPage::getUrl(['tenant' => $this->a->id]))->assertForbidden();
    expect(TenantPlanAssignment::query()->count())->toBe(0)
        ->and(app(TenantContext::class)->runAs($this->b, fn () => TenantPlanAssignment::query()->sole()->effective_to))->toBeNull();
});

it('refuses every non-operator: employee, manager, tenant administrator, an operator flag inside a tenant, a tenantless non-operator', function () {
    $flagged = tenantUser($this->a, ['*']);
    $flagged->forceFill(['is_platform_admin' => true])->save();
    $people = [
        'employee' => tenantUser($this->a, ['leave.apply']),
        'manager' => tenantUser($this->a, ['leave.approve', 'attendance.approve']),
        'tenant administrator' => $this->admin,
        'tenant user flagged as operator' => $flagged,
        'tenantless non-operator' => User::factory()->create(['tenant_id' => null, 'is_platform_admin' => false]),
    ];
    $catalog = app(PlanCatalog::class);
    $bAssignment = app(TenantContext::class)->runAs($this->b, fn () => TenantPlanAssignment::query()->sole());
    $before = [AuditEvent::query()->withoutTenancy()->where('module', 'entitlements')->count(), Plan::query()->count()];

    foreach ($people as $who => $user) {
        $user = $user->fresh();
        foreach ([
            'create a plan' => fn () => $catalog->create('attempt', 'Attempt', null, 'Attempted plan', $user),
            'edit a draft' => fn () => $catalog->draft($this->growth->plan, 'Attempted draft', $user),
            'retire a version' => fn () => $catalog->retire($this->growth, 'Attempted retire', $user),
            'assign a plan' => fn () => $this->config->assignPlan($this->a, $this->growth, '2027-01-05', null, 'Attempted assign', $user),
            'end an assignment' => fn () => $this->config->endPlanAssignment($bAssignment, '2027-01-04', 'Attempted end', $user),
        ] as $what => $attempt) {
            expect($attempt)->toThrow(RuntimeException::class, 'Only platform operators');
        }
        // Refused: 403 inside the panel, or (a user who cannot enter the panel at all) sent to the login page.
        $this->actingAs($user);
        foreach ([PlatformPlansPage::getUrl(), PlatformEntitlementsPage::getUrl(['tenant' => $this->b->id])] as $url) {
            $response = $this->get($url);
            expect($response->status() === 403 || ($response->isRedirect() && str_ends_with((string) $response->headers->get('Location'), '/admin/login')))
                ->toBeTrue("{$who} reached {$url} ({$response->status()})");
        }
    }
    // No commercial change and no commercial audit event (the panel's own sign-out of a refused user is an identity event).
    expect([AuditEvent::query()->withoutTenancy()->where('module', 'entitlements')->count(), Plan::query()->count()])->toBe($before)
        ->and($bAssignment->fresh()->effective_to)->toBeNull();
});

it('cannot skip the reason, or assign a draft, through the page', function () {
    $this->actingAs($this->operator);
    actAsTenant(null);
    $draft = app(PlanCatalog::class)->draft($this->growth->plan, 'Growth v2 draft', $this->operator);

    foreach ([
        'reason' => ['version' => $this->growth->id, 'from' => '2027-01-04', 'reason' => 'no'],
        'reason ' => ['version' => $this->growth->id, 'from' => '2027-01-04', 'reason' => ''],
        'version' => ['version' => $draft->id, 'from' => '2027-01-04', 'reason' => 'Assign the draft'],
    ] as $field => $data) {
        Livewire::test(PlatformEntitlementsPage::class, ['tenant' => $this->a->id])
            ->callAction('assignPlan', data: $data)
            ->assertHasActionErrors([trim($field)]);
    }
    expect(app(TenantContext::class)->runAs($this->a, fn () => TenantPlanAssignment::query()->count()))->toBe(0);
});

it('lets an operator assign and end a plan on the Entitlements page, audited and verifiable, never rewritable', function () {
    $this->actingAs($this->operator);
    actAsTenant(null);
    Livewire::test(PlatformEntitlementsPage::class, ['tenant' => $this->a->id])
        ->callAction('assignPlan', data: ['version' => $this->growth->id, 'from' => '2027-01-11', 'reference' => 'DEAL-9', 'reason' => 'Alpha signs Growth'])
        ->assertHasNoActionErrors();
    $assignment = app(TenantContext::class)->runAs($this->a, fn () => TenantPlanAssignment::query()->sole());
    $this->get(PlatformEntitlementsPage::getUrl(['tenant' => $this->a->id, 'day' => '2027-01-11']))->assertOk()
        ->assertSee('growth v1')->assertSee('Alpha signs Growth')->assertSee('PLAN_ASSIGNED')->assertSee('DEAL-9');

    Livewire::test(PlatformEntitlementsPage::class, ['tenant' => $this->a->id])
        ->callAction('endPlanAssignment', data: ['assignment' => $assignment->id, 'last_day' => '2027-01-31', 'reason' => 'Short pilot only'])
        ->assertHasNoActionErrors();
    expect($assignment->fresh()->effective_to->toDateString())->toBe('2027-01-31')
        ->and(app(Entitlements::class)->evaluateFor($this->a->id, Capability::Payroll, '2027-01-20')->outcome)->toBe(O::Allow)
        ->and(app(Entitlements::class)->evaluateFor($this->a->id, Capability::Payroll, '2027-02-01')->reason->value)->toBe('NO_PLAN_IN_FORCE');

    $events = AuditEvent::query()->withoutTenancy()->whereIn('action', ['PLAN_ASSIGNED', 'PLAN_ASSIGNMENT_ENDED'])->get();
    expect($events->where('tenant_id', $this->a->id)->pluck('action')->map->value->sort()->values()->all())->toBe(['PLAN_ASSIGNED', 'PLAN_ASSIGNMENT_ENDED'])
        ->and($events->whereNull('tenant_id')->filter(fn ($e) => ($e->metadata['subject_tenant_id'] ?? null) === $this->a->id)->count())->toBe(2)
        ->and(fn () => $events->first()->update(['reason' => 'rewritten']))->toThrow(ImmutableAuditRecordException::class)
        ->and(app(AuditIntegrityVerifier::class)->verify($this->a->id)['valid'])->toBeTrue()
        ->and(app(AuditIntegrityVerifier::class)->verify(null)['valid'])->toBeTrue();
});

it('records the plan behind an observation, and nothing about employees or values', function () {
    actAsTenant($this->b);
    app(ShadowRecorder::class)->flush();
    app(Entitlements::class)->observe(Capability::Attendance, 'attendance.punch.record');
    app(ShadowRecorder::class)->flush();
    $row = EntitlementShadowObservation::query()->where('surface', 'attendance.punch.record')->sole();
    $assignment = TenantPlanAssignment::query()->sole();
    expect([$row->outcome, $row->reason, $row->last_source, $row->last_assignment_id])->toBe(['DENY', 'NOT_IN_PLAN', 'plan', $assignment->id]);
});

it('measures and observes the plan\'s active-employee limit on a hire, and never refuses the hire', function () {
    $small = publishedPlan($this->operator, 'small', ['leave' => true, 'active_employees.max' => 1], '2027-01-04');
    $this->config->assignPlan($this->a, $small, '2027-01-04', null, 'One employee only', $this->operator);
    actAsTenant($this->a);
    $this->actingAs(tenantUser($this->a, ['*']));
    app(ShadowRecorder::class)->flush();

    $first = activeEmployee(null, ['task.view']);
    $second = activeEmployee(null, ['task.view']);
    expect($first->lifecycle_state->isEmployed())->toBeTrue()->and($second->lifecycle_state->isEmployed())->toBeTrue();
    app(ShadowRecorder::class)->flush();
    $rows = EntitlementShadowObservation::query()->where('surface', 'employee.activate')->get();
    expect($rows->pluck('reason')->sort()->values()->all())->toBe(['LIMIT_EXCEEDED', 'WITHIN_LIMIT'])
        ->and($rows->pluck('last_source')->unique()->all())->toBe(['plan']);
});

/** Open, calculate, approve and finalise this month's run. */
function runPayrollOnPlan(object $test)
{
    $runs = app(PayrollRuns::class);
    $run = $runs->calculate($runs->open($test->company, 2027, 1));

    return $runs->finalize($runs->approve($runs->validate($run), $test->approver));
}

it('runs payroll to finalisation on a plan without payroll, and when the plan tables are unreadable', function () {
    syncComplianceRules();
    $starter = publishedPlan($this->operator, 'starter', ['leave' => true], '2027-01-04');
    $this->config->assignPlan($this->a, $starter, '2027-01-04', null, 'Starter without payroll', $this->operator);
    actAsTenant($this->a);
    $this->actingAs(tenantUser($this->a, ['payroll.*', 'employee.*']));
    $this->approver = tenantUser($this->a, ['payroll.*', 'employee.*']);
    $this->company = payrollCompany();
    $employee = salariedEmployee(600000);
    expect(app(Entitlements::class)->evaluate(Capability::Payroll)->reason->value)->toBe('NOT_IN_PLAN');

    expect(runPayrollOnPlan($this)->status)->toBe('finalized')
        ->and(Payslip::query()->where('employee_id', $employee->id)->exists())->toBeTrue();
    app(ShadowRecorder::class)->flush();
    expect(EntitlementShadowObservation::query()->where('capability', 'payroll')->pluck('reason', 'surface')->all())
        ->toBe(['payroll.run.calculate' => 'NOT_IN_PLAN', 'payroll.run.finalize' => 'NOT_IN_PLAN', 'payroll.run.open' => 'NOT_IN_PLAN']);

    // The plan tables become unreadable: the engine answers UNKNOWN (EVALUATION_FAILED) and payroll still runs.
    app(EntitlementStateStore::class)->forget($this->a->id);
    Schema::drop('plan_entitlements');
    expect(app(Entitlements::class)->evaluate(Capability::Payroll)->reason->value)->toBe('EVALUATION_FAILED');
    $payslips = Payslip::query()->where('employee_id', $employee->id)->count();
    $this->travelTo('2027-02-22 09:00:00');
    $runs = app(PayrollRuns::class);
    $run = $runs->finalize($runs->approve($runs->validate($runs->calculate($runs->open($this->company, 2027, 2))), $this->approver));
    expect($run->status)->toBe('finalized')->and(Payslip::query()->where('employee_id', $employee->id)->count())->toBe($payslips + 1);
});
