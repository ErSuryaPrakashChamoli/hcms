<?php

use App\Domain\Analytics\Services\DatasetRegistry;
use App\Domain\Analytics\Services\ReportLibrary;
use App\Domain\Analytics\Services\ReportRunner;
use App\Domain\Attendance\Contracts\PaidDayAdjustmentReader;
use App\Domain\Attendance\Models\AttendanceArrear;
use App\Domain\Attendance\Models\AttendancePenalty;
use App\Domain\Attendance\Models\AttendanceRecord;
use App\Domain\Attendance\Models\PaidDayAdjustment;
use App\Domain\Attendance\Services\AttendanceMonthCloses;
use App\Domain\Attendance\Services\AttendanceOutput;
use App\Domain\Attendance\Services\PaidDayAdjustments;
use App\Domain\Attendance\Services\PaidDaysRegister;
use App\Domain\Attendance\Support\PaidDayTally;
use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Audit\Models\AuditEvent;
use App\Domain\Configuration\Models\Policy;
use App\Domain\Configuration\Models\PolicyAssignmentRule;
use App\Domain\Configuration\Services\Policies;
use App\Domain\Employment\Actions\HireEmployeeAction;
use App\Domain\Employment\Models\Employee;
use App\Domain\Experience\Models\BulkRun;
use App\Domain\Experience\Models\BulkRunItem;
use App\Domain\Identity\Services\AccessScopes;
use App\Domain\Offboarding\Support\DataInventory;
use App\Domain\Organisation\Models\Company;
use App\Domain\Organisation\Models\Location;
use App\Domain\Payroll\Models\PayrollAdjustment;
use App\Domain\Payroll\Models\PayrollPeriod;
use App\Domain\Payroll\Services\AttendancePaidDayBasis;
use App\Domain\Payroll\Services\PayrollCalculator;
use App\Domain\Platform\Services\SettingsRepository;
use App\Filament\Pages\PaidDays;
use App\Filament\Pages\RunAttendance;
use App\Filament\Pages\TimeOfficeDesk;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Livewire\Livewire;

require_once __DIR__.'/../Workflow/WorkflowTestHelpers.php';
require_once __DIR__.'/../Payroll/PayrollTestHelpers.php';

/*
| HROne parity TX-4 (matrix 24 §24.5.3 #17–21, §24.5.4 #1–4, 6): the Paid days register for an attendance month and the
| financial year to date, the per-employee breakdown and the review summary — and, behind
| attendance.paid_day_adjustments.enabled (owner decision TIM-2 / D-TX2, off by default), HR's paid-day adjustments (set the
| counts, or reduce chosen days with the attendance policy's rounding) read by AttendanceOutput and payroll as quantities.
*/

beforeEach(function () {
    $this->travelTo('2026-11-03 10:00:00'); // October 2026 has ended
    $this->tenant = provisionTenant();
    actAsTenant($this->tenant);
    $this->hr = tenantUser($this->tenant, ['*']);
    $this->actingAs($this->hr);
    Filament::getPanel('admin')->boot();
    $this->company = Company::factory()->create(['name' => 'Acme India']);
    $this->delhi = Location::factory()->create(['company_id' => $this->company->id, 'name' => 'Delhi']);
    $this->mumbai = Location::factory()->create(['company_id' => $this->company->id, 'name' => 'Mumbai']);
    $this->asha = tx4Hire('Asha', $this->delhi);
    $this->ravi = tx4Hire('Ravi', $this->mumbai);
    // Asha's October: 16 working days present, the 31st (a Saturday) worked, 2 absent, 1 paid leave, 1 unpaid leave, a half day.
    tx4Month($this->asha, [
        '2026-10-06' => ['status' => 'absent', 'worked_minutes' => 0], '2026-10-07' => ['status' => 'absent', 'worked_minutes' => 0],
        '2026-10-08' => ['status' => 'leave', 'worked_minutes' => 0], '2026-10-09' => ['status' => 'unpaid_leave', 'worked_minutes' => 0],
        '2026-10-13' => ['status' => 'half_day', 'worked_minutes' => 240],
        '2026-10-31' => ['status' => 'present', 'worked_minutes' => 300, 'exceptions' => ['holiday_work']],
    ]);
    tx4Month($this->ravi);
    AttendancePenalty::create(['employee_id' => $this->asha->id, 'date' => '2026-10-20', 'month' => '2026-10', 'action' => 'half_day', 'days' => 0.5, 'late_count' => 3, 'status' => 'applied']);
    AttendanceArrear::create(['employee_id' => $this->asha->id, 'date' => '2026-10-15', 'status_before' => 'present', 'status_after' => 'absent', 'paid_before' => 1, 'paid_after' => 0, 'delta_days' => -1, 'status' => 'pending']);
    AttendanceArrear::create(['employee_id' => $this->asha->id, 'date' => '2026-10-16', 'status_before' => 'present', 'status_after' => 'absent', 'paid_before' => 1, 'paid_after' => 0, 'delta_days' => -1, 'status' => 'void']);
    $this->adjustments = app(PaidDayAdjustments::class);
    $this->register = app(PaidDaysRegister::class);
});

function tx4Hire(string $first, Location $location, array $employment = []): Employee
{
    return app(HireEmployeeAction::class)->handle(['first_name' => $first, 'last_name' => 'Test'], ['joining_date' => '2024-03-01'] + $employment,
        ['company_id' => $location->company_id, 'location_id' => $location->id]);
}

/** October 2026 processed: weekends weekly off, 2 Oct a holiday, other days present (8 h), with changes by date. */
function tx4Month(Employee $employee, array $changes = [], int $year = 2026, int $month = 10): void
{
    $first = CarbonImmutable::create($year, $month, 1);
    for ($day = $first; $day->month === $month; $day = $day->addDay()) {
        $date = $day->toDateString();
        $row = match (true) {
            $day->isWeekend() => ['status' => 'weekly_off', 'worked_minutes' => 0],
            $date === '2026-10-02' => ['status' => 'holiday', 'worked_minutes' => 0, 'holiday_name' => 'Gandhi Jayanti'],
            default => ['status' => 'present', 'worked_minutes' => 480, 'scheduled_minutes' => 480],
        };
        AttendanceRecord::create(['employee_id' => $employee->id, 'date' => $date, 'processed_at' => now()] + ($changes[$date] ?? []) + $row);
    }
}

function tx4Setting(string $key, mixed $value): void
{
    app(SettingsRepository::class)->set($key, $value, 'test');
}

function tx4Policy(array $settings): Policy
{
    $policy = Policy::create(['type' => 'attendance', 'name' => 'Standard attendance', 'code' => 'TX4_ATT']);
    app(Policies::class)->draft($policy, $settings);
    app(Policies::class)->publish($policy, '2026-01-01');
    PolicyAssignmentRule::create(['policy_type' => 'attendance', 'policy_id' => $policy->id, 'name' => 'All', 'conditions' => []]);

    return $policy;
}

/** The reduce input for "a share of weekly offs and all paid leave". */
function tx4Reduce(float $weeklyOffPercent = 30): array
{
    return ['mode' => 'reduce', 'categories' => ['weekly_off' => ['how' => 'partial', 'percent' => $weeklyOffPercent], 'paid_leave' => ['how' => 'full']]];
}

// ---- Defaults: switched off --------------------------------------------------------------------------------------------

it('changes nothing while paid-day adjustments are off: AttendanceOutput is byte-identical and nothing is offered', function () {
    $output = app(AttendanceOutput::class);
    $before = $output->summary($this->asha, '2026-10-01', '2026-10-31');
    $days = $output->forEmployee($this->asha, '2026-10-01', '2026-10-31')->map->toArray()->all();

    // A row left behind (e.g. from before the switch went off) has no effect.
    PaidDayAdjustment::create(['employee_id' => $this->asha->id, 'company_id' => $this->company->id, 'year' => 2026, 'month' => 10, 'start_date' => '2026-10-01',
        'end_date' => '2026-10-31', 'mode' => 'reduce', 'categories' => ['weekly_off' => ['how' => 'full']], 'reason' => 'Left behind', 'created_by' => $this->hr->id]);

    expect(config('peopleos.settings')['attendance.paid_day_adjustments.enabled'])->toBeFalse()
        ->and($output->summary($this->asha, '2026-10-01', '2026-10-31'))->toBe($before)
        ->and(array_keys($before))->toBe(['days', 'paid_days', 'half_days', 'absent_days', 'worked_minutes', 'scheduled_minutes', 'late_minutes', 'early_minutes', 'approved_overtime_minutes', 'unprocessed_days', 'penalty_days'])
        ->and($output->forEmployee($this->asha, '2026-10-01', '2026-10-31')->map->toArray()->all())->toBe($days)
        ->and($before['paid_days'])->toBe(27.5)
        ->and(app(PaidDayAdjustmentReader::class)->inUse())->toBeFalse()
        ->and(app(PaidDayAdjustmentReader::class)->forMonth($this->asha->id, $this->company->id, 2026, 10))->toBeNull();

    $figures = $this->register->figures([$this->asha->id], $this->company->id, $this->register->windows($this->company->id, 2026, 10))[$this->asha->id]['total'];
    expect($figures['adjustment'])->toBe(0.0)->and($figures['paid_days_processed'])->toBe(27.5)->and($figures['paid_days'])->toBe(27.0);

    expect(fn () => $this->adjustments->save($this->asha, $this->company->id, 2026, 10, tx4Reduce(), 'Late marks', $this->hr))->toThrow(RuntimeException::class, 'switched off');

    Livewire::withQueryParams(['company' => (string) $this->company->id, 'month' => '2026-10'])->test(PaidDays::class)
        ->assertOk()->assertTableActionHidden('adjust', $this->asha)->assertTableBulkActionHidden('adjustSelected')
        ->assertTableColumnHidden('adjustment')
        ->assertSee('once "HR may adjust paid days" is switched on', false);
});

// ---- Set and reduce, rounding, preview ---------------------------------------------------------------------------------

it('previews and saves a reduction with the attendance policy\'s rounding, and AttendanceOutput reads it as a quantity', function () {
    tx4Setting('attendance.paid_day_adjustments.enabled', true);
    tx4Policy(['paid_days_round_off' => 'up_half']);

    $preview = $this->adjustments->preview($this->asha, $this->company->id, 2026, 10, tx4Reduce(), $this->hr);
    expect($preview['refusal'])->toBeNull()
        ->and($preview['counts'])->toMatchArray(['present' => 17.5, 'paid_leave' => 1.0, 'holiday' => 1.0, 'weekly_off' => 8.0, 'unpaid_leave' => 1.0, 'absent' => 2.5, 'half_days' => 1, 'paid_days' => 27.5, 'penalty_days' => 0.5])
        ->and($preview['rounding'])->toBe('up_half')
        // 30% of 8 weekly offs is 2.4, rounded up to a half day: 2.5; all paid leave: 1.
        ->and($preview['effect']['lines']['weekly_off'])->toMatchArray(['count' => 8.0, 'after' => 5.5])
        ->and($preview['effect']['lines']['paid_leave'])->toMatchArray(['count' => 1.0, 'after' => 0.0])
        ->and($preview['effect'])->toMatchArray(['before' => 27.5, 'after' => 24.0, 'change' => -3.5])
        ->and(PaidDayAdjustment::query()->count())->toBe(0); // a preview saves nothing

    expect(fn () => $this->adjustments->save($this->asha, $this->company->id, 2026, 10, tx4Reduce(), '  ', $this->hr))->toThrow(RuntimeException::class, 'Give the reason');
    $saved = $this->adjustments->save($this->asha, $this->company->id, 2026, 10, tx4Reduce(), 'Late marks policy', $this->hr);
    expect($saved->only(['mode', 'status', 'rounding', 'reason']))->toBe(['mode' => 'reduce', 'status' => 'active', 'rounding' => 'up_half', 'reason' => 'Late marks policy'])
        ->and((float) $saved->paid_days_before)->toBe(27.5)->and((float) $saved->paid_days_after)->toBe(24.0)
        ->and(AuditEvent::query()->withoutGlobalScopes()->where('entity_type', PaidDayAdjustment::class)->where('entity_id', $saved->id)->where('reason', 'Late marks policy')->exists())->toBeTrue();

    $summary = app(AttendanceOutput::class)->summary($this->asha, '2026-10-01', '2026-10-31');
    expect($summary['paid_days'])->toBe(24.0)->and($summary['paid_days_by_attendance'])->toBe(27.5)->and($summary['paid_day_adjustment'])->toBe(-3.5)
        // A range that does not hold the whole month is left as processed.
        ->and(app(AttendanceOutput::class)->summary($this->asha, '2026-10-01', '2026-10-20')['paid_day_adjustment'])->toBe(0.0);

    // Without a rounding in the policy: two decimals (30% of 9 weekly offs = 2.7).
    $ravi = $this->adjustments->counts($this->ravi, CarbonImmutable::parse('2026-10-01'), CarbonImmutable::parse('2026-10-31'));
    expect($this->adjustments->effect($ravi, 'reduce', ['weekly_off' => ['how' => 'partial', 'percent' => 30]], null, null)['change'])->toBe(-2.7)
        ->and(PaidDayTally::round(2.4, 'down_half'))->toBe(2.0)->and(PaidDayTally::round(2.4, 'nearest_whole'))->toBe(2.0)
        ->and(PaidDayTally::round(2.6, 'up_whole'))->toBe(3.0)->and(PaidDayTally::round(2.345, null))->toBe(2.35);

    // Refused inputs.
    expect(fn () => $this->adjustments->save($this->asha, $this->company->id, 2026, 10, ['mode' => 'reduce', 'categories' => []], 'x', $this->hr))->toThrow(RuntimeException::class, 'at least one category')
        ->and(fn () => $this->adjustments->save($this->asha, $this->company->id, 2026, 10, ['mode' => 'reduce', 'categories' => ['present' => ['how' => 'partial']]], 'x', $this->hr))->toThrow(RuntimeException::class, 'from 0 to 100%')
        ->and(fn () => $this->adjustments->save($this->asha, $this->company->id, 2026, 10, ['mode' => 'set', 'categories' => ['present' => 40]], 'x', $this->hr))->toThrow(RuntimeException::class, 'from 0 to 31')
        ->and(fn () => $this->adjustments->save($this->asha, $this->company->id, 2026, 10, ['mode' => 'other'], 'x', $this->hr))->toThrow(RuntimeException::class, 'Choose how');
});

it('sets the month\'s counts, replacing the adjustment counting until then, and refuses one\'s own paid days', function () {
    tx4Setting('attendance.paid_day_adjustments.enabled', true);
    $first = $this->adjustments->save($this->asha, $this->company->id, 2026, 10, tx4Reduce(), 'Late marks', $this->hr);
    $set = $this->adjustments->save($this->asha, $this->company->id, 2026, 10, ['mode' => 'set', 'categories' => ['present' => 20, 'paid_leave' => 1, 'holiday' => 1, 'weekly_off' => 9]], 'Agreed with the manager', $this->hr);

    expect($first->refresh()->status)->toBe('reversed')->and($first->active)->toBeNull()->and($first->reversal_reason)->toBe('Replaced by a newer adjustment: Agreed with the manager')
        ->and((float) $set->value)->toBe(31.0)->and($set->describe())->toBe('Set: Present days 20, Paid leave 1, Holidays 1, Weekly offs 9')
        ->and(PaidDayAdjustment::query()->where('status', 'active')->count())->toBe(1)
        ->and(app(AttendanceOutput::class)->summary($this->asha, '2026-10-01', '2026-10-31'))->toMatchArray(['paid_days' => 31.0, 'paid_days_by_attendance' => 27.5, 'paid_day_adjustment' => 3.5]);

    // A category left blank keeps the processed count; the total alone (TX-13's upload) works too.
    $counts = $this->adjustments->counts($this->asha, CarbonImmutable::parse('2026-10-01'), CarbonImmutable::parse('2026-10-31'));
    expect($this->adjustments->effect($counts, 'set', ['present' => 18], null, null)['after'])->toBe(28.0)
        ->and($this->adjustments->effect($counts, 'set', [], 26.0, null)['after'])->toBe(26.0);

    $mine = tx4Hire('Hema', $this->delhi, ['user_id' => $this->hr->id]);
    tx4Month($mine);
    expect(fn () => $this->adjustments->save($mine, $this->company->id, 2026, 10, tx4Reduce(), 'x', $this->hr))->toThrow(RuntimeException::class, 'your own paid days')
        ->and(fn () => $this->adjustments->save($this->asha, $this->company->id, 2026, 10, tx4Reduce(), 'x', tenantUser($this->tenant, ['attendance.view'])))->toThrow(RuntimeException::class, 'cannot adjust paid days');
    // attendance.manage still grants the narrower attendance.override (TX-2).
    expect($this->adjustments->refusal($this->asha, $this->company->id, 2026, 10, tenantUser($this->tenant, ['attendance.view', 'attendance.manage'])))->toBeNull();
});

// ---- Locked and closed months -------------------------------------------------------------------------------------------

it('refuses finalized, locked and closed months, and a closed month only while corrections after lock are off', function () {
    tx4Setting('attendance.paid_day_adjustments.enabled', true);
    tx4Month($this->asha, [], 2026, 9);
    tx4Month($this->asha, [], 2026, 8);
    PayrollPeriod::create(['company_id' => $this->company->id, 'year' => 2026, 'month' => 9, 'start_date' => '2026-09-01', 'end_date' => '2026-09-30', 'status' => 'closed']);
    AttendanceRecord::query()->where('employee_id', $this->asha->id)->whereDate('date', '2026-08-14')->update(['is_locked' => true]);

    expect(fn () => $this->adjustments->save($this->asha, $this->company->id, 2026, 9, tx4Reduce(), 'x', $this->hr))->toThrow(RuntimeException::class, 'Sep 2026 is finalized')
        ->and(fn () => $this->adjustments->save($this->asha, $this->company->id, 2026, 8, tx4Reduce(), 'x', $this->hr))->toThrow(RuntimeException::class, 'locked for good');

    $october = $this->adjustments->save($this->asha, $this->company->id, 2026, 10, tx4Reduce(), 'Before the close', $this->hr);
    tx4Setting('attendance.month_close.enabled', true);
    app(AttendanceMonthCloses::class)->close($this->company->id, 2026, 10, $this->hr);
    expect(fn () => $this->adjustments->save($this->ravi, $this->company->id, 2026, 10, tx4Reduce(), 'x', $this->hr))->toThrow(RuntimeException::class, 'closed for attendance')
        ->and(fn () => $this->adjustments->reverse($october, $this->hr, 'Undo'))->toThrow(RuntimeException::class, 'closed for attendance');

    tx4Setting('attendance.allow_corrections_after_lock', true);
    expect($this->adjustments->save($this->ravi, $this->company->id, 2026, 10, tx4Reduce(), 'Allowed after the close', $this->hr)->isActive())->toBeTrue();
});

// ---- Reversal ---------------------------------------------------------------------------------------------------------------

it('reverses an adjustment with a reason, keeps the row and puts the month back as processed', function () {
    tx4Setting('attendance.paid_day_adjustments.enabled', true);
    $adjustment = $this->adjustments->save($this->asha, $this->company->id, 2026, 10, tx4Reduce(), 'Late marks', $this->hr);

    expect(fn () => $this->adjustments->reverse($adjustment, $this->hr, ''))->toThrow(RuntimeException::class, 'reason');
    $this->adjustments->reverse($adjustment, $this->hr, 'Entered for the wrong month');
    expect($adjustment->refresh()->only(['status', 'reversal_reason']))->toBe(['status' => 'reversed', 'reversal_reason' => 'Entered for the wrong month'])
        ->and($adjustment->active)->toBeNull()->and((int) $adjustment->reversed_by)->toBe($this->hr->id)
        ->and(PaidDayAdjustment::query()->count())->toBe(1)
        ->and(app(AttendanceOutput::class)->summary($this->asha, '2026-10-01', '2026-10-31'))->toMatchArray(['paid_days' => 27.5, 'paid_day_adjustment' => 0.0])
        ->and(AuditEvent::query()->withoutGlobalScopes()->where('entity_type', PaidDayAdjustment::class)->where('reason', 'Entered for the wrong month')->exists())->toBeTrue();
    expect(fn () => $this->adjustments->reverse($adjustment, $this->hr, 'Again'))->toThrow(RuntimeException::class, 'no longer counting');

    // From the page's breakdown.
    $again = $this->adjustments->save($this->asha, $this->company->id, 2026, 10, tx4Reduce(), 'Late marks again', $this->hr);
    Livewire::withQueryParams(['company' => (string) $this->company->id, 'month' => '2026-10', 'detail' => (string) $this->asha->id])->test(PaidDays::class)
        ->assertSee('Late marks again')
        ->callAction('reverseAdjustment', data: ['reason' => 'Manager withdrew it'], arguments: ['adjustment' => $again->id])->assertNotified('Adjustment reversed');
    expect($again->refresh()->status)->toBe('reversed');
});

// ---- Payroll basis -------------------------------------------------------------------------------------------------------

it('lets payroll read the adjustment as loss-of-pay days through the contract and warns when its own LOP touches the same month', function () {
    syncComplianceRules();
    payrollCompany();
    $employee = salariedEmployee(600000);
    for ($d = CarbonImmutable::parse('2026-10-01'); $d->month === 10; $d = $d->addDay()) {
        AttendanceRecord::create(['employee_id' => $employee->id, 'date' => $d->toDateString(), 'status' => 'present', 'worked_minutes' => 480, 'processed_at' => now()]);
    }
    $period = PayrollPeriod::for($this->company, 2026, 10);
    $calculator = app(PayrollCalculator::class);

    $off = $calculator->calculate($employee, $period);
    expect($off->lopDays)->toBe(0.0)->and($off->paidDays)->toBe(31.0)->and($off->inputs)->not->toHaveKey('attendance_paid_day_adjustment');

    tx4Setting('attendance.paid_day_adjustments.enabled', true);
    expect($calculator->calculate($employee, $period)->inputs)->toBe($off->inputs); // on, but nothing adjusted: the same
    $this->adjustments->save($employee, $this->company->id, 2026, 10, ['mode' => 'set', 'categories' => ['present' => 29]], 'Two days not worked', $this->hr);

    $on = $calculator->calculate($employee, $period);
    expect($on->lopDays)->toBe(2.0)->and($on->paidDays)->toBe(29.0)
        ->and($on->inputs['attendance_paid_day_adjustment'])->toMatchArray(['change' => -2.0, 'paid_days_before' => 31.0, 'paid_days_after' => 29.0, 'lop_days' => 2.0, 'reason' => 'Two days not worked'])
        ->and(collect($on->exceptions)->pluck('type')->all())->not->toContain(AttendancePaidDayBasis::EXCEPTION);

    // Payroll's own loss-of-pay adjustment stays and counts too; the entry is flagged.
    PayrollAdjustment::create(['employee_id' => $employee->id, 'payroll_period_id' => $period->id, 'type' => 'lop', 'name' => 'Unauthorised absence', 'amount' => 1]);
    $both = $calculator->calculate($employee, $period);
    $warning = collect($both->exceptions)->firstWhere('type', AttendancePaidDayBasis::EXCEPTION);
    expect($both->lopDays)->toBe(3.0)->and($both->inputs['manual_lop'])->toBe(1.0)
        ->and($warning['message'])->toContain('Loss of pay was adjusted in payroll (1 day)')->toContain('Oct 2026')->toContain('Both count')
        ->and($warning)->not->toHaveKey('blocking')
        ->and(config('peopleos.payroll.exception_types.'.AttendancePaidDayBasis::EXCEPTION))->not->toBeNull();

    // Payroll reads attendance only through the contract.
    $source = file_get_contents(app_path('Domain/Payroll/Services/AttendancePaidDayBasis.php'));
    expect($source)->toContain('PaidDayAdjustmentReader')->not->toContain('Models\PaidDayAdjustment')->not->toContain('PaidDaysRegister');
    expect(app(PaidDayAdjustmentReader::class))->toBeInstanceOf(PaidDayAdjustments::class);
});

it('gives raised paid days back from the last salary segment backwards, so the segments pay what the entry says', function () {
    syncComplianceRules();
    payrollCompany();
    $employee = salariedEmployee(600000);
    compensate($employee, 720000, '2026-10-16', ['CONV' => 1600], 'revision', 'Mid-month increment');
    for ($d = CarbonImmutable::parse('2026-10-01'); $d->month === 10; $d = $d->addDay()) {
        $absent = in_array($d->toDateString(), ['2026-10-05', '2026-10-06'], true); // two days of loss of pay in the first segment
        AttendanceRecord::create(['employee_id' => $employee->id, 'date' => $d->toDateString(), 'status' => $absent ? 'absent' : 'present', 'worked_minutes' => $absent ? 0 : 480, 'processed_at' => now()]);
    }
    $period = PayrollPeriod::for($this->company, 2026, 10);
    $calculator = app(PayrollCalculator::class);
    $agree = function ($c, float $lop) {
        expect($c->inputs['segments'])->toHaveCount(2)
            ->and($c->lopDays)->toBe($lop)->and($c->paidDays)->toBe(31.0 - $lop)
            ->and(round(array_sum(array_column($c->inputs['segments'], 'lop')), 2))->toBe($c->lopDays)
            ->and(round(array_sum(array_column($c->inputs['segments'], 'paid_units')), 2))->toBe($c->paidDays)
            ->and(collect($c->inputs['segments'])->every(fn (array $s) => $s['lop'] >= 0))->toBeTrue();
    };

    // Off: the dates' loss of pay sits in the first segment, as before.
    $off = $calculator->calculate($employee, $period);
    $agree($off, 2.0);
    expect(array_column($off->inputs['segments'], 'lop'))->toBe([2.0, 0.0]);

    // On, set two days above the processed count: the last segment has nothing to give back, so the first one does.
    tx4Setting('attendance.paid_day_adjustments.enabled', true);
    $this->adjustments->save($employee, $this->company->id, 2026, 10, ['mode' => 'set', 'categories' => ['present' => 31]], 'Worked from a client site', $this->hr);
    $raised = $calculator->calculate($employee, $period);
    $agree($raised, 0.0);
    expect(array_column($raised->inputs['segments'], 'lop'))->toBe([0.0, 0.0])
        ->and($raised->amount('BASIC'))->toEqualWithDelta(round(20000 * 15 / 31 + 24000 * 16 / 31, 2), 0.02);

    // One day raised: one of the two days comes back.
    $this->adjustments->save($employee, $this->company->id, 2026, 10, ['mode' => 'set', 'categories' => ['present' => 30]], 'One day worked from a client site', $this->hr);
    $one = $calculator->calculate($employee, $period);
    $agree($one, 1.0);
    expect(array_column($one->inputs['segments'], 'lop'))->toBe([1.0, 0.0])
        ->and($one->amount('BASIC'))->toEqualWithDelta(round(20000 * 14 / 31 + 24000 * 16 / 31, 2), 0.02);

    // Fewer paid days still go on the last segment.
    $this->adjustments->save($employee, $this->company->id, 2026, 10, ['mode' => 'set', 'categories' => ['present' => 27]], 'Two more days not worked', $this->hr);
    $lower = $calculator->calculate($employee, $period);
    $agree($lower, 4.0);
    expect(array_column($lower->inputs['segments'], 'lop'))->toBe([2.0, 2.0]);
});

// ---- Register, breakdown, scope ------------------------------------------------------------------------------------------

it('keeps the register\'s figures equal to AttendanceOutput\'s summary, month and financial year to date', function () {
    tx4Setting('attendance.paid_day_adjustments.enabled', true);
    tx4Month($this->asha, [], 2026, 9);
    $this->adjustments->save($this->asha, $this->company->id, 2026, 10, tx4Reduce(), 'Late marks', $this->hr);

    $month = $this->register->figures([$this->asha->id, $this->ravi->id], $this->company->id, $this->register->windows($this->company->id, 2026, 10));
    foreach ([$this->asha, $this->ravi] as $employee) {
        $summary = app(AttendanceOutput::class)->summary($employee, '2026-10-01', '2026-10-31');
        $row = $month[$employee->id]['total'];
        expect($row['paid_days_adjusted'])->toBe($summary['paid_days'])
            ->and($row['paid_days_processed'])->toBe($summary['paid_days_by_attendance'])
            ->and($row['adjustment'])->toBe($summary['paid_day_adjustment'])
            ->and($row['penalty_days'])->toBe($summary['penalty_days'])
            ->and($row['days'])->toBe((float) $summary['days'])->and($row['half_days'])->toBe((float) $summary['half_days'])
            ->and($row['absent'] + $row['unpaid_leave'])->toBe((float) $summary['absent_days'] + 0.5 * $summary['half_days']) // the absent half of a half day
            ->and($row['paid_days'])->toBe(max(0.0, $summary['paid_days'] - $summary['penalty_days']));
    }
    expect($month[$this->asha->id]['total'])->toMatchArray(['present' => 17.5, 'paid_leave' => 1.0, 'unpaid_leave' => 1.0, 'holiday' => 1.0, 'weekly_off' => 8.0, 'absent' => 2.5,
        'penalty_days' => 0.5, 'arrear_days' => -1.0, 'adjustment' => -3.4, 'paid_days' => 23.6, 'working_hours' => 132.0, 'weekly_off_hours' => 5.0, 'holiday_hours' => 0.0])
        ->and($month[$this->ravi->id]['total'])->toMatchArray(['present' => 21.0, 'weekly_off' => 9.0, 'holiday' => 1.0, 'paid_days' => 31.0, 'adjustment' => 0.0]);

    // Financial year to date: April to October, each month as the summary reads it.
    $windows = $this->register->windows($this->company->id, 2026, 10, true);
    expect(array_column($windows, 'key'))->toBe(['2026-04', '2026-05', '2026-06', '2026-07', '2026-08', '2026-09', '2026-10']);
    $year = $this->register->figures([$this->asha->id], $this->company->id, $windows)[$this->asha->id];
    $whole = app(AttendanceOutput::class)->summary($this->asha, '2026-04-01', '2026-10-31');
    expect($year['total']['paid_days_adjusted'])->toBe($whole['paid_days'])->and($year['total']['penalty_days'])->toBe($whole['penalty_days'])
        ->and($year['months']['2026-09']['paid_days'])->toBe(30.0)->and($year['months']['2026-10']['paid_days'])->toBe(23.6);

    // The page: the month, its columns, the breakdown and the year view.
    $page = Livewire::withQueryParams(['company' => (string) $this->company->id, 'month' => '2026-10'])->test(PaidDays::class)
        ->assertCanSeeTableRecords([$this->asha, $this->ravi])
        ->assertTableColumnStateSet('paid_days', 23.6, $this->asha)->assertTableColumnStateSet('adjustment', -3.4, $this->asha)
        ->assertTableColumnStateSet('arrear_days', -1.0, $this->asha)->assertTableColumnStateSet('paid_days', 31.0, $this->ravi)
        ->callTableAction('breakdown', $this->asha)
        ->assertSee('Breakdown · '.$this->asha->employee_code)->assertSee('Hours by kind of day')->assertSee('Reduce: Paid leave: all, Weekly offs: 30%')
        ->assertSee('3 late marks');
    $page->set('period', 'year')->assertTableColumnStateSet('paid_days', $year['total']['paid_days'], $this->asha)->assertSee('FY 2026–27');

    // Presets and the audited CSV.
    Livewire::withQueryParams(['company' => (string) $this->company->id, 'month' => '2026-10', 'view' => 'adjusted'])->test(PaidDays::class)
        ->assertCanSeeTableRecords([$this->asha])->assertCanNotSeeTableRecords([$this->ravi]);
    Livewire::withQueryParams(['company' => (string) $this->company->id, 'month' => '2026-10'])->test(PaidDays::class)
        ->call('downloadCsv')->assertFileDownloaded('paid-days-2026-10.csv');
    expect(AuditEvent::query()->withoutGlobalScopes()->where('action', AuditAction::Export->value)->where('metadata->export', 'paid_days')->exists())->toBeTrue();
    $csv = $this->register->csv($this->hr, Employee::query()->with('person')->whereKey($this->asha->id)->get(), $this->company->id, 2026, 10);
    expect($csv)->toContain('Paid days as processed')->toContain(',23.6')->toContain(',-3.4,');
});

it('adjusts one employee and the selected ones from the page with a preview, through the bulk framework', function () {
    tx4Setting('attendance.paid_day_adjustments.enabled', true);
    $page = fn () => Livewire::withQueryParams(['company' => (string) $this->company->id, 'month' => '2026-10'])->test(PaidDays::class);

    $page()->assertTableActionVisible('adjust', $this->asha)
        ->callTableAction('adjust', $this->asha, data: ['mode' => 'reduce', 'reductions' => ['weekly_off' => ['how' => 'full']], 'reason' => 'Absconded weekends'])
        ->assertHasNoTableActionErrors()->assertNotified('Paid days adjusted');
    expect($this->adjustments->active($this->asha->id, $this->company->id, 2026, 10)?->describe())->toBe('Reduce: Weekly offs: all');

    // The preview is the service's: before → after per employee, or why it would be refused.
    $component = new PaidDays;
    $component->company = (string) $this->company->id;
    $component->month = '2026-10';
    expect((string) $component->previewHtml(collect([$this->ravi]), ['mode' => 'set', 'categories' => ['present' => 20]]))->toContain('paid days 31 → 30 (-1)')->toContain('Present days 21 → 20')
        ->and((string) $component->previewHtml(collect([$this->asha]), ['mode' => 'set', 'categories' => ['present' => 20]]))->toContain('Replaces the adjustment saved');

    $page()->callTableBulkAction('adjustSelected', [$this->asha, $this->ravi], data: ['mode' => 'set', 'counts' => ['present' => 20], 'reason' => 'Agreed counts'])
        ->assertHasNoTableBulkActionErrors();
    $run = BulkRun::query()->latest('id')->firstOrFail();
    expect($run->operation)->toBe('paid_days.adjust')->and($run->register)->toBe('paid_days')
        ->and(BulkRunItem::query()->where('bulk_run_id', $run->id)->pluck('outcome')->all())->toBe(['done', 'done'])
        ->and(PaidDayAdjustment::query()->where('status', 'active')->where('mode', 'set')->count())->toBe(2)
        ->and(PaidDayAdjustment::query()->where('status', 'reversed')->count())->toBe(1);
});

it('keeps the register, the breakdown and adjustments inside the viewer\'s access scope and rights', function () {
    tx4Setting('attendance.paid_day_adjustments.enabled', true);
    $scoped = tenantUser($this->tenant, ['attendance.view', 'attendance.override', 'bulk.run']);
    app(AccessScopes::class)->assign($scoped, ['location' => [$this->delhi->id]]);
    $this->actingAs($scoped);

    Livewire::withQueryParams(['company' => (string) $this->company->id, 'month' => '2026-10'])->test(PaidDays::class)
        ->assertCanSeeTableRecords([$this->asha])->assertCanNotSeeTableRecords([$this->ravi]);
    Livewire::withQueryParams(['company' => (string) $this->company->id, 'month' => '2026-10', 'detail' => (string) $this->ravi->id])->test(PaidDays::class)
        ->assertDontSee('Breakdown · '.$this->ravi->employee_code);
    expect(fn () => $this->adjustments->save($this->ravi, $this->company->id, 2026, 10, tx4Reduce(), 'x', $scoped))->toThrow(RuntimeException::class, 'not in your access')
        ->and($this->adjustments->save($this->asha, $this->company->id, 2026, 10, tx4Reduce(), 'In scope', $scoped)->isActive())->toBeTrue();

    $viewer = tenantUser($this->tenant, ['attendance.view']);
    $this->actingAs($viewer);
    expect(PaidDays::canAccess())->toBeTrue();
    Livewire::withQueryParams(['company' => (string) $this->company->id, 'month' => '2026-10'])->test(PaidDays::class)
        ->assertTableActionHidden('adjust', $this->asha)->assertTableBulkActionHidden('adjustSelected');
    $this->actingAs(tenantUser($this->tenant, ['employee.view']));
    $this->get(PaidDays::getUrl())->assertForbidden();

    $this->actingAs($this->hr);
    $this->get(PaidDays::getUrl())->assertOk()->assertSee('Paid days');
    expect(collect((new TimeOfficeDesk)->deskSections())->flatMap(fn (array $s) => array_column($s['items'], 'label')))->toContain('Paid days')
        ->and(collect(config('peopleos_setup.modules.time_office.settings'))->pluck('key'))->toContain('attendance.paid_day_adjustments.enabled')
        ->and(collect(config('peopleos_setup.modules.time_office.links'))->pluck('target'))->toContain(PaidDays::class);
});

it('adds hours by kind of day, arrear days and the paid days to Run attendance\'s employee review', function () {
    Livewire::withQueryParams(['company' => (string) $this->company->id, 'month' => '2026-10'])->test(RunAttendance::class)
        ->set('reviewEmployee', (string) $this->asha->id)->call('load')
        ->assertSee('Working days 132 h')->assertSee('weekly offs 5 h')->assertSee('Paid days breakdown')
        ->assertSee('corrections to this month');
});

// ---- Dataset ----------------------------------------------------------------------------------------------------------------

it('reports paid days per employee and attendance month in the attendance_paid_days dataset, inside the reader\'s rights and scope', function () {
    tx4Setting('attendance.paid_day_adjustments.enabled', true);
    $this->adjustments->save($this->asha, $this->company->id, 2026, 10, tx4Reduce(), 'Late marks', $this->hr);
    $registry = app(DatasetRegistry::class);
    $runner = app(ReportRunner::class);
    $dataset = $registry->get('attendance_paid_days');

    expect($dataset->category())->toBe('Attendance')->and($dataset->permissions())->toBe(['attendance.view'])
        ->and(config('peopleos.analytics.unattended_datasets.attendance_paid_days'))->toBeNull();

    $fields = ['employee_code', 'month', 'month_company', 'present_days', 'paid_leave_days', 'unpaid_leave_days', 'holidays', 'weekly_offs', 'absent_days', 'penalty_days', 'arrear_days', 'adjustment_days', 'paid_days_processed', 'paid_days', 'working_hours', 'adjusted'];
    $rows = collect($runner->execute('attendance_paid_days', ['fields' => $fields], $this->hr)->rows)->keyBy('employee_code');
    expect($rows[$this->asha->employee_code])->toMatchArray(['month' => '2026-10', 'month_company' => 'Acme India', 'present_days' => 17.5, 'paid_leave_days' => 1.0, 'weekly_offs' => 8.0,
        'penalty_days' => 0.5, 'arrear_days' => -1.0, 'adjustment_days' => -3.4, 'paid_days_processed' => 27.5, 'paid_days' => 23.6, 'working_hours' => 132.0, 'adjusted' => true])
        ->and($rows[$this->ravi->employee_code])->toMatchArray(['paid_days' => 31.0, 'adjusted' => false]);

    $template = app(ReportLibrary::class)->find('paid_days_last_month');
    expect(collect($runner->execute('attendance_paid_days', $template['definition'], $this->hr)->rows)->pluck('paid_days', 'employee_code')->all())
        ->toMatchArray([$this->asha->employee_code => 23.6, $this->ravi->employee_code => 31.0]);
    expect($runner->execute('attendance_paid_days', app(ReportLibrary::class)->find('paid_days_this_year')['definition'], $this->hr)->rows)->toHaveCount(2);

    // Rights and scope: no attendance.view, no dataset; a location-scoped reader sees only their people; no reader, no run.
    expect(fn () => $runner->execute('attendance_paid_days', ['fields' => ['employee_code']], tenantUser($this->tenant, ['analytics.reports'])))->toThrow(RuntimeException::class, 'cannot report')
        ->and(fn () => $runner->execute('attendance_paid_days', ['fields' => ['employee_code']], null))->toThrow(RuntimeException::class, 'not available without a signed-in user');
    $scoped = tenantUser($this->tenant, ['attendance.view', 'analytics.reports']);
    app(AccessScopes::class)->assign($scoped, ['location' => [$this->delhi->id]]);
    $this->actingAs($scoped);
    expect(collect($runner->execute('attendance_paid_days', ['fields' => ['employee_code', 'paid_days']], $scoped)->rows)->pluck('employee_code')->all())->toBe([$this->asha->employee_code]);
});

// ---- Boundaries ---------------------------------------------------------------------------------------------------------

it('keeps TX-4 attendance code free of payroll words and classifies the new table', function () {
    $files = ['Domain/Attendance/Services/PaidDayAdjustments.php', 'Domain/Attendance/Services/PaidDaysRegister.php', 'Domain/Attendance/Support/PaidDayTally.php',
        'Domain/Attendance/Models/PaidDayAdjustment.php', 'Domain/Attendance/Contracts/PaidDayAdjustmentReader.php'];
    foreach ($files as $file) {
        expect((bool) preg_match('/\b(payroll|pay|salary|wage|wages|deduction|deductions|earning|earnings|ctc|payslip)\b/i', file_get_contents(app_path($file))))->toBeFalse("{$file} must not use payroll words");
    }
    expect(DataInventory::categoryOf('attendance_paid_day_adjustments'))->toBe('attendance');
});
