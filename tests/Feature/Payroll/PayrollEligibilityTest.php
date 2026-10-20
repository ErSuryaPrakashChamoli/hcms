<?php

use App\Domain\Lifecycle\Enums\LifecycleState;
use App\Domain\Payroll\Models\PayrollPeriod;
use App\Domain\Payroll\Services\PayrollRuns;

require_once __DIR__.'/../Workflow/WorkflowTestHelpers.php';
require_once __DIR__.'/PayrollTestHelpers.php';

/* SaaS.2: the payroll population is a positive list of lifecycle states. Nobody who has not joined is paid. */

beforeEach(function () {
    $this->travelTo('2026-09-21 09:00:00');
    syncComplianceRules();
    $this->tenant = provisionTenant();
    actAsTenant($this->tenant);
    $this->actingAs(tenantUser($this->tenant, ['payroll.*', 'employee.*']));
    $this->company = payrollCompany();
    $this->runs = app(PayrollRuns::class);
});

it('classifies every lifecycle state, and only joined states are payroll-eligible', function () {
    expect(LifecycleState::payrollEligibleValues())->toBe(['joined', 'probation', 'confirmed', 'active', 'on_leave', 'suspended', 'notice_period', 'exited'])
        ->and(LifecycleState::PreEmployee->isPayrollEligible())->toBeFalse()
        ->and(LifecycleState::Preboarding->isPayrollEligible())->toBeFalse()
        ->and(LifecycleState::Onboarding->isPayrollEligible())->toBeFalse()
        ->and(LifecycleState::Alumni->isPayrollEligible())->toBeFalse();

    // Every eligible value is a real state: the old list named `offer_accepted` and `candidate`, which do not exist.
    foreach (LifecycleState::payrollEligibleValues() as $value) {
        expect(LifecycleState::tryFrom($value))->not->toBeNull();
    }
});

it('keeps pre-joining employees out of the run and pays everyone who has joined', function () {
    $in = static fn (LifecycleState $state, array $extra = []) => forceLifecycle(salariedEmployee(600000), $state, $extra);

    $expected = [
        $in(LifecycleState::Joined, ['joining_date' => '2026-09-01']),
        $in(LifecycleState::Probation),
        $in(LifecycleState::Confirmed),
        $in(LifecycleState::Active),
        $in(LifecycleState::OnLeave),
        $in(LifecycleState::Suspended),
        $in(LifecycleState::NoticePeriod),
        $in(LifecycleState::Exited, ['exit_date' => '2026-09-10']),
        // Rehire: the same employee record, back to active with a new joining date inside the period.
        $in(LifecycleState::Active, ['joining_date' => '2026-09-15', 'exit_date' => null]),
    ];

    $excluded = [
        // An RMS pre-employee has no joining date at all; the date filter alone let it through.
        $in(LifecycleState::PreEmployee, ['joining_date' => null]),
        // Preboarding with a joining date inside the period: hired, not yet joined.
        $in(LifecycleState::Preboarding, ['joining_date' => '2026-09-25']),
        $in(LifecycleState::Preboarding, ['joining_date' => null]),
        $in(LifecycleState::Onboarding, ['joining_date' => null]),
        $in(LifecycleState::Alumni, ['exit_date' => '2026-09-05']),
        $in(LifecycleState::Exited, ['exit_date' => '2026-08-20']),
    ];

    $run = $this->runs->open($this->company, 2026, 9);
    $population = $this->runs->population($run)->pluck('id')->sort()->values()->all();

    expect($population)->toBe(collect($expected)->pluck('id')->sort()->values()->all());
    foreach ($excluded as $employee) {
        expect($population)->not->toContain($employee->id);
    }

    // Calculation uses the same population: no entry for anyone who has not joined.
    $this->runs->calculate($run);
    expect($run->entries()->pluck('employee_id')->sort()->values()->all())->toBe($population)
        ->and(PayrollPeriod::query()->count())->toBe(1);
});
