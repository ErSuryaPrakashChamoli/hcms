<?php

use App\Domain\Attendance\Contracts\LeaveDayResolver;
use App\Domain\Attendance\Models\AttendancePunch;
use App\Domain\Attendance\Services\AttendanceProcessor;
use App\Domain\Audit\Models\AuditEvent;
use App\Domain\Employment\Actions\HireEmployeeAction;
use App\Domain\Identity\Services\AccessScopes;
use App\Domain\Integration\Services\ApiKeys;
use App\Domain\Leave\Models\LeaveBalance;
use App\Domain\Leave\Models\LeaveLedgerEntry;
use App\Domain\Leave\Models\LeaveRequest;
use App\Domain\Leave\Models\LeaveType;
use App\Domain\Leave\Services\LeaveAccrual;
use App\Domain\Leave\Services\LeaveAdjustments;
use App\Domain\Leave\Services\LeaveBalances;
use App\Domain\Leave\Services\LeaveEligibility;
use App\Domain\Leave\Services\LeaveOutput;
use App\Domain\Leave\Services\Leaves;
use App\Domain\Lifecycle\Enums\LifecycleState;
use App\Domain\Organisation\Models\Company;
use App\Domain\Organisation\Models\Location;
use App\Filament\Pages\LeaveCalendar;
use App\Filament\Resources\LeaveTransactions\LeaveTransactionResource;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

require_once __DIR__.'/LeaveTestHelpers.php';
require_once __DIR__.'/../Attendance/AttendanceTestHelpers.php';

/* Phase 3: eligibility, proration, ledger integrity, locking, cancellation review, attendance contract, payroll output, API and security. */

beforeEach(function () {
    $this->travelTo('2026-09-21 09:00:00'); // Monday
    $this->tenant = provisionTenant();
    $this->tenantB = provisionTenant('B');
    actAsTenant($this->tenant);
    $this->hr = tenantUser($this->tenant, ['*']);
    $this->actingAs($this->hr);
    leavePolicy([
        'EL' => ['days' => 24, 'accrual_frequency' => 'annual', 'eligible_after' => 'months', 'eligible_after_value' => 3, 'carry_forward_limit' => 10, 'carry_forward_expiry_months' => 3],
        'CL' => ['days' => 12, 'accrual_frequency' => 'annual', 'eligible_after' => 'confirmation', 'proration' => 'daily'],
        'SL' => ['days' => 12, 'accrual_frequency' => 'annual'],
    ]);
    $this->el = LeaveType::query()->where('code', 'EL')->first();
    $this->cl = LeaveType::query()->where('code', 'CL')->first();
    $this->sl = LeaveType::query()->where('code', 'SL')->first();
    $this->company = Company::factory()->create();
    $this->delhi = Location::factory()->create(['company_id' => $this->company->id]);
    $this->mumbai = Location::factory()->create(['company_id' => $this->company->id]);
    $this->schedule = weeklySchedule(generalShift());

    $this->managerUser = tenantUser($this->tenant, ['leave.view', 'leave.approve']);
    $this->manager = app(HireEmployeeAction::class)->handle(['first_name' => 'Mgr', 'last_name' => 'One'], ['joining_date' => '2024-01-01', 'user_id' => $this->managerUser->id, 'employee_code' => 'MGR1'], ['company_id' => $this->company->id, 'location_id' => $this->delhi->id]);
    $this->empUser = tenantUser($this->tenant, ['leave.apply']);
    $this->employee = app(HireEmployeeAction::class)->handle(['first_name' => 'Asha', 'last_name' => 'Rao'], ['joining_date' => '2025-01-01', 'user_id' => $this->empUser->id, 'employee_code' => 'EMP1'], ['company_id' => $this->company->id, 'location_id' => $this->mumbai->id], $this->manager->id);
    forceLifecycle($this->employee, LifecycleState::Active);
    $this->stranger = app(HireEmployeeAction::class)->handle(['first_name' => 'Ravi', 'last_name' => 'N'], ['joining_date' => '2025-01-01', 'employee_code' => 'EMP2'], ['company_id' => $this->company->id, 'location_id' => $this->mumbai->id]);
    forceLifecycle($this->stranger, LifecycleState::Active);
    foreach ([$this->employee, $this->stranger, $this->manager] as $e) {
        assignSchedule($e, $this->schedule);
        app(LeaveAccrual::class)->accrue($e);
    }
    $this->leaves = app(Leaves::class);
});

it('decides eligibility deterministically with a reason: lifecycle, service period, confirmation', function () {
    $check = app(LeaveEligibility::class);
    expect($check->check($this->employee, $this->el)->eligible)->toBeTrue();

    $new = app(HireEmployeeAction::class)->handle(['first_name' => 'New', 'last_name' => 'J'], ['joining_date' => '2026-08-01'], ['company_id' => $this->company->id]);
    forceLifecycle($new, LifecycleState::Active);
    $r = $check->check($new, $this->el, '2026-09-21');
    expect($r->eligible)->toBeFalse()->and($r->reason)->toContain('2026-11-01');
    expect($check->check($new, $this->el, '2026-11-01')->eligible)->toBeTrue();

    expect($check->check($this->employee, $this->cl)->reason)->toContain('after confirmation');
    forceLifecycle($this->employee, LifecycleState::Active, ['confirmation_date' => '2025-07-01']);
    expect($check->check($this->employee->fresh(), $this->cl)->eligible)->toBeTrue();

    forceLifecycle($new, LifecycleState::Suspended);
    expect($check->check($new->fresh(), $this->sl)->reason)->toContain('Suspended');
    forceLifecycle($new, LifecycleState::Preboarding);
    expect($check->check($new->fresh(), $this->sl)->eligible)->toBeFalse();

    expect(fn () => $this->leaves->request($new->fresh(), $this->sl, '2026-09-24', '2026-09-24', 'x'))->toThrow(RuntimeException::class, 'Preboarding');
});

it('pro-rates by remaining months or remaining days and never credits twice', function () {
    $joiner = app(HireEmployeeAction::class)->handle(['first_name' => 'Mid', 'last_name' => 'Year'], ['joining_date' => '2026-07-01'], ['company_id' => $this->company->id]);
    forceLifecycle($joiner, LifecycleState::Active);
    app(LeaveAccrual::class)->accrue($joiner);
    app(LeaveAccrual::class)->accrue($joiner);

    $el = app(LeaveBalances::class)->balance($joiner, $this->el, 2026);
    $cl = app(LeaveBalances::class)->balance($joiner, $this->cl, 2026);
    expect((float) $el->accrued)->toBe(12.0)                   // 6 of 12 months
        ->and((float) $cl->accrued)->toBe(round(12 * 184 / 365, 2)) // 184 of 365 days
        ->and(LeaveLedgerEntry::query()->where('employee_id', $joiner->id)->where('type', 'accrual')->count())->toBe(3);
});

it('keeps the ledger append-only and every balance movement explainable, including opening, expiry and carry-forward', function () {
    $entry = LeaveLedgerEntry::query()->where('employee_id', $this->employee->id)->first();
    expect(fn () => $entry->update(['days' => 99]))->toThrow(RuntimeException::class, 'append-only')
        ->and(fn () => $entry->delete())->toThrow(RuntimeException::class)
        ->and(fn () => LeaveLedgerEntry::query()->whereKey($entry->id)->delete())->toThrow(RuntimeException::class);

    $adj = app(LeaveAdjustments::class);
    expect($adj->opening($this->stranger, $this->sl, 3, 2026, 'Go-live balance'))->not->toBeNull()
        ->and($adj->opening($this->stranger, $this->sl, 3, 2026, 'Go-live balance'))->toBeNull();
    expect(AuditEvent::query()->where('action', 'LEAVE_BALANCE_ADJUSTED')->where('reason', 'Go-live balance')->count())->toBe(1);

    // Year end 2026: 24 EL unused -> 10 carried to 2027, 14 lapse; 3 months into 2027 the unused carried days expire.
    app(LeaveAccrual::class)->closeYear($this->employee, 2026);
    $this->travelTo('2027-02-10');
    $this->leaves->approve($this->leaves->request($this->employee, $this->el, '2027-02-15', '2027-02-17', 'Trip')); // 3 days used before expiry
    $this->travelTo('2027-04-02');
    expect(app(LeaveAccrual::class)->expireCarryForward($this->employee))->toBe(1)
        ->and(app(LeaveAccrual::class)->expireCarryForward($this->employee))->toBe(0);

    $types = LeaveLedgerEntry::query()->where('employee_id', $this->employee->id)->where('leave_type_id', $this->el->id)->pluck('days', 'type')->all();
    expect((float) $types['carry_forward'])->toBe(10.0)->and((float) $types['lapse'])->toBe(-14.0)->and((float) $types['expiry'])->toBe(-7.0);
    expect(AuditEvent::query()->whereIn('action', ['LEAVE_CARRIED_FORWARD', 'LEAVE_EXPIRED'])->get()->filter(fn ($e) => ($e->metadata['leave_type'] ?? null) === 'EL')->count())->toBe(3);

    // The cached balance equals the ledger sum.
    $sum = (float) LeaveLedgerEntry::query()->where('employee_id', $this->employee->id)->where('leave_type_id', $this->el->id)->where('period_year', 2027)->sum('days');
    expect((float) app(LeaveBalances::class)->recompute($this->employee, $this->el, 2027)->closing)->toBe(round($sum, 2));
});

it('reserves balance for pending requests, re-checks at approval and refuses what would go negative', function () {
    app(LeaveAdjustments::class)->adjust($this->stranger, $this->sl, -10, 'Used elsewhere'); // 2 SL left
    $a = $this->leaves->request($this->stranger, $this->sl, '2026-09-22', '2026-09-22', 'One');
    $b = $this->leaves->request($this->stranger, $this->sl, '2026-09-23', '2026-09-23', 'Two');
    expect(fn () => $this->leaves->request($this->stranger, $this->sl, '2026-09-24', '2026-09-24', 'Three'))->toThrow(RuntimeException::class, 'Insufficient');

    // An adjustment lands between request and approval: approval re-checks the committed balance under the lock.
    app(LeaveAdjustments::class)->adjust($this->stranger, $this->sl, -1, 'Correction');
    $this->leaves->approve($a);
    expect(fn () => $this->leaves->approve($b))->toThrow(RuntimeException::class, 'Insufficient');
    expect($b->fresh()->status)->toBe('pending')->and(app(LeaveBalances::class)->balance($this->stranger, $this->sl, 2026)->closing)->toEqual(0.0);

    // Double decision is refused after the lock re-reads the row.
    expect(fn () => $this->leaves->approve($a->fresh()))->toThrow(RuntimeException::class, 'already been decided');
});

it('reverses cancelled leave with compensating entries and supports cancellation review', function () {
    $this->el->update(['cancellation_policy' => 'approval']);
    $req = $this->leaves->request($this->employee, $this->el, '2026-09-28', '2026-09-30', 'Trip');
    $this->leaves->approve($req, 'ok', $this->managerUser);
    $used = (float) app(LeaveBalances::class)->balance($this->employee, $this->el, 2026)->used;

    $this->actingAs($this->empUser);
    $this->leaves->cancel($req->fresh(), 'Plans changed');
    expect($req->fresh()->status)->toBe('cancel_requested')
        ->and(app(LeaveDayResolver::class)->approvedLeaveOn($this->employee, Carbon::parse('2026-09-29')))->not->toBeNull(); // still leave until decided

    $this->actingAs($this->managerUser);
    $this->leaves->rejectCancellation($req->fresh(), 'Needed cover');
    expect($req->fresh()->status)->toBe('approved');

    $this->actingAs($this->empUser);
    $this->leaves->cancel($req->fresh(), 'Really changed');
    $this->actingAs($this->managerUser);
    $this->leaves->approveCancellation($req->fresh(), 'fine');

    $entries = LeaveLedgerEntry::query()->where('reference_id', $req->id)->pluck('days', 'type')->all();
    expect($req->fresh()->status)->toBe('cancelled')->and((float) $entries['usage'])->toBe(-3.0)->and((float) $entries['reversal'])->toBe(3.0)
        ->and((float) app(LeaveBalances::class)->balance($this->employee, $this->el, 2026)->used)->toBe($used - 3.0)
        ->and(app(LeaveDayResolver::class)->approvedLeaveOn($this->employee, Carbon::parse('2026-09-29')))->toBeNull()
        ->and(AuditEvent::query()->whereIn('action', ['LEAVE_REQUESTED', 'LEAVE_APPROVED', 'LEAVE_CANCEL_REQUESTED', 'LEAVE_CANCELLED'])->where('entity_id', (string) $req->id)->count())->toBe(5);

    $this->el->update(['cancellation_policy' => 'not_allowed']);
    $again = $this->leaves->request($this->employee, $this->el, '2026-10-05', '2026-10-05', 'x');
    $this->leaves->approve($again, null, $this->managerUser);
    $this->actingAs($this->empUser);
    expect(fn () => $this->leaves->cancel($again->fresh(), 'no'))->toThrow(RuntimeException::class, 'only be cancelled by HR');
    $this->actingAs($this->hr);
    expect($this->leaves->cancel($again->fresh(), 'HR override')->status)->toBe('cancelled');
});

it('feeds attendance only through LeaveDayResolver: pending and rejected leave are not leave; weekly off and holiday stay what they are', function () {
    $pending = $this->leaves->request($this->employee, $this->sl, '2026-09-22', '2026-09-22', 'Pending');
    $half = $this->leaves->request($this->employee, $this->el, '2026-09-23', '2026-09-23', 'Half', 'first_half', 'first_half');
    $this->leaves->approve($half);
    $rejected = $this->leaves->request($this->employee, $this->sl, '2026-09-24', '2026-09-24', 'No');
    $this->leaves->reject($rejected, 'No');

    $resolver = app(LeaveDayResolver::class);
    expect($resolver->approvedLeaveOn($this->employee, Carbon::parse('2026-09-22')))->toBeNull()
        ->and($resolver->approvedLeaveOn($this->employee, Carbon::parse('2026-09-23'))->session)->toBe('first_half')
        ->and($resolver->approvedLeaveOn($this->employee, Carbon::parse('2026-09-23'))->isPaid)->toBeTrue()
        ->and($resolver->approvedLeaveOn($this->employee, Carbon::parse('2026-09-24')))->toBeNull();

    $this->travelTo('2026-09-28 10:00:00');
    $processor = app(AttendanceProcessor::class);
    expect($processor->process($this->employee, '2026-09-22')->status)->toBe('absent')
        ->and($processor->process($this->employee, '2026-09-23')->is_half_day_leave)->toBeTrue()
        ->and($processor->process($this->employee, '2026-09-26')->status)->toBe('weekly_off');

    // Leave never touches raw punches.
    punch($this->employee, '2026-09-23 14:00:00');
    $count = AttendancePunch::query()->count();
    $this->leaves->cancel($half->fresh(), 'Came in');
    expect(AttendancePunch::query()->count())->toBe($count);
});

it('exposes paid, unpaid and reversed quantities for payroll without money', function () {
    $lwp = LeaveType::query()->where('category', 'unpaid')->first();
    $this->leaves->approve($this->leaves->request($this->employee, $this->el, '2026-09-22', '2026-09-23', 'Paid'));
    $this->leaves->approve($this->leaves->request($this->employee, $lwp, '2026-09-24', '2026-09-24', 'Unpaid'));
    $cancelled = $this->leaves->request($this->employee, $this->sl, '2026-09-25', '2026-09-25', 'Sick');
    $this->leaves->approve($cancelled);
    $this->leaves->cancel($cancelled->fresh(), 'Better');
    $this->leaves->cancel($this->leaves->request($this->employee, $this->sl, '2026-09-29', '2026-09-29', 'Withdrawn'), 'Withdrawn');

    $out = app(LeaveOutput::class)->forEmployee($this->employee, '2026-09-01', '2026-09-30');
    expect($out['EL'])->toMatchArray(['is_paid' => true, 'approved_days' => 2.0, 'paid_days' => 2.0, 'unpaid_days' => 0.0])
        ->and($out[$lwp->code])->toMatchArray(['is_paid' => false, 'unpaid_days' => 1.0, 'paid_days' => 0.0])
        ->and($out['SL']['reversed_days'])->toBe(1.0)->and($out['SL']['approved_days'])->toBe(0.0);
    expect(array_keys($out['EL']))->not->toContain('amount', 'salary', 'deduction');
});

it('scopes approvals, calendar and ledger to tenant, organisation and relationship scope', function () {
    $mine = $this->leaves->request($this->employee, $this->sl, '2026-09-22', '2026-09-22', 'Private reason');
    $theirs = $this->leaves->request($this->stranger, $this->sl, '2026-09-22', '2026-09-22', 'Other reason');
    app(AccessScopes::class)->assign($this->managerUser, ['location' => [$this->delhi->id]]);

    $this->actingAs($this->managerUser);
    expect($this->managerUser->can('approve', $mine))->toBeTrue()->and($this->managerUser->can('approve', $theirs))->toBeFalse()
        ->and(LeaveRequest::query()->find($theirs->id))->toBeNull();
    Livewire::test(LeaveCalendar::class)->assertCanSeeTableRecords([$mine])->assertCanNotSeeTableRecords([$theirs])->assertDontSee('Private reason');

    $this->actingAs($this->empUser);
    expect($this->empUser->can('approve', $mine))->toBeFalse()->and($this->empUser->can('view', $theirs))->toBeFalse()
        ->and(LeaveTransactionResource::getEloquentQuery()->pluck('employee_id')->unique()->values()->all())->toBe([$this->employee->id]);
    $this->get(LeaveCalendar::getUrl())->assertForbidden();

    actAsTenant($this->tenantB);
    $this->actingAs(tenantUser($this->tenantB, ['*']));
    expect(LeaveRequest::query()->count())->toBe(0)->and(LeaveBalance::query()->count())->toBe(0)->and(LeaveLedgerEntry::query()->count())->toBe(0);
});

it('serves the leave API inside the key tenant with idempotent submission and no reasons on the calendar', function () {
    $key = app(ApiKeys::class)->issue('hr', ['leave.read', 'leave.write'])['plaintext'];
    $readOnly = app(ApiKeys::class)->issue('ro', ['leave.read'])['plaintext'];
    actAsTenant($this->tenantB);
    $keyB = app(ApiKeys::class)->issue('B', ['leave.read', 'leave.write'])['plaintext'];
    actAsTenant(null);
    auth()->logout();

    $payload = ['employee_code' => 'EMP1', 'leave_type' => 'SL', 'from_date' => '2026-09-22', 'to_date' => '2026-09-23', 'reason' => 'Flu'];
    $first = $this->withHeaders(['X-Api-Key' => $key, 'Idempotency-Key' => 'req-1'])->postJson('/api/v1/leave/requests', $payload)->assertCreated()->assertJsonPath('data.days', 2);
    $this->withHeaders(['X-Api-Key' => $key, 'Idempotency-Key' => 'req-1'])->postJson('/api/v1/leave/requests', $payload)->assertOk()->assertJsonPath('data.id', $first->json('data.id'));
    $this->flushHeaders()->withHeaders(['X-Api-Key' => $key])->postJson('/api/v1/leave/requests', $payload)->assertStatus(422); // overlap without the key
    $this->withHeaders(['X-Api-Key' => $readOnly])->postJson('/api/v1/leave/requests', $payload)->assertForbidden();

    $id = $first->json('data.id');
    $this->withHeaders(['X-Api-Key' => $key])->getJson('/api/v1/leave/calendar?from=2026-09-01&to=2026-09-30')->assertOk()->assertJsonPath('meta.total', 1)->assertJsonMissing(['reason' => 'Flu']);
    $this->withHeaders(['X-Api-Key' => $key])->getJson("/api/v1/leave/requests/{$id}")->assertOk()->assertJsonPath('data.reason', 'Flu');
    $this->withHeaders(['X-Api-Key' => $keyB])->getJson("/api/v1/leave/requests/{$id}")->assertNotFound();
    $this->withHeaders(['X-Api-Key' => $keyB])->postJson("/api/v1/leave/requests/{$id}/cancel", ['reason' => 'x'])->assertNotFound();
    $this->withHeaders(['X-Api-Key' => $key])->postJson("/api/v1/leave/requests/{$id}/cancel", ['reason' => 'Recovered'])->assertOk()->assertJsonPath('data.status', 'cancelled');
    $this->withHeaders(['X-Api-Key' => $key])->postJson("/api/v1/leave/requests/{$id}/cancel", ['reason' => 'Recovered'])->assertOk()->assertJsonPath('data.status', 'cancelled');
    $this->withHeaders(['X-Api-Key' => $key])->getJson('/api/v1/leave/types')->assertOk()->assertJsonFragment(['code' => 'SL']);
    $this->withHeaders(['X-Api-Key' => $key])->getJson('/api/v1/leave/balances?employee_code=EMP1')->assertOk();
    $this->withHeaders(['X-Api-Key' => $key])->getJson('/api/v1/leave/transactions?employee_code=EMP1')->assertOk()->assertJsonFragment(['type' => 'accrual']);
});
