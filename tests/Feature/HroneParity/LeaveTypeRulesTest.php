<?php

use App\Domain\Employment\Models\EmployeeStatutoryDetail;
use App\Domain\Exit\Models\ExitCase;
use App\Domain\Leave\Models\LeaveType;
use App\Domain\Leave\Services\LeaveAccrual;
use App\Domain\Leave\Services\LeaveAdjustments;
use App\Domain\Leave\Services\LeaveBalances;
use App\Domain\Leave\Services\LeaveEligibility;
use App\Domain\Leave\Services\Leaves;
use App\Domain\Lifecycle\Enums\LifecycleState;

require_once __DIR__.'/../Leave/LeaveTestHelpers.php';
require_once __DIR__.'/../Attendance/AttendanceTestHelpers.php';
require_once __DIR__.'/../Workflow/WorkflowTestHelpers.php';

/* HROne setup parity TO-7: leave-type rules — eligibility, request limits, backdating per role, resignation, birthday window, clubbing, credits, carry forward, encashment. */

beforeEach(function () {
    $this->travelTo('2026-09-21 09:00:00'); // Monday
    $this->tenant = provisionTenant();
    actAsTenant($this->tenant);
    $this->hr = tenantUser($this->tenant, ['*']);
    $this->actingAs($this->hr);
    $this->manager = employeeWithUser();
    $this->employee = employeeWithUser($this->manager, ['leave.apply']);
    forceLifecycle($this->employee, LifecycleState::Active, ['joining_date' => '2025-01-01']);
    assignSchedule($this->employee, weeklySchedule(generalShift()));
    $this->leaves = app(Leaves::class);
    $this->el = LeaveType::query()->where('code', 'EL')->first();
    $this->cl = LeaveType::query()->where('code', 'CL')->first();
    $this->sl = LeaveType::query()->where('code', 'SL')->first();
    $this->apply = fn (LeaveType $type, string $from, ?string $to = null, $by = null) => $this->leaves->request($this->employee, $type, $from, $to ?? $from, 'Personal', requester: $by ?? $this->employee->user);
});

it('limits who may take the leave by ESI membership, age and marital status', function () {
    leavePolicy(['EL' => ['days' => 12, 'esi_applicability' => 'esi'], 'CL' => ['days' => 6, 'min_age' => 40], 'SL' => ['days' => 6, 'marital_status' => 'married']]);
    $eligibility = app(LeaveEligibility::class);
    $this->employee->person->update(['date_of_birth' => '1995-05-10', 'marital_status' => 'single']);

    expect($eligibility->check($this->employee->refresh(), $this->el)->reason)->toContain('only for ESI members')
        ->and($eligibility->check($this->employee, $this->cl)->reason)->toContain('at least 40 years old')
        ->and($eligibility->check($this->employee, $this->sl)->reason)->toContain('married employees');

    EmployeeStatutoryDetail::create(['employee_id' => $this->employee->id, 'esic_applicable' => true]);
    $this->employee->person->update(['date_of_birth' => '1980-05-10', 'marital_status' => 'married']);
    $this->employee->refresh();
    expect($eligibility->check($this->employee, $this->el)->eligible)->toBeTrue()->and($eligibility->check($this->employee, $this->cl)->eligible)->toBeTrue()
        ->and($eligibility->check($this->employee, $this->sl)->eligible)->toBeTrue();
});

it('caps requests per month and year, applies backdating per role and future dates, and refuses while resigning', function () {
    leavePolicy(['EL' => ['days' => 24, 'max_requests_per_month' => 2, 'max_days_per_year' => 3, 'backdated_days' => 2, 'manager_backdated_days' => 10, 'future_allowed' => 'yes', 'not_while_resigning' => true],
        'CL' => ['days' => 6, 'future_allowed' => 'no']]);
    app(LeaveAccrual::class)->accrue($this->employee);

    expect(fn () => ($this->apply)($this->el, '2026-09-15'))->toThrow(RuntimeException::class, 'at most 2 day(s) back')
        ->and(($this->apply)($this->el, '2026-09-14', null, $this->manager->user)->status)->toBe('pending'); // the manager may go back 10 days
    ($this->apply)($this->el, '2026-09-23');
    expect(fn () => ($this->apply)($this->el, '2026-09-24'))->toThrow(RuntimeException::class, 'at most 2 time(s) a month')
        ->and(fn () => ($this->apply)($this->el, '2026-10-05', '2026-10-07'))->toThrow(RuntimeException::class, 'at most 3 day(s) a year')
        ->and(fn () => ($this->apply)($this->cl, '2026-09-25'))->toThrow(RuntimeException::class, 'cannot be applied for future days');

    ExitCase::query()->create(['number' => 'EX-1', 'employee_id' => $this->employee->id, 'type' => 'resignation', 'status' => 'notice', 'initiated_on' => '2026-09-20', 'resignation_date' => '2026-09-20', 'notice_days' => 60, 'last_working_day' => '2026-11-19']);
    expect(fn () => ($this->apply)($this->el, '2026-11-02'))->toThrow(RuntimeException::class, 'while a resignation is pending');
});

it('keeps a birthday leave to its window and refuses leave taken next to types it cannot be clubbed with', function () {
    leavePolicy(['SL' => ['days' => 2, 'birthday_window_before' => 2, 'birthday_window_after' => 5], 'CL' => ['days' => 6, 'not_clubbed_with' => ['EL']], 'EL' => ['days' => 12]]);
    app(LeaveAccrual::class)->accrue($this->employee);
    $this->employee->person->update(['date_of_birth' => '1990-10-14']);
    $this->employee->refresh();

    expect(fn () => ($this->apply)($this->sl, '2026-10-05'))->toThrow(RuntimeException::class, 'from 12 Oct to 19 Oct')
        ->and(($this->apply)($this->sl, '2026-10-15')->status)->toBe('pending');

    ($this->apply)($this->el, '2026-10-21');
    expect(fn () => ($this->apply)($this->cl, '2026-10-22'))->toThrow(RuntimeException::class, 'cannot be taken next to')
        ->and(($this->apply)($this->cl, '2026-10-26')->status)->toBe('pending');
});

it('rounds and caps credits, carries forward all or none, and limits encashment per request', function () {
    leavePolicy(['EL' => ['days' => 13, 'accrual_frequency' => 'monthly', 'credit_round_off' => 'half', 'credit_cap' => 5, 'carry_forward_mode' => 'all', 'encashment_allowed' => true, 'max_encash_days' => 10,
        'max_encash_per_request' => 2, 'min_balance_after_encash' => 4], 'CL' => ['days' => 6, 'carry_forward_mode' => 'zero', 'carry_forward_limit' => 6]]);
    LeaveType::query()->where('code', 'EL')->update(['is_encashable' => true]);
    app(LeaveAccrual::class)->accrue($this->employee);
    $balances = app(LeaveBalances::class);

    // 13 / 12 = 1.08 a month rounds to 1.0; nine months would be 9, the cap keeps it at 5.
    expect($balances->balance($this->employee, $this->el->refresh(), 2026)->available())->toBe(5.0);

    app(LeaveAccrual::class)->closeYear($this->employee, 2026);
    expect($balances->balance($this->employee, $this->el, 2027)->available())->toBe(5.0)   // all of it carried
        ->and($balances->balance($this->employee, $this->cl, 2027)->available())->toBe(0.0); // none, although the limit is 6

    $adjust = app(LeaveAdjustments::class);
    expect(fn () => $adjust->requestEncashment($this->employee, $this->el, 3, null, 2027))->toThrow(RuntimeException::class, 'At most 2 day(s)')
        ->and(fn () => $adjust->requestEncashment($this->employee, $this->el, 2, null, 2027))->toThrow(RuntimeException::class, 'Keep at least 4 day(s)')
        ->and($adjust->requestEncashment($this->employee, $this->el, 1, null, 2027)->status)->toBe('pending');
});
