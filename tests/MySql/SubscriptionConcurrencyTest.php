<?php

use App\Domain\Audit\Models\AuditEvent;
use App\Domain\Audit\Services\AuditIntegrityVerifier;
use App\Domain\Entitlements\Models\PlanVersion;
use App\Domain\Entitlements\Models\TenantPlanAssignment;
use App\Domain\Platform\Models\Tenant;
use App\Domain\Subscriptions\Models\SubscriptionPeriod;
use App\Domain\Subscriptions\Models\TenantSubscription;
use App\Domain\Subscriptions\Services\CommercialSubscriptions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/ConcurrencyHelpers.php';
require_once __DIR__.'/../Feature/Entitlements/PlanTestHelpers.php';

/*
 | SaaS.6: concurrent commercial lifecycle changes on MySQL. Whatever the interleaving: never two live subscriptions,
 | never overlapping live periods, never a duplicate conversion or expiry, the projected plan assignments always
 | equal the entitled periods, and both audit chains stay valid. Same opt-in as the other MySQL suites.
 */

beforeEach(function () {
    $database = (string) env('PEOPLEOS_MYSQL_CONCURRENCY_DB', '');
    if ($database === '' || ! str_contains($database, 'concurrency') || ! function_exists('pcntl_fork')) {
        $this->markTestSkipped('Set PEOPLEOS_MYSQL_CONCURRENCY_DB to a disposable MySQL database (name containing "concurrency") and enable pcntl.');
    }
    config(['database.connections.concurrency' => array_merge(config('database.connections.mysql'), ['database' => $database]), 'database.default' => 'concurrency',
        'queue.default' => 'sync', 'cache.default' => 'database', 'cache.stores.database.connection' => 'concurrency']);
    DB::purge('concurrency');
    if (! ($GLOBALS['peopleos_concurrency_migrated'] ?? false)) {
        Artisan::call('migrate:fresh', ['--database' => 'concurrency', '--force' => true]);
        $GLOBALS['peopleos_concurrency_migrated'] = true;
    }
    $this->tenant = provisionTenant('Subscription race '.uniqid());
    $this->operator = platformAdmin();
    $this->today = now()->toDateString();
    $this->version = publishedPlan($this->operator, 'race-'.substr(uniqid(), -6), ['leave' => true, 'payroll' => true], $this->today);
    actAsTenant($this->tenant);
});

afterEach(function () {
    if (isset($this->tenant)) {
        actAsTenant($this->tenant);
        // Live periods of one subscription never overlap, and the plan in force equals the entitled periods exactly.
        foreach (TenantSubscription::query()->get() as $subscription) {
            $live = SubscriptionPeriod::query()->where('subscription_id', $subscription->id)->whereNull('voided_at')->orderBy('starts_on')->get();
            foreach ($live->values() as $i => $period) {
                $next = $live->values()[$i + 1] ?? null;
                if ($next !== null) {
                    expect($period->ends_on?->toDateString())->not->toBeNull()->and($period->ends_on->toDateString() < $next->starts_on->toDateString())->toBeTrue();
                }
            }
        }
        $entitled = SubscriptionPeriod::query()->whereNull('voided_at')->whereIn('status', ['trial', 'active', 'grace'])->orderBy('starts_on')->get()
            ->map(fn ($p) => [$p->plan_version_id, $p->status->value, $p->starts_on->toDateString(), $p->ends_on?->toDateString()])->all();
        $assigned = TenantPlanAssignment::query()->where('status', 'active')->whereNotNull('subscription_id')->orderBy('effective_from')->get()
            ->map(fn ($a) => [$a->plan_version_id, $a->commercial_status, $a->effective_from->toDateString(), $a->effective_to?->toDateString()])->all();
        expect($assigned)->toBe($entitled)
            ->and(app(AuditIntegrityVerifier::class)->verify($this->tenant->id)['valid'])->toBeTrue()
            ->and(app(AuditIntegrityVerifier::class)->verify(null)['valid'])->toBeTrue();
    }
});

/** A subscription operation as a separate process makes it (fresh tenant, subscription and operator instances). */
function subscriptionCall(object $test, Closure $call): Closure
{
    return function () use ($test, $call) {
        $call(app(CommercialSubscriptions::class), Tenant::query()->findOrFail($test->tenant->id), $test->operator->fresh(),
            fn (int $id) => TenantSubscription::query()->withoutTenancy()->findOrFail($id));
    };
}

it('1. lets exactly one of two operators\' conflicting transitions win, and leaves a consistent timeline', function () {
    $sub = app(CommercialSubscriptions::class)->startTrial($this->tenant, $this->version, $this->today, now()->addDays(13)->toDateString(), 'Trial', $this->operator);
    $results = race([
        subscriptionCall($this, fn ($s, $t, $op, $find) => $s->convert($find($sub->id), $this->today, null, 'Operator one converts', $op)),
        subscriptionCall($this, fn ($s, $t, $op, $find) => $s->cancel($find($sub->id), $this->today, 'Operator two cancels', $op)),
    ], slow: ['eloquent.creating: '.SubscriptionPeriod::class]);

    // The cancellation is legal from a trial and from an active term, so it always lands; the conversion lands only if first.
    expect($results[1])->toBe('ok');
    actAsTenant($this->tenant);
    expect(TenantSubscription::query()->with('periods')->findOrFail($sub->id)->timeline()->stateOn($this->today)['status']->value)->toBe('cancelled');
    if ($results[0] !== 'ok') {
        expect($results[0])->toContain('cancelled');
    }
});

it('2. creates one live subscription when two operators start one for the same tenant at once', function () {
    $results = race([
        subscriptionCall($this, fn ($s, $t, $op) => $s->startTrial($t, PlanVersion::query()->findOrFail($this->version->id), $this->today, now()->addDays(13)->toDateString(), 'Operator one: trial', $op)),
        subscriptionCall($this, fn ($s, $t, $op) => $s->start($t, PlanVersion::query()->findOrFail($this->version->id), $this->today, null, 'Operator two: direct start', $op)),
    ], slow: ['eloquent.creating: '.TenantSubscription::class]);

    expect(collect($results)->filter(fn ($r) => $r === 'ok'))->toHaveCount(1)
        ->and(collect($results)->first(fn ($r) => $r !== 'ok'))->toContain('already has subscription')
        ->and(TenantSubscription::query()->count())->toBe(1);
});

it('3. converts once when the same conversion is submitted twice at once', function () {
    $sub = app(CommercialSubscriptions::class)->startTrial($this->tenant, $this->version, $this->today, now()->addDays(13)->toDateString(), 'Trial', $this->operator);
    $convert = subscriptionCall($this, fn ($s, $t, $op, $find) => $s->convert($find($sub->id), $this->today, null, 'Contract signed', $op));
    expect(race([$convert, $convert], slow: ['eloquent.creating: '.SubscriptionPeriod::class]))->toBe(['ok', 'ok']);

    expect(SubscriptionPeriod::query()->where('status', 'active')->count())->toBe(1)
        ->and(AuditEvent::query()->withoutTenancy()->where('tenant_id', $this->tenant->id)->where('action', 'TRIAL_CONVERTED')->count())->toBe(1);
});

it('4. records one expiry when two settlements run at once, however late', function () {
    $this->travelTo(now()->subDays(20));
    $from = now()->toDateString();
    $old = publishedPlan($this->operator, 'old-'.substr(uniqid(), -6), ['leave' => true], $from);
    $sub = app(CommercialSubscriptions::class)->startTrial($this->tenant, $old, $from, now()->addDays(4)->toDateString(), 'Old trial', $this->operator);
    $this->travelBack();
    $settle = subscriptionCall($this, fn ($s, $t) => $s->settle($t));
    expect(race([$settle, $settle], slow: ['eloquent.creating: '.SubscriptionPeriod::class]))->toBe(['ok', 'ok']);

    expect(SubscriptionPeriod::query()->where('status', 'expired')->count())->toBe(1)
        ->and(SubscriptionPeriod::query()->where('status', 'expired')->sole()->starts_on->toDateString())->toBe(Carbon::parse($from)->addDays(5)->toDateString())
        ->and(AuditEvent::query()->withoutTenancy()->where('tenant_id', $this->tenant->id)->where('action', 'SUBSCRIPTION_EXPIRED')->count())->toBe(1);
});

it('5. resolves a reactivation racing the settlement of the same lapse: the same state on every day, whichever wins', function () {
    $this->travelTo(now()->subDays(10));
    $old = publishedPlan($this->operator, 'old-'.substr(uniqid(), -6), ['leave' => true], now()->toDateString());
    $sub = app(CommercialSubscriptions::class)->startTrial($this->tenant, $old, now()->toDateString(), now()->addDays(8)->toDateString(), 'Trial ended yesterday', $this->operator);
    $this->travelBack();
    $results = race([
        subscriptionCall($this, fn ($s, $t) => $s->settle($t)),
        subscriptionCall($this, fn ($s, $t, $op, $find) => $s->reactivate($find($sub->id), $this->today, null, 'Late signature', $op)),
    ], slow: ['eloquent.creating: '.SubscriptionPeriod::class]);

    expect($results)->toBe(['ok', 'ok']);
    actAsTenant($this->tenant);
    $timeline = TenantSubscription::query()->with('periods')->findOrFail($sub->id)->timeline();
    $yesterday = now()->subDay()->toDateString();
    expect($timeline->stateOn($this->today)['status']->value)->toBe('active')
        ->and($timeline->stateOn($yesterday)['status']->value)->toBe('expired');   // decided by the dates, either order
    // The settlement records the expiry only if it ran first; then the reactivation closed it the day before.
    $expiries = SubscriptionPeriod::query()->where('status', 'expired')->whereNull('voided_at')->get();
    expect($expiries->count())->toBeLessThanOrEqual(1);
    if ($expiries->isNotEmpty()) {
        expect([$expiries->sole()->starts_on->toDateString(), $expiries->sole()->ends_on?->toDateString()])->toBe([$yesterday, $yesterday]);
    }
});

it('6. resolves a cancellation racing a reactivation from grace: either order ends cancelled, nothing overlaps', function () {
    $s = app(CommercialSubscriptions::class);
    $sub = $s->start($this->tenant, $this->version, $this->today, null, 'Contract', $this->operator);
    $sub = $s->enterGrace($sub, $this->today, now()->addDays(9)->toDateString(), 'Renewal pending', $this->operator);
    $results = race([
        subscriptionCall($this, fn ($s, $t, $op, $find) => $s->cancel($find($sub->id), $this->today, 'Customer leaves', $op)),
        subscriptionCall($this, fn ($s, $t, $op, $find) => $s->reactivate($find($sub->id), $this->today, null, 'Renewal signed', $op)),
    ], slow: ['eloquent.creating: '.SubscriptionPeriod::class, 'eloquent.updating: '.SubscriptionPeriod::class]);

    expect($results[0])->toBe('ok');
    actAsTenant($this->tenant);
    expect(TenantSubscription::query()->with('periods')->findOrFail($sub->id)->timeline()->stateOn($this->today)['status']->value)->toBe('cancelled');
    if ($results[1] !== 'ok') {
        expect($results[1])->toContain('cancelled'); // the cancellation came first: nothing to reactivate
    }
});

it('7. refuses an extension racing the lapse of the same trial on the day after its end, whichever runs first', function () {
    $this->travelTo(now()->subDays(3));
    $old = publishedPlan($this->operator, 'old-'.substr(uniqid(), -6), ['leave' => true], now()->toDateString());
    $sub = app(CommercialSubscriptions::class)->startTrial($this->tenant, $old, now()->toDateString(), now()->addDays(1)->toDateString(), 'Trial ended yesterday', $this->operator);
    $this->travelBack();
    $results = race([
        subscriptionCall($this, fn ($s, $t) => $s->settle($t)),
        subscriptionCall($this, fn ($s, $t, $op, $find) => $s->extend($find($sub->id), now()->addDays(10)->toDateString(), 'Too late to extend', $op)),
    ]);

    expect($results[0])->toBe('ok')->and($results[1])->toContain('lapsed')
        ->and(SubscriptionPeriod::query()->where('status', 'expired')->count())->toBe(1);
});
