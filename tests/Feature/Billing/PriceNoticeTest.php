<?php

use App\Domain\Billing\Models\BillingPeriod;
use App\Domain\Billing\Models\CreditNote;
use App\Domain\Billing\Models\FinancialApproval;
use App\Domain\Billing\Models\InvoiceTdsClaim;
use App\Domain\Billing\Models\PriceChangeNotice;
use App\Domain\Billing\Models\SubscriptionBillingTerm;
use App\Domain\Billing\Services\BillingPeriods;
use App\Domain\Billing\Services\BillingTerms;
use App\Domain\Billing\Services\PriceNotices;
use App\Domain\Payments\Models\Refund;
use App\Domain\Subscriptions\Services\CommercialSubscriptions;
use App\Filament\Pages\PlatformApprovalsPage;
use App\Support\Tenancy\BelongsToTenant;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\Schema;

require_once __DIR__.'/BillingTestHelpers.php';
require_once __DIR__.'/../Entitlements/PlanTestHelpers.php';

/*
| SaaS.7 completion (B-15): existing subscribers keep their pinned price until the next period (monthly) or renewal
| (annual) after a written notice of at least 30 days; a decrease needs no notice. And every new billing record is
| the tenant's own (fail-closed); approvals are platform records no tenant user can reach.
*/

beforeEach(function () {
    $this->travelTo('2027-04-01 09:00:00');
    $this->setup = indiaBilling();
    [$this->op, $this->checker] = [$this->setup['operator'], $this->setup['verifier']];
    $this->growth = publishedPlan($this->op, 'growth', ['leave' => true], '2027-04-01');
    $this->v1 = pepmPrice($this->growth, $this->setup['market'], 'month', '100.00', '2027-04-01', $this->op, $this->checker);
    $this->tenant = provisionTenant('Alpha');
    billingProfile($this->tenant, $this->setup['market'], $this->op);
    $this->sub = app(CommercialSubscriptions::class)->start($this->tenant, $this->growth, '2027-04-01', null, 'Contract', $this->op);
    $this->terms = app(BillingTerms::class);
    $this->terms->set($this->sub, $this->v1, '2027-04-01', 'Order form', $this->op);
    $this->notices = app(PriceNotices::class);
});

it('raises an existing subscriber\'s price only after 30 days\' written notice, at a period start, by an operator re-pin (critical 10)', function () {
    $v2 = pepmPrice($this->growth, $this->setup['market'], 'month', '125.00', '2027-05-01', $this->op, $this->checker);
    expect(fn () => $this->terms->set($this->sub, $v2, '2027-05-01', 'Increase without notice', $this->op))->toThrow(RuntimeException::class, 'at least 30 days')
        ->and(fn () => $this->notices->record($this->sub, $v2, '2027-04-01', '2027-05-01', 'Only 30 days? exactly', $this->op))->not->toThrow(RuntimeException::class);
    $this->terms->set($this->sub, $v2, '2027-05-01', 'Re-pin after the notice', $this->op);
    $notice = app(TenantContext::class)->runAs($this->tenant, fn () => PriceChangeNotice::query()->sole());
    expect($notice->status)->toBe(PriceChangeNotice::APPLIED)
        ->and(app(TenantContext::class)->runAs($this->tenant, fn () => SubscriptionBillingTerm::query()->whereDate('effective_from', '2027-05-01')->sole()->price_notice_id))->toBe($notice->id);

    $this->travelTo('2027-05-10 09:00:00');
    $v3 = pepmPrice($this->growth, $this->setup['market'], 'month', '150.00', '2027-06-01', $this->op, $this->checker);
    expect(fn () => $this->notices->record($this->sub, $v3, '2027-05-10', '2027-06-01', 'Twenty-two days', $this->op))->toThrow(RuntimeException::class, 'at least 30 days')
        ->and(fn () => $this->notices->record($this->sub, $v3, '2027-05-12', '2027-07-01', 'Notice dated tomorrow', $this->op))->toThrow(RuntimeException::class, 'today or earlier')
        ->and(fn () => $this->notices->record($this->sub, $v3, '2027-05-10', '2027-07-15', 'Mid-month', $this->op))->toThrow(RuntimeException::class);
    $this->notices->record($this->sub, $v3, '2027-05-10', '2027-07-01', 'Notice sent', $this->op, 'LETTER-2');
    expect($this->notices->pending($this->tenant)->pluck('effective_from')->map->toDateString()->all())->toBe(['2027-07-01'])   // the re-pin worklist
        ->and(fn () => $this->terms->set($this->sub, $v3, '2027-06-01', 'Before the notice date', $this->op))->toThrow(RuntimeException::class, 'record a written notice');
    $this->terms->set($this->sub, $v3, '2027-07-01', 'Re-pin when due', $this->op);
    expect($this->notices->pending($this->tenant))->toBeEmpty();

    // A decrease needs no notice but still starts with a period.
    $this->travelTo('2027-07-05 09:00:00');
    $v4 = pepmPrice($this->growth, $this->setup['market'], 'month', '90.00', '2027-07-05', $this->op, $this->checker);
    expect(fn () => $this->terms->set($this->sub, $v4, '2027-07-15', 'Mid-month decrease', $this->op))->toThrow(RuntimeException::class, '1st of a month')
        ->and(fn () => $this->notices->record($this->sub, $v4, '2027-07-05', '2027-09-01', 'Not an increase', $this->op))->toThrow(RuntimeException::class, 'not a price increase');
    expect($this->terms->set($this->sub, $v4, '2027-08-01', 'Decrease from the next period', $this->op)->plan_price_version_id)->toBe($v4->id);
});

it('keeps billing periods, notices, credit notes, refunds and TDS claims inside their tenant, and approvals away from every tenant user (critical 21)', function () {
    $beta = provisionTenant('Beta');
    billingProfile($beta, $this->setup['market'], $this->op);
    $betaSub = app(CommercialSubscriptions::class)->start($beta, $this->growth, '2027-04-01', null, 'Contract', $this->op);
    $this->terms->set($betaSub, $this->v1, '2027-04-01', 'Order form', $this->op);
    staff($this->tenant, '2027-03-01');
    staff($beta, '2027-03-01');
    staff($beta, '2027-03-01');
    $this->travelTo('2027-05-01 06:00:00');
    app(BillingPeriods::class)->run($this->tenant);
    app(BillingPeriods::class)->run($beta);
    // Each tenant's peak counts only its own employees.
    expect([billingPeriodsOf($this->tenant)->sole()->measured_peak, billingPeriodsOf($beta)->sole()->measured_peak])->toBe([1, 2]);

    $models = [BillingPeriod::class, PriceChangeNotice::class, CreditNote::class, Refund::class, InvoiceTdsClaim::class];
    $counts = fn () => array_map(fn ($m) => $m::query()->count(), $models);
    actAsTenant(null);
    expect($counts())->toBe([0, 0, 0, 0, 0]);                                    // fail-closed without a tenant
    expect(app(TenantContext::class)->runAs($this->tenant, fn () => BillingPeriod::query()->pluck('tenant_id')->unique()->all()))->toBe([$this->tenant->id])
        ->and(app(TenantContext::class)->runAs($beta, fn () => BillingPeriod::query()->pluck('tenant_id')->unique()->all()))->toBe([$beta->id]);

    // Approvals are platform records: no tenant user reaches the page, whatever their permissions.
    foreach ([tenantUser($this->tenant, ['*']), tenantUser($beta, ['leave.apply'])] as $user) {
        $this->actingAs($user);
        $this->get(PlatformApprovalsPage::getUrl())->assertForbidden();
    }
    expect(in_array(BelongsToTenant::class, class_uses_recursive(FinancialApproval::class), true))->toBeFalse()
        ->and(Schema::hasColumn('financial_approvals', 'tenant_id'))->toBeFalse();
});
