<?php

use App\Domain\Entitlements\Enums\Capability;
use App\Domain\Entitlements\Services\EntitlementConfiguration;
use App\Domain\Entitlements\Services\Entitlements;
use App\Domain\Entitlements\Services\EntitlementStateStore;
use App\Filament\Pages\PlatformEntitlementsPage;
use App\Filament\Pages\PlatformPlansPage;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/PlanTestHelpers.php';

/*
| SaaS.4: plans keep the SaaS.3 cost model. A tenant on a plan costs two more indexed reads on a cold cache (its
| assignments, then the pinned versions with their entitlements in one joined read) and none after; a tenant that
| never had a plan reads exactly as before. The platform screens do not grow with the catalogue (no N+1).
*/

beforeEach(function () {
    $this->travelTo('2027-01-04 09:00:00');
    $this->tenant = provisionTenant('Alpha');
    $this->operator = platformAdmin();
    $this->growth = publishedPlan($this->operator, 'growth', ['payroll' => true, 'leave' => true, 'ai' => true, 'ai.external_model' => true, 'active_employees.max' => 100], '2027-01-04');
    app(EntitlementConfiguration::class)->assignPlan($this->tenant, $this->growth, '2027-01-04', null, 'Alpha on Growth', $this->operator);
});

function planQueries(Closure $work): int
{
    DB::flushQueryLog();
    DB::enableQueryLog();
    $work();
    $count = count(DB::getQueryLog());
    DB::disableQueryLog();

    return $count;
}

it('loads a tenant on a plan with five indexed reads on a cold cache, then none, whatever is evaluated', function () {
    actAsTenant($this->tenant);
    app(EntitlementStateStore::class)->forget($this->tenant->id);
    $entitlements = app(Entitlements::class);

    expect(planQueries(fn () => $entitlements->evaluate(Capability::Payroll)))->toBe(5)
        ->and(planQueries(function () use ($entitlements) {
            foreach (Capability::cases() as $capability) {
                $entitlements->evaluate($capability);
                $entitlements->evaluate($capability, '2027-06-01', 10);
            }
        }))->toBe(0);
});

it('keeps a tenant that never had a plan at the SaaS.3 cost, even with plans in the catalogue', function () {
    $other = provisionTenant('Beta');
    actAsTenant($other);
    app(EntitlementConfiguration::class)->configure($other, '2027-01-04', 'Own terms only', $this->operator);
    app(EntitlementStateStore::class)->forget($other->id);

    expect(planQueries(fn () => app(Entitlements::class)->evaluate(Capability::Payroll)))->toBe(3);
});

it('renders the plans page with the same number of queries however many plans and versions exist', function () {
    actAsTenant(null);
    $this->actingAs($this->operator);
    $render = fn () => planQueries(fn () => $this->get(PlatformPlansPage::getUrl(['plan' => $this->growth->plan_id]))->assertOk());
    $render();
    $few = $render();

    foreach (range(1, 4) as $i) {
        publishedPlan($this->operator, "extra-{$i}", ['leave' => true, 'payroll' => true, 'users.max' => 10], '2027-01-04');
    }
    expect($render())->toBe($few);
});

it('renders a tenant on the entitlements page with the same number of queries however long its plan history is', function () {
    actAsTenant(null);
    $this->actingAs($this->operator);
    $render = fn () => planQueries(fn () => $this->get(PlatformEntitlementsPage::getUrl(['tenant' => $this->tenant->id]))->assertOk()->assertSee('growth v1'));
    $render();
    $short = $render();

    $starter = publishedPlan($this->operator, 'starter', ['leave' => true], '2027-01-04');
    foreach (['2027-02-01', '2027-03-01', '2027-04-01'] as $i => $from) {
        app(EntitlementConfiguration::class)->assignPlan($this->tenant, $i % 2 === 0 ? $starter : $this->growth, $from, null, "Plan change {$i}", $this->operator);
    }
    expect($render())->toBe($short);
});
