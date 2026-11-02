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

/* Pay cycle: an admin picks the date a cycle starts (25th to 24th, or back to calendar months); no day is paid twice or left out. */

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

it('keeps calendar months until a cycle starts, then runs each month from the 25th of the month before to the 24th', function () {
    expect(periodDates(PayrollPeriod::for($this->company, 2026, 9)))->toBe(['2026-09-01', '2026-09-30']);

    expect($this->cycle->change(Carbon::parse('2026-10-25'), 'Client runs attendance from the 25th'))->toBeTrue();
    $november = PayrollPeriod::for($this->company, 2026, 11);
    expect(periodDates($november))->toBe(['2026-10-25', '2026-11-24'])
        ->and($november->daysInPeriod())->toBe(31)->and($november->label())->toBe('Nov 2026')->and($november->key())->toBe('2026-11')
        ->and($november->rangeLabel())->toBe('25 Oct – 24 Nov 2026')
        ->and(periodDates(PayrollPeriod::for($this->company, 2026, 10)))->toBe(['2026-10-01', '2026-10-24']) // the changeover month
        ->and(periodDates(PayrollPeriod::for($this->company, 2026, 9)))->toBe(['2026-09-01', '2026-09-30'])  // before it: unchanged
        ->and(periodDates(PayrollPeriod::for($this->company, 2027, 1)))->toBe(['2026-12-25', '2027-01-24'])
        ->and(periodDates(PayrollPeriod::for($this->company, 2027, 3)))->toBe(['2027-02-25', '2027-03-24'])
        ->and(PayrollPeriod::containing($this->company, Carbon::parse('2026-10-27'))->is($november))->toBeTrue()
        ->and(PayrollPeriod::containing($this->company, Carbon::parse('2026-10-24'))->month)->toBe(10)
        ->and($this->cycle->startDay())->toBe(25)
        ->and($this->cycle->change(Carbon::parse('2026-12-25')))->toBeFalse() // already the 25th from then
        ->and(fn () => $this->cycle->change(Carbon::parse('2026-11-29')))->toThrow(RuntimeException::class, '1st to the 28th');
});

it('takes loss of pay from the attendance of the cycle, not of the calendar month', function () {
    $this->cycle->change(Carbon::parse('2026-10-25'));
    $employee = salariedEmployee(600000);
    foreach (['2026-10-24', '2026-10-27', '2026-11-24', '2026-11-26'] as $date) {
        AttendanceRecord::create(['employee_id' => $employee->id, 'date' => $date, 'status' => 'absent', 'processed_at' => now()]);
    }

    $c = app(PayrollCalculator::class)->calculate($employee, PayrollPeriod::for($this->company, 2026, 11));
    expect($c->inputs['lop_by_date'])->toBe(['2026-10-27' => 1.0, '2026-11-24' => 1.0])
        ->and($c->inputs['eligible_from'])->toBe('2026-10-25')->and($c->inputs['eligible_to'])->toBe('2026-11-24')
        ->and($c->daysInPeriod)->toBe(31)->and($c->inputs['divisor'])->toBe(31.0)->and($c->lopDays)->toBe(2.0);
});

it('starts a cycle only after the months with a payroll run, and goes back to calendar months the same way', function () {
    $runs = app(PayrollRuns::class);
    $runs->open($this->company, 2026, 10); // October's run fixes 1 – 31 October

    expect(fn () => $this->cycle->change(Carbon::parse('2026-10-25')))->toThrow(RuntimeException::class, 'Choose a date after 31 Oct 2026');
    $this->cycle->change(Carbon::parse('2026-11-25'));
    expect(periodDates(PayrollPeriod::for($this->company, 2026, 10)))->toBe(['2026-10-01', '2026-10-31'])
        ->and(periodDates(PayrollPeriod::for($this->company, 2026, 11)))->toBe(['2026-11-01', '2026-11-24'])
        ->and(periodDates(PayrollPeriod::for($this->company, 2026, 12)))->toBe(['2026-11-25', '2026-12-24'])
        ->and(PayrollPeriod::containing($this->company, Carbon::parse('2026-10-28'))->month)->toBe(10)
        ->and($this->cycle->scheduled())->toHaveCount(1);

    $preview = collect($this->cycle->preview($this->company->id, 2026, 10))->keyBy('label');
    expect($preview->keys()->all())->toBe(['Oct 2026', 'Nov 2026', 'Dec 2026', 'Jan 2027', 'Feb 2027'])
        ->and($preview['Oct 2026']['state'])->toBe('fixed')
        ->and($preview['Nov 2026'])->toMatchArray(['state' => 'changeover', 'days' => 24, 'note' => 'Shorter month: the changeover to the next cycle'])
        ->and($preview['Dec 2026'])->toMatchArray(['state' => 'first', 'days' => 30, 'note' => 'First month on the new cycle: 25th to 24th'])
        ->and($preview['Jan 2027']['state'])->toBeNull();

    // Back to calendar months after December's run: January takes the days from 25 December.
    $runs->open($this->company, 2026, 12);
    $this->cycle->change(Carbon::parse('2027-01-01'));
    expect(periodDates(PayrollPeriod::for($this->company, 2027, 1)))->toBe(['2026-12-25', '2027-01-31'])
        ->and(periodDates(PayrollPeriod::for($this->company, 2027, 2)))->toBe(['2027-02-01', '2027-02-28'])
        ->and(collect($this->cycle->changes())->map(fn ($c) => $c['from']->toDateString())->all())->toBe(['2026-11-25', '2027-01-01'])
        // A date whose first month is the same as a change's replaces it: 1 Dec drops the 25th cycle altogether.
        ->and($this->cycle->withChange(Carbon::parse('2026-12-01')))->toBe([]);
});

it('finds a cycle month by the month it is named for in statutory and cost ranges', function () {
    $this->cycle->change(Carbon::parse('2026-10-25'));
    $october = PayrollPeriod::for($this->company, 2026, 10);
    $november = PayrollPeriod::for($this->company, 2026, 11);

    expect(PayrollPeriod::query()->monthsBetween('2026-11-01', '2026-11-30')->pluck('id')->all())->toBe([$november->id])
        ->and(PayrollPeriod::query()->monthsBetween('2026-10-01', '2026-12-31')->orderBy('id')->pluck('id')->all())->toBe([$october->id, $november->id])
        ->and(PayrollPeriod::query()->monthsBetween('2026-10-02', '2026-11-30')->pluck('id')->all())->toBe([$november->id]); // a month counts when its 1st is in the range
});

it('lets a payroll admin pick the date a cycle starts, see the months it gives, and cancel a scheduled change', function () {
    Livewire::test(PayCyclePage::class)
        ->assertSee('Calendar month')->assertSee('1 Oct – 31 Oct 2026')
        ->set('data.starts_on', '2026-10-25')
        ->assertSee('Payroll months will run from the 25th to 24th, starting with November 2026')
        ->assertSee('25 Oct – 24 Nov 2026')->assertSee('Shorter month: the changeover to the next cycle')
        ->set('data.audit_reason', 'Client closes attendance on the 24th')
        ->call('save')->assertNotified('Pay cycle saved');

    expect(app(SettingsRepository::class)->get(PayCycle::SETTING))->toBe([['from' => '2026-10-25']])
        ->and(AuditEvent::query()->where('reason', 'Client closes attendance on the 24th')->exists())->toBeTrue();
    $this->get(PayrollControlRoom::getUrl())->assertOk()->assertSee('Payroll for Nov 2026 · 25 Oct – 24 Nov 2026');

    // A change back to calendar months from February is scheduled, then cancelled.
    Livewire::test(PayCyclePage::class)
        ->assertSee('25th to 24th')->assertSee('Since 25 Oct 2026')
        ->set('data.starts_on', '2027-02-01')->call('save')
        ->assertActionVisible('cancelScheduled')->callAction('cancelScheduled')->assertNotified('Scheduled pay cycle change cancelled');
    expect(app(SettingsRepository::class)->get(PayCycle::SETTING))->toBe([['from' => '2026-10-25']]);

    $this->actingAs(tenantUser($this->tenant, ['payroll.view']))->get(PayCyclePage::getUrl())->assertForbidden();
});

/** @return list<string> the dates the saved changes start on */
function cycleStarts(): array
{
    return collect(app(PayCycle::class)->changes())->map(fn ($c) => $c['from']->toDateString())->all();
}

it('runs a cycle for one payroll month or to the end of the financial year, then goes back to the cycle the months had', function () {
    // One month: December alone on the 25th; November gives up its last days, December keeps them to its end.
    expect($this->cycle->change(Carbon::parse('2026-11-25'), null, PayCycle::monthIndex(2026, 12)))->toBeTrue()
        ->and(cycleStarts())->toBe(['2026-11-25', '2027-01-01'])
        ->and(periodDates(PayrollPeriod::for($this->company, 2026, 11)))->toBe(['2026-11-01', '2026-11-24'])
        ->and(periodDates(PayrollPeriod::for($this->company, 2026, 12)))->toBe(['2026-11-25', '2026-12-31'])
        ->and(periodDates(PayrollPeriod::for($this->company, 2027, 1)))->toBe(['2027-01-01', '2027-01-31']);
    $preview = collect($this->cycle->preview($this->company->id, 2026, 11))->keyBy('label');
    expect($preview['Dec 2026'])->toMatchArray(['state' => 'first', 'days' => 37, 'note' => 'Only month on the new cycle (25th to 24th): it ends the day before the next cycle starts'])
        ->and($preview['Jan 2027']['state'])->toBe('first');

    // To the end of the financial year: December to March on the 25th, April back on calendar months.
    expect($this->cycle->change(Carbon::parse('2026-11-25'), null, PayCycle::monthIndex(2027, 3)))->toBeTrue()
        ->and(cycleStarts())->toBe(['2026-11-25', '2027-04-01'])
        ->and(periodDates(PayrollPeriod::for($this->company, 2026, 12)))->toBe(['2026-11-25', '2026-12-24'])
        ->and(periodDates(PayrollPeriod::for($this->company, 2027, 2)))->toBe(['2027-01-25', '2027-02-24'])
        ->and(periodDates(PayrollPeriod::for($this->company, 2027, 3)))->toBe(['2027-02-25', '2027-03-31'])
        ->and(periodDates(PayrollPeriod::for($this->company, 2027, 4)))->toBe(['2027-04-01', '2027-04-30'])
        ->and(AuditEvent::query()->where('reason', 'Pay cycle from 25 Nov 2026: 25th to 24th, to Mar 2027')->exists())->toBeTrue()
        // The financial year a month is named in ends in March.
        ->and(PayCycle::financialYearEnd(PayCycle::monthIndex(2026, 12)))->toBe(PayCycle::monthIndex(2027, 3))
        ->and(PayCycle::financialYearEnd(PayCycle::monthIndex(2027, 4)))->toBe(PayCycle::monthIndex(2028, 3))
        ->and(PayCycle::financialYearEnd(PayCycle::monthIndex(2027, 3)))->toBe(PayCycle::monthIndex(2027, 3))
        ->and(fn () => $this->cycle->change(Carbon::parse('2026-11-25'), null, PayCycle::monthIndex(2026, 11)))->toThrow(RuntimeException::class, 'cannot be before its first month');
});

it('keeps the changes after a cycle with an end, and goes back to the cycle in force after it', function () {
    $this->cycle->change(Carbon::parse('2026-10-25'));
    $this->cycle->change(Carbon::parse('2027-06-01')); // calendar months again from June

    // December alone on calendar months, between months on the 25th.
    expect($this->cycle->change(Carbon::parse('2026-12-01'), null, PayCycle::monthIndex(2026, 12)))->toBeTrue()
        ->and(cycleStarts())->toBe(['2026-10-25', '2026-12-01', '2026-12-25', '2027-06-01'])
        ->and(periodDates(PayrollPeriod::for($this->company, 2026, 11)))->toBe(['2026-10-25', '2026-11-30'])
        ->and(periodDates(PayrollPeriod::for($this->company, 2026, 12)))->toBe(['2026-12-01', '2026-12-24'])
        ->and(periodDates(PayrollPeriod::for($this->company, 2027, 1)))->toBe(['2026-12-25', '2027-01-24'])
        // Until changed replaces every later change.
        ->and(collect($this->cycle->withChange(Carbon::parse('2026-12-01')))->map(fn ($c) => $c['from']->toDateString())->all())->toBe(['2026-10-25', '2026-12-01'])
        // A year on calendar months from April: the year after is calendar months too, so no change takes the months back.
        ->and(collect($this->cycle->withChange(Carbon::parse('2027-04-01'), PayCycle::monthIndex(2028, 3)))->map(fn ($c) => $c['from']->toDateString())->all())
        ->toBe(['2026-10-25', '2026-12-01', '2026-12-25', '2027-04-01']);
});

it('lets the admin pick the months a cycle runs from and to, from any month back on a first set up', function () {
    Livewire::test(PayCyclePage::class)
        ->assertSee('Until changed')->assertSee('No payroll run yet, so it can start on any day from 1 Oct 2024.')
        ->set('data.starts_on', '2026-11-25')->assertSee('First month on it: December 2026, 25 Nov – 24 Dec 2026.')
        ->set('data.until', '2026-12')
        ->assertSee('Dec 2026 (this month only)')
        ->assertSee('Payroll months will run from the 25th to 24th for December 2026 only, then go back to calendar months')
        ->assertSee('Only month on the new cycle (25th to 24th)')
        ->set('data.until', '2027-03')
        ->assertSee('Mar 2027 (end of financial year 2026-27)')
        ->assertSee('Payroll months will run from the 25th to 24th from December 2026 to March 2027, then go back to calendar months')
        // A first set up: from April 2026, months already past included, to the end of the next financial year.
        ->set('data.starts_on', '2026-03-25')->set('data.until', '2028-03')
        ->assertSee('Payroll months will run from the 25th to 24th from April 2026 to March 2028')->assertSee('Jun 2026 – Feb 2028')
        ->set('data.audit_reason', 'Client runs payroll from the 25th for two financial years')
        ->call('save')->assertNotified('Pay cycle saved');

    expect(app(SettingsRepository::class)->get(PayCycle::SETTING))->toBe([['from' => '2026-03-25'], ['from' => '2028-04-01']])
        ->and(periodDates(PayrollPeriod::for($this->company, 2026, 4)))->toBe(['2026-03-25', '2026-04-24'])
        ->and(periodDates(PayrollPeriod::for($this->company, 2026, 3)))->toBe(['2026-03-01', '2026-03-24']);

    // Cancelling the scheduled end leaves the cycle running until changed.
    Livewire::test(PayCyclePage::class)
        ->assertActionVisible('cancelScheduled')->callAction('cancelScheduled')->assertNotified('Scheduled pay cycle change cancelled');
    expect(app(SettingsRepository::class)->get(PayCycle::SETTING))->toBe([['from' => '2026-03-25']]);

    // Once a month has a payroll run, the first month can only be after it.
    app(PayrollRuns::class)->open($this->company, 2026, 10);
    Livewire::test(PayCyclePage::class)->assertSee('Months up to 24 Oct 2026 have a payroll run and keep their dates.')
        ->set('data.starts_on', '2026-10-01')->assertSee('Pick the date the cycle starts and the month it runs to.');
});
