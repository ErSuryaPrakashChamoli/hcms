<?php

use App\Domain\Attendance\Models\AttendanceRecord;
use App\Domain\Audit\Models\AuditEvent;
use App\Domain\Payroll\Models\PayrollPeriod;
use App\Domain\Payroll\Services\PayCycle;
use App\Domain\Payroll\Services\PayrollCalculator;
use App\Domain\Payroll\Services\PayrollRuns;
use App\Domain\Platform\Services\SettingsRepository;
use App\Filament\Pages\PayCyclePage;
use App\Filament\Pages\PayrollControlRoom;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

require_once __DIR__.'/../Workflow/WorkflowTestHelpers.php';
require_once __DIR__.'/PayrollTestHelpers.php';

/* Pay cycle: payroll months from the 25th to the 24th (any start day to the 28th), changed by an admin without paying a day twice or leaving one out. */

beforeEach(function () {
    $this->travelTo('2026-10-28 09:00:00');
    syncComplianceRules();
    $this->tenant = provisionTenant();
    actAsTenant($this->tenant);
    $this->admin = tenantUser($this->tenant, ['payroll.*', 'employee.*']);
    $this->actingAs($this->admin);
    $this->company = payrollCompany();
    $this->cycle = app(PayCycle::class);
});

/** @return array{0: string, 1: string} */
function periodDates(PayrollPeriod $period): array
{
    return [$period->start_date->toDateString(), $period->end_date->toDateString()];
}

it('keeps calendar months by default and runs a month from the 25th of the month before to the 24th once the cycle says so', function () {
    expect(periodDates(PayrollPeriod::for($this->company, 2026, 9)))->toBe(['2026-09-01', '2026-09-30']);

    $this->cycle->change(25);
    $november = PayrollPeriod::for($this->company, 2026, 11);
    expect(periodDates($november))->toBe(['2026-10-25', '2026-11-24'])
        ->and($november->daysInPeriod())->toBe(31)->and($november->label())->toBe('Nov 2026')->and($november->key())->toBe('2026-11')
        ->and($november->rangeLabel())->toBe('25 Oct – 24 Nov 2026')
        ->and(periodDates(PayrollPeriod::for($this->company, 2026, 9)))->toBe(['2026-08-25', '2026-09-24']) // no run yet: follows the new cycle
        ->and(periodDates(PayrollPeriod::for($this->company, 2027, 1)))->toBe(['2026-12-25', '2027-01-24'])
        ->and(periodDates(PayrollPeriod::for($this->company, 2027, 3)))->toBe(['2027-02-25', '2027-03-24'])
        ->and(PayrollPeriod::containing($this->company, Carbon::parse('2026-10-27'))->is($november))->toBeTrue()
        ->and(PayrollPeriod::containing($this->company, Carbon::parse('2026-10-24'))->month)->toBe(10)
        ->and(fn () => $this->cycle->change(29))->toThrow(RuntimeException::class, '1st to the 28th');
});

it('takes loss of pay from the attendance of the cycle, not of the calendar month', function () {
    $this->cycle->change(25);
    $employee = salariedEmployee(600000);
    foreach (['2026-10-24', '2026-10-27', '2026-11-24', '2026-11-26'] as $date) {
        AttendanceRecord::create(['employee_id' => $employee->id, 'date' => $date, 'status' => 'absent', 'processed_at' => now()]);
    }

    $c = app(PayrollCalculator::class)->calculate($employee, PayrollPeriod::for($this->company, 2026, 11));
    expect($c->inputs['lop_by_date'])->toBe(['2026-10-27' => 1.0, '2026-11-24' => 1.0])
        ->and($c->inputs['eligible_from'])->toBe('2026-10-25')->and($c->inputs['eligible_to'])->toBe('2026-11-24')
        ->and($c->daysInPeriod)->toBe(31)->and($c->inputs['divisor'])->toBe(31.0)->and($c->lopDays)->toBe(2.0);
});

it('starts the first month on a new cycle the day after the last fixed month, both ways', function () {
    $runs = app(PayrollRuns::class);
    $runs->open($this->company, 2026, 10); // October's run fixes 1 – 31 October

    $this->cycle->change(25);
    expect(periodDates(PayrollPeriod::for($this->company, 2026, 10)))->toBe(['2026-10-01', '2026-10-31'])
        ->and(periodDates(PayrollPeriod::for($this->company, 2026, 11)))->toBe(['2026-11-01', '2026-11-24'])
        ->and(periodDates(PayrollPeriod::for($this->company, 2026, 12)))->toBe(['2026-11-25', '2026-12-24'])
        ->and(PayrollPeriod::containing($this->company, Carbon::parse('2026-10-28'))->month)->toBe(10);

    $preview = collect($this->cycle->preview($this->company->id))->keyBy('label');
    expect($preview->keys()->all())->toBe(['Oct 2026', 'Nov 2026', 'Dec 2026', 'Jan 2027'])
        ->and($preview['Oct 2026']['note'])->toBe('Payroll run open: dates stay as they are')
        ->and($preview['Nov 2026']['note'])->toBe('Changeover month: shorter than usual')->and($preview['Nov 2026']['days'])->toBe(24)
        ->and($preview['Dec 2026']['note'])->toBeNull()->and($preview['Dec 2026']['days'])->toBe(30);

    // Back to calendar months after December's run: January takes the days from 25 December.
    $runs->open($this->company, 2026, 12);
    $this->cycle->change(1);
    expect(periodDates(PayrollPeriod::for($this->company, 2027, 1)))->toBe(['2026-12-25', '2027-01-31'])
        ->and(periodDates(PayrollPeriod::for($this->company, 2026, 12)))->toBe(['2026-11-25', '2026-12-24'])
        ->and(collect($this->cycle->preview($this->company->id, months: 4))->firstWhere('label', 'Jan 2027')['note'])->toBe('Changeover month: longer than usual');
});

it('finds a cycle month by the month it is named for in statutory and cost ranges', function () {
    $this->cycle->change(25);
    $october = PayrollPeriod::for($this->company, 2026, 10);
    $november = PayrollPeriod::for($this->company, 2026, 11);

    expect(PayrollPeriod::query()->monthsBetween('2026-11-01', '2026-11-30')->pluck('id')->all())->toBe([$november->id])
        ->and(PayrollPeriod::query()->monthsBetween('2026-10-01', '2026-12-31')->orderBy('id')->pluck('id')->all())->toBe([$october->id, $november->id])
        ->and(PayrollPeriod::query()->monthsBetween('2026-10-02', '2026-11-30')->pluck('id')->all())->toBe([$november->id]); // a month counts when its 1st is in the range
});

it('lets a payroll admin change the cycle on the Pay cycle page with a preview and an audited reason', function () {
    Livewire::test(PayCyclePage::class)
        ->assertSet('data.start_day', 1)
        ->assertSee('1 Oct 2026 – 31 Oct 2026')
        ->set('data.start_day', 25)
        ->assertSee('25 Oct 2026 – 24 Nov 2026')
        ->set('data.audit_reason', 'Client closes attendance on the 24th')
        ->call('save')->assertNotified('Pay cycle saved: 25th to 24th');

    expect(app(SettingsRepository::class)->get(PayCycle::SETTING))->toBe(25)
        ->and(AuditEvent::query()->where('reason', 'Client closes attendance on the 24th')->exists())->toBeTrue();
    $this->get(PayrollControlRoom::getUrl())->assertOk()->assertSee('Payroll for Nov 2026 · 25 Oct – 24 Nov 2026');

    $this->actingAs(tenantUser($this->tenant, ['payroll.view']))->get(PayCyclePage::getUrl())->assertForbidden();
});
