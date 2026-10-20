<?php

use App\Domain\Billing\Enums\InvoiceStatus;
use App\Domain\Billing\Models\BillingPeriod;
use App\Domain\Billing\Models\InvoiceLine;
use App\Domain\Billing\Services\BillingPeriods;
use App\Domain\Billing\Services\BillingTerms;
use App\Domain\Billing\Services\Invoices;
use App\Domain\Billing\Services\PriceNotices;
use App\Domain\Lifecycle\Enums\LifecycleState;
use App\Domain\Subscriptions\Services\CommercialSubscriptions;
use App\Support\Money\Money;
use App\Support\Tenancy\TenantContext;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Artisan;

require_once __DIR__.'/BillingTestHelpers.php';
require_once __DIR__.'/../Entitlements/PlanTestHelpers.php';

/*
| SaaS.7 completion (B-1, B-2, B-3, B-15): the billing run. Monthly terms are billed after each calendar month on its
| peak employed count (max with the price's minimum), prorated by billable days only for a partial first or last
| month; annual terms in advance on the committed quantity, with a monthly true-up above it. Trials are not billed,
| grace is. The quantity is rebuilt from the lifecycle history and frozen with its evidence; a later HR change never
| recalculates a period. Existing subscribers keep their pinned price.
*/

beforeEach(function () {
    $this->travelTo('2027-04-01 09:00:00');
    $this->setup = indiaBilling();
    [$this->op, $this->checker] = [$this->setup['operator'], $this->setup['verifier']];
    $this->growth = publishedPlan($this->op, 'growth', ['leave' => true], '2027-04-01');
    $this->monthly = pepmPrice($this->growth, $this->setup['market'], 'month', '100.00', '2027-04-01', $this->op, $this->checker);
    $this->tenant = provisionTenant('Alpha');
    billingProfile($this->tenant, $this->setup['market'], $this->op);
    $this->subs = app(CommercialSubscriptions::class);
    $this->terms = app(BillingTerms::class);
    $this->run = app(BillingPeriods::class);
});

it('bills the monthly peak employed count from lifecycle history: joiners, leavers, leave and suspension counted, preboarding and alumni not (critical 1, 3, 4)', function () {
    $sub = $this->subs->start($this->tenant, $this->growth, '2027-04-01', null, 'Contract', $this->op);
    $this->terms->set($sub, $this->monthly, '2027-04-01', 'Order form', $this->op);

    // Recorded later, back-dated: the history decides, not today's state.
    $this->travelTo('2027-05-02 09:00:00');
    $a = staff($this->tenant, '2027-03-01');
    $b = staff($this->tenant, '2027-04-10');                    // joins mid-month
    $c = staff($this->tenant, '2027-04-20');
    $d = staff($this->tenant, '2027-02-01');
    staffMove($this->tenant, $d, LifecycleState::Active, '2027-03-01');
    staffMove($this->tenant, $d, LifecycleState::OnLeave, '2027-04-05');   // on leave: still employed
    $e = staff($this->tenant, '2027-01-15');
    staffMove($this->tenant, $e, LifecycleState::Suspended, '2027-04-01'); // suspended: still employed
    $f = staff($this->tenant, null);
    staffMove($this->tenant, $f, LifecycleState::Preboarding, '2027-03-20'); // offer stage all month
    $g = staff($this->tenant, '2026-01-01');
    staffExit($this->tenant, $g, '2027-01-31');                // alumni all month
    staffExit($this->tenant, $a, '2027-04-15');                // last day 15 April

    expect($this->run->run($this->tenant))->toBe(['created' => 1, 'drafted' => 1, 'nothing_due' => 0, 'exceptions' => 0]);
    $april = billingPeriodsOf($this->tenant)->get('monthly_arrears 2027-04-01');
    expect([$april->period_end->toDateString(), $april->days_billed, $april->days_in_period, $april->measured_peak, $april->billed_quantity, $april->amount()->toDecimal()])
        ->toBe(['2027-04-30', 30, 30, 4, 4, '400.00'])
        ->and($april->evidence['peak_day'])->toBe('2027-04-10')
        ->and($april->evidence['employee_ids'])->toBe([$a->id, $b->id, $d->id, $e->id])
        ->and($april->evidence['employee_ids_sha256'])->toBe(hash('sha256', implode(',', [$a->id, $b->id, $d->id, $e->id])))
        ->and([$april->evidence['daily_counts']['2027-04-09'], $april->evidence['daily_counts']['2027-04-15'], $april->evidence['daily_counts']['2027-04-16'], $april->evidence['daily_counts']['2027-04-20']])
        ->toBe([3, 4, 3, 4])                                    // the leaver counts on the exit day, not after
        ->and($april->evidence['method'])->toBe('lifecycle_transitions_v1')
        ->and($april->evidence['fallback_employee_ids'])->toBe([]);

    // The draft carries the frozen evidence; issue stays an operator step.
    $invoice = $april->invoice;
    $line = app(TenantContext::class)->runAs($this->tenant, fn () => InvoiceLine::query()->where('invoice_id', $invoice->id)->sole());
    expect([$invoice->status, $invoice->subscription_id, $invoice->period_start->toDateString(), $invoice->subtotal_minor, $line->quantity, $line->unit_amount_minor, $line->amount_minor, $line->billing_period_id])
        ->toBe([InvoiceStatus::Draft, $sub->id, '2027-04-01', 40000, 4, 10000, 40000, $april->id])
        ->and($line->quantity_evidence['peak_day'])->toBe('2027-04-10')->and($line->quantity_evidence['employee_count'])->toBe(4)
        ->and($line->description)->toContain('peak 4 employees on 2027-04-10');

    // A later, back-dated HR change never recalculates a billed period; running again changes nothing.
    staff($this->tenant, '2027-04-01');
    expect($this->run->run($this->tenant)['created'])->toBe(0)
        ->and($april->fresh()->measured_peak)->toBe(4)->and($april->fresh()->evidence)->toBe($april->evidence)
        ->and(fn () => $april->fresh()->forceFill(['billed_quantity' => 9])->save())->toThrow(RuntimeException::class, 'frozen')
        ->and(fn () => $april->fresh()->delete())->toThrow(RuntimeException::class, 'never deleted');

    // May: the leaver and the alumni are out; the preboarding offer still is; May is not billed before it ends.
    $this->travelTo('2027-06-01 06:00:00');
    expect($this->run->run($this->tenant)['created'])->toBe(1);
    expect(billingPeriodsOf($this->tenant)->get('monthly_arrears 2027-05-01')->evidence['employee_ids'])->not->toContain($a->id)->not->toContain($f->id)->not->toContain($g->id);
});

it('counts a rehired employee once, never twice on the same day (critical 2)', function () {
    $sub = $this->subs->start($this->tenant, $this->growth, '2027-04-01', null, 'Contract', $this->op);
    $this->terms->set($sub, $this->monthly, '2027-04-01', 'Order form', $this->op);
    $this->travelTo('2027-05-02 09:00:00');
    $r = staff($this->tenant, '2027-01-01');
    staffExit($this->tenant, $r, '2027-04-05');
    staffMove($this->tenant, $r, LifecycleState::Active, '2027-04-12');    // rehire: the same employee record
    $s = staff($this->tenant, '2027-01-01');
    staffExit($this->tenant, $s, '2027-04-20');
    staffMove($this->tenant, $s, LifecycleState::Active, '2027-04-20');    // rehired the day they left

    $this->run->run($this->tenant);
    $april = billingPeriodsOf($this->tenant)->get('monthly_arrears 2027-04-01');
    $counts = $april->evidence['daily_counts'];
    expect($april->measured_peak)->toBe(2)->and(max($counts))->toBe(2)
        ->and([$counts['2027-04-05'], $counts['2027-04-06'], $counts['2027-04-11'], $counts['2027-04-12'], $counts['2027-04-20'], $counts['2027-04-21']])->toBe([2, 1, 1, 2, 2, 2]);
});

it('prorates only a partial first or last month by billable days; trials are not billed, grace is (critical 7, 8)', function () {
    $odd = pepmPrice($this->growth, $this->setup['market'], 'month', '99.99', '2027-04-02', $this->op, $this->checker);
    $this->travelTo('2027-04-02 09:00:00');
    $sub = $this->subs->startTrial($this->tenant, $this->growth, '2027-04-02', '2027-04-30', 'Trial', $this->op);
    $this->terms->set($sub, $odd, '2027-04-02', 'Price agreed during the trial', $this->op);
    $this->subs->convert($sub, '2027-04-15', null, 'Converted', $this->op);
    $this->subs->enterGrace($sub, '2027-06-01', '2027-06-10', 'Renewal pending', $this->op);
    foreach (range(1, 3) as $i) {
        staff($this->tenant, '2027-03-01');
    }

    $this->travelTo('2027-07-01 06:00:00');
    expect($this->run->run($this->tenant))->toBe(['created' => 3, 'drafted' => 3, 'nothing_due' => 0, 'exceptions' => 0]);
    $p = billingPeriodsOf($this->tenant);
    // April: trial 2–14 not billed; 15–30 billed: 3 × 99.99 × 16/30 = 159.984 → 159.98 (rounded once, half up).
    expect([$p['monthly_arrears 2027-04-01']->days_billed, $p['monthly_arrears 2027-04-01']->amount()->toDecimal(), $p['monthly_arrears 2027-04-01']->evidence['first_billable_day']])
        ->toBe([16, '159.98', '2027-04-15'])
        // May: a full month, no proration.
        ->and([$p['monthly_arrears 2027-05-01']->days_billed, $p['monthly_arrears 2027-05-01']->amount()->toDecimal()])->toBe([31, '299.97'])
        // June: grace 1–10 billed, then expired: 3 × 99.99 × 10/30 = 99.99.
        ->and([$p['monthly_arrears 2027-06-01']->days_billed, $p['monthly_arrears 2027-06-01']->amount()->toDecimal(), $p['monthly_arrears 2027-06-01']->evidence['last_billable_day']])
        ->toBe([10, '99.99', '2027-06-10']);
    $line = app(TenantContext::class)->runAs($this->tenant, fn () => InvoiceLine::query()->where('billing_period_id', $p['monthly_arrears 2027-04-01']->id)->sole());
    expect([$line->quantity, $line->unit_amount_minor, $line->days_billed, $line->days_in_period, $line->amount_minor, $line->period_start->toDateString()])
        ->toBe([3, 9999, 16, 30, 15998, '2027-04-15'])
        ->and(Invoices::lineAmount(Money::parse('99.99', 'INR'), 3, 16, 30)->minor)->toBe(15998)
        ->and(Invoices::lineAmount(Money::parse('99.99', 'INR'), 1, 1, 2)->minor)->toBe(5000)          // 4999.5 → 5000: half up, once
        ->and(Invoices::lineAmount(Money::parse('1250', 'JPY'), 1, 1, 3)->minor)->toBe(417);           // a zero-decimal currency
});

it('applies the minimum quantity and never prorates people: one joiner on the last day counts for the month (B-1, B-2)', function () {
    $floor = pepmPrice($this->growth, $this->setup['market'], 'month', '120.00', '2027-04-02', $this->op, $this->checker, minimum: 5);
    $this->travelTo('2027-04-02 09:00:00');
    $sub = $this->subs->start($this->tenant, $this->growth, '2027-04-02', null, 'Contract', $this->op);
    $this->terms->set($sub, $floor, '2027-05-01', 'Price from May', $this->op);
    staff($this->tenant, '2027-03-01');
    staff($this->tenant, '2027-05-31');
    $this->travelTo('2027-06-01 06:00:00');
    $this->run->run($this->tenant);
    $may = billingPeriodsOf($this->tenant)->get('monthly_arrears 2027-05-01');
    expect([$may->measured_peak, $may->minimum_quantity, $may->billed_quantity, $may->amount()->toDecimal()])->toBe([2, 5, 5, '600.00'])
        ->and(billingPeriodsOf($this->tenant)->has('monthly_arrears 2027-04-01'))->toBeFalse()   // no terms in April: nothing billed
        ->and($may->invoice->subtotal()->toDecimal())->toBe('600.00');
});

it('bills annual terms in advance on the committed quantity, with a monthly true-up above it, changed only at renewal (critical 5, 6)', function () {
    $annual = pepmPrice($this->growth, $this->setup['market'], 'year', '100.00', '2027-04-15', $this->op, $this->checker, minimum: 5);
    $this->travelTo('2027-04-15 09:00:00');
    $sub = $this->subs->start($this->tenant, $this->growth, '2027-04-15', null, 'Annual contract', $this->op);
    // The partial month before the term is billed on monthly terms; the annual term starts on the 1st.
    $this->terms->set($sub, $this->monthly, '2027-04-15', 'Stub month', $this->op);
    expect(fn () => $this->terms->set($sub, $annual, '2027-05-15', 'Mid-month', $this->op, null, 10))->toThrow(RuntimeException::class, 'start on the 1st')
        ->and(fn () => $this->terms->set($sub, $annual, '2027-05-01', 'Below the minimum', $this->op, null, 4))->toThrow(RuntimeException::class, 'at least 5')
        ->and(fn () => $this->terms->set($sub, $annual, '2027-05-01', 'No commitment', $this->op))->toThrow(RuntimeException::class, 'committed quantity')
        ->and(fn () => $this->terms->set($sub, $this->monthly, '2027-05-01', 'Monthly with a commitment', $this->op, null, 3))->toThrow(RuntimeException::class, 'annual per-employee terms only');
    $term = $this->terms->set($sub, $annual, '2027-05-01', 'Annual from May', $this->op, 'ORDER-9', 10);
    expect($term->committed_quantity)->toBe(10);
    foreach (range(1, 6) as $i) {
        staff($this->tenant, '2027-03-01');
    }

    // 1 May: the annual term is billed in advance; April's stub month is billed in arrears.
    $this->travelTo('2027-05-01 06:00:00');
    expect($this->run->run($this->tenant))->toBe(['created' => 2, 'drafted' => 2, 'nothing_due' => 0, 'exceptions' => 0]);
    $p = billingPeriodsOf($this->tenant);
    $advance = $p['annual_advance 2027-05-01'];
    expect([$advance->period_end->toDateString(), $advance->billed_quantity, $advance->committed_quantity, $advance->measured_peak, $advance->amount()->toDecimal()])
        ->toBe(['2028-04-30', 10, 10, null, '12000.00'])
        ->and($advance->invoice->subtotal()->toDecimal())->toBe('12000.00')
        ->and([$p['monthly_arrears 2027-04-01']->days_billed, $p['monthly_arrears 2027-04-01']->amount()->toDecimal()])->toBe([16, '320.00']);   // 6 × 100 × 16/30

    // May: 12 employees at the peak, 10 committed → 2 × 100.00 in arrears. June: 8 → nothing due (recorded).
    $extra = collect(range(1, 6))->map(fn () => staff($this->tenant, '2027-05-10'));
    $this->travelTo('2027-06-01 06:00:00');
    $this->run->run($this->tenant);
    $mayUp = billingPeriodsOf($this->tenant)['annual_true_up 2027-05-01'];
    expect([$mayUp->measured_peak, $mayUp->committed_quantity, $mayUp->billed_quantity, $mayUp->amount()->toDecimal(), $mayUp->status])->toBe([12, 10, 2, '200.00', BillingPeriod::DRAFTED])
        ->and($mayUp->evidence['calculation'])->toBe('max(0, peak 12 − committed 10) = 2 × INR 100.00 = 200.00');
    $extra->take(4)->each(fn ($e) => staffExit($this->tenant, $e, '2027-05-31'));
    $this->travelTo('2027-07-01 06:00:00');
    $this->run->run($this->tenant);
    $juneUp = billingPeriodsOf($this->tenant)['annual_true_up 2027-06-01'];
    expect([$juneUp->measured_peak, $juneUp->billed_quantity, $juneUp->amount_minor, $juneUp->status, $juneUp->invoice_id])->toBe([8, 0, 0, BillingPeriod::NOTHING_DUE, null]);

    // Annual terms change only at renewal: price, interval and commitment alike.
    expect(fn () => $this->terms->set($sub, $this->monthly, '2027-08-01', 'Switch to monthly mid-term', $this->op))->toThrow(RuntimeException::class, 'only at renewal')
        ->and(fn () => $this->terms->set($sub, $annual, '2027-09-01', 'More seats mid-term', $this->op, null, 15))->toThrow(RuntimeException::class, 'next one starts 2028-05-01');
    $this->terms->set($sub, $annual, '2028-05-01', 'Renewal with more seats', $this->op, null, 15);
    $this->travelTo('2028-05-01 06:00:00');
    $this->run->run($this->tenant);
    expect(billingPeriodsOf($this->tenant)['annual_advance 2028-05-01']->amount()->toDecimal())->toBe('18000.00');
});

it('keeps an existing subscriber on the pinned price version until a notified re-pin (critical 9)', function () {
    $sub = $this->subs->start($this->tenant, $this->growth, '2027-04-01', null, 'Contract', $this->op);
    $this->terms->set($sub, $this->monthly, '2027-04-01', 'Order form', $this->op);
    staff($this->tenant, '2027-03-01');
    $this->travelTo('2027-04-10 09:00:00');
    $v2 = pepmPrice($this->growth, $this->setup['market'], 'month', '130.00', '2027-05-01', $this->op, $this->checker);   // new list price from May

    $this->travelTo('2027-06-01 06:00:00');
    $this->run->run($this->tenant);
    $p = billingPeriodsOf($this->tenant);
    expect([$p['monthly_arrears 2027-04-01']->unit_amount_minor, $p['monthly_arrears 2027-05-01']->unit_amount_minor, $p['monthly_arrears 2027-05-01']->plan_price_version_id])
        ->toBe([10000, 10000, $this->monthly->id])
        ->and(fn () => $this->monthly->fresh()->forceFill(['unit_amount_minor' => 1])->save())->toThrow(RuntimeException::class, 'immutable');

    // The notified increase applies from the period it was announced for, never before.
    app(PriceNotices::class)->record($sub, $v2, '2027-06-01', '2027-07-01', 'Increase letter', $this->op, 'LTR-7');
    $this->terms->set($sub, $v2, '2027-07-01', 'Re-pin after notice', $this->op);
    $this->travelTo('2027-08-01 06:00:00');
    $this->run->run($this->tenant);
    $p = billingPeriodsOf($this->tenant);
    expect([$p['monthly_arrears 2027-06-01']->unit_amount_minor, $p['monthly_arrears 2027-07-01']->unit_amount_minor])->toBe([10000, 13000]);
});

it('records a period it cannot bill safely as an exception, and drafts again only a discarded draft from its frozen quantity', function () {
    $sub = $this->subs->start($this->tenant, $this->growth, '2027-04-01', null, 'Contract', $this->op);
    $this->terms->set($sub, $this->monthly, '2027-04-01', 'Order form', $this->op);
    staff($this->tenant, '2027-03-01');
    $starter = publishedPlan($this->op, 'starter', ['leave' => true], '2027-04-01');
    $this->subs->changePlan($sub, $starter, '2027-05-01', 'Downgrade without a re-pin', $this->op);
    $this->travelTo('2027-06-01 06:00:00');
    expect($this->run->run($this->tenant))->toBe(['created' => 2, 'drafted' => 1, 'nothing_due' => 0, 'exceptions' => 1]);
    $may = billingPeriodsOf($this->tenant)['monthly_arrears 2027-05-01'];
    expect([$may->status, $may->invoice_id, $may->exception])->toBe([BillingPeriod::EXCEPTION, null, 'The subscription is on another plan than its billing terms: re-pin the terms from the next period.']);

    $april = billingPeriodsOf($this->tenant)['monthly_arrears 2027-04-01'];
    expect(fn () => $this->run->redraft($april, 'Not discarded', $this->op))->toThrow(RuntimeException::class, 'discarded');
    app(Invoices::class)->discard($april->invoice, 'Wrong customer address', $this->op);
    staff($this->tenant, '2027-04-01');                          // today's data must not leak into the redraft
    $again = $this->run->redraft($april->fresh(), 'Address corrected', $this->op);
    expect([$again->status, $again->subtotal_minor, $april->fresh()->invoice_id, $april->fresh()->measured_peak])->toBe([InvoiceStatus::Draft, 10000, $again->id, 1]);
});

it('runs from the scheduler command for every tenant, idempotently, and is scheduled only when enabled', function () {
    $sub = $this->subs->start($this->tenant, $this->growth, '2027-04-01', null, 'Contract', $this->op);
    $this->terms->set($sub, $this->monthly, '2027-04-01', 'Order form', $this->op);
    staff($this->tenant, '2027-03-01');
    $this->travelTo('2027-05-01 06:00:00');
    expect(Artisan::call('peopleos:billing:run'))->toBe(0)->and(Artisan::output())->toContain('1 period(s): 1 drafted')
        ->and(Artisan::call('peopleos:billing:run'))->toBe(0)->and(billingPeriodsOf($this->tenant))->toHaveCount(1);
    $event = collect(app(Schedule::class)->events())->first(fn ($e) => str_contains($e->command, 'peopleos:billing:run'));
    config(['peopleos.billing.run_enabled' => false]);
    expect($event->filtersPass(app()))->toBeFalse();
    config(['peopleos.billing.run_enabled' => true]);
    expect($event->filtersPass(app()))->toBeTrue();
});
