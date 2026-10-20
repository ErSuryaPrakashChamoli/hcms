<?php

use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Audit\Models\AuditEvent;
use App\Domain\Audit\Services\AuditIntegrityVerifier;
use App\Domain\Billing\Enums\ApprovalStatus;
use App\Domain\Billing\Enums\ConfigurationKey;
use App\Domain\Billing\Models\ConfigurationVersion;
use App\Domain\Billing\Models\FinancialApproval;
use App\Domain\Billing\Models\NegotiatedPrice;
use App\Domain\Billing\Models\NegotiatedPriceVersion;
use App\Domain\Billing\Models\SupplierProfile;
use App\Domain\Billing\Services\CommercialConfiguration;
use App\Domain\Billing\Services\NegotiatedPrices;
use App\Domain\Billing\Services\StatutoryDataset;
use App\Domain\Billing\Services\SupplierProfiles;
use App\Domain\Identity\Models\User;
use App\Domain\Payments\Services\ApprovalDesk;
use App\Domain\Subscriptions\Models\TenantSubscription;
use App\Domain\Subscriptions\Services\CommercialSubscriptions;
use App\Domain\Tax\Enums\TaxRuleStatus;
use App\Domain\Tax\Models\TaxRule;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/ConcurrencyHelpers.php';
require_once __DIR__.'/../Feature/Billing/BillingTestHelpers.php';
require_once __DIR__.'/../Feature/Entitlements/PlanTestHelpers.php';

/*
 | SaaS.7 configuration: concurrent configuration changes on MySQL. Whatever the interleaving: configuration versions
 | get distinct numbers; an approval executes once; a deal has one draft at a time and its contract windows never
 | overlap; the statutory dataset is loaded and verified once per rule; both audit chains stay valid.
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
    $suffix = substr(uniqid(), -6);
    [$this->op, $this->checker] = billingOperators();
    $this->checker2 = platformAdmin();
    $this->market = billingMarket($this->op, "K{$suffix}", 'INR', "ME-CFG-{$suffix}");
    $this->tenant = provisionTenant('Configuration race '.$suffix);
    billingProfile($this->tenant, $this->market, $this->op);
    $this->plan = publishedPlan($this->op, 'cfg-'.$suffix, ['leave' => true], now()->toDateString());
    $this->subscription = app(CommercialSubscriptions::class)->start($this->tenant, $this->plan, now()->toDateString(), null, 'Race contract', $this->op);
});

afterEach(function () {
    if (! isset($this->tenant)) {
        return;
    }
    expect(FinancialApproval::query()->where('status', ApprovalStatus::Approved)->whereNull('executed_at')->count())->toBe(0)
        ->and(app(AuditIntegrityVerifier::class)->verify($this->tenant->id)['valid'])->toBeTrue()
        ->and(app(AuditIntegrityVerifier::class)->verify(null)['valid'])->toBeTrue();
});

function configurationCall(Closure $call, User $actor): Closure
{
    return fn () => $call($actor->fresh());
}

it('12. numbers two configuration versions proposed at the same moment distinctly, both accepted', function () {
    $key = ConfigurationKey::PaymentTermsDays;
    $before = ConfigurationVersion::query()->where('key', $key->value)->count();
    $results = race([
        configurationCall(fn (User $u) => app(CommercialConfiguration::class)->propose($key, '', 20, now()->addDays(10)->toDateString(), null, 'Proposal one', $u), $this->op),
        configurationCall(fn (User $u) => app(CommercialConfiguration::class)->propose($key, '', 25, now()->addDays(11)->toDateString(), null, 'Proposal two', $u), $this->checker),
    ], slow: ['eloquent.creating: '.ConfigurationVersion::class]);

    expect($results)->toBe(['ok', 'ok']);
    $versions = ConfigurationVersion::query()->where('key', $key->value)->orderBy('version')->pluck('version')->all();
    expect($versions)->toHaveCount($before + 2)->and(array_unique($versions))->toBe($versions);
});

it('13. applies a configuration change once when two checkers approve it at the same moment', function () {
    $request = app(CommercialConfiguration::class)->propose(ConfigurationKey::PriceIncreaseNoticeDays, '', 45, now()->addDays(20)->toDateString(), null, 'Longer notice', $this->op);
    $results = race([
        configurationCall(fn (User $u) => app(ApprovalDesk::class)->approve(FinancialApproval::query()->findOrFail($request->id), 'Checker one', $u), $this->checker),
        configurationCall(fn (User $u) => app(ApprovalDesk::class)->approve(FinancialApproval::query()->findOrFail($request->id), 'Checker two', $u), $this->checker2),
    ], slow: ['eloquent.updating: '.ConfigurationVersion::class]);

    expect(collect($results)->filter(fn ($r) => $r === 'ok'))->toHaveCount(1)
        ->and(collect($results)->first(fn ($r) => $r !== 'ok'))->toContain('already approved')
        ->and(ConfigurationVersion::query()->findOrFail($request->subject_id)->status)->toBe(ConfigurationVersion::APPROVED)
        ->and(AuditEvent::query()->withoutTenancy()->where('action', AuditAction::ConfigurationApproved)->where('metadata->version', ConfigurationVersion::query()->findOrFail($request->subject_id)->version)
            ->where('metadata->key', ConfigurationKey::PriceIncreaseNoticeDays->value)->count())->toBe(1);
});

it('14. keeps one draft of a deal and never overlapping contract windows when operators act at the same moment', function () {
    $deals = app(NegotiatedPrices::class);
    $subscription = fn () => TenantSubscription::query()->withoutTenancy()->findOrFail($this->subscription->id);
    $create = fn (string $from) => configurationCall(fn (User $u) => $deals->create($subscription(), $this->plan->id, $this->market->fresh(), 'month', 'per_active_employee', $from, null,
        'MSA-RACE', null, 'Deal agreed', $u), $this->op);
    $results = race([$create(now()->toDateString()), $create(now()->addDays(5)->toDateString())], slow: ['eloquent.creating: '.NegotiatedPrice::class]);
    expect(collect($results)->filter(fn ($r) => $r === 'ok'))->toHaveCount(1)
        ->and(collect($results)->first(fn ($r) => $r !== 'ok'))->toContain('in that window');

    actAsTenant($this->tenant);
    $deal = NegotiatedPrice::query()->sole();
    actAsTenant(null);
    $draft = fn (string $amount) => configurationCall(fn (User $u) => $deals->draftVersion(NegotiatedPrice::query()->withoutTenancy()->findOrFail($deal->id), $amount, 0, null, null, 'Draft', $u), $this->op);
    $results = race([$draft('80.00'), $draft('81.00')], slow: ['eloquent.creating: '.NegotiatedPriceVersion::class]);
    expect(collect($results)->filter(fn ($r) => $r === 'ok'))->toHaveCount(1)
        ->and(collect($results)->first(fn ($r) => $r !== 'ok'))->toContain('already has a draft');
    actAsTenant($this->tenant);
    expect(NegotiatedPriceVersion::query()->count())->toBe(1);
});

it('15. loads and verifies the statutory dataset once per rule when operators act at the same moment', function () {
    $dataset = app(StatutoryDataset::class);
    $load = fn (User $actor) => configurationCall(fn (User $u) => $dataset->load('2026.10', 'Load the shipped statutory values', $u), $actor);
    $results = race([$load($this->op), $load($this->op)], slow: ['eloquent.creating: '.TaxRule::class]);
    expect(collect($results)->filter(fn ($r) => $r === 'ok'))->not->toBeEmpty()
        ->and(TaxRule::query()->where('dataset_version', '2026.10')->count())->toBe(36);

    $activate = fn (User $actor, string $reference) => configurationCall(fn (User $u) => $dataset->activate('2026.10', $reference, $u), $actor);
    $results = race([$activate($this->checker, 'RACE-VERIFY-1'), $activate($this->checker2, 'RACE-VERIFY-2')], slow: ['eloquent.updating: '.TaxRule::class]);
    expect(collect($results)->filter(fn ($r) => $r === 'ok'))->not->toBeEmpty()
        ->and(TaxRule::query()->where(['dataset_version' => '2026.10', 'status' => TaxRuleStatus::Verified])->count())->toBe(35)
        ->and(AuditEvent::query()->withoutTenancy()->where('action', AuditAction::TaxRuleVerified)->whereIn('entity_id', TaxRule::query()->where('dataset_version', '2026.10')->pluck('id'))->count())->toBe(35)
        ->and(ConfigurationVersion::query()->where(['dataset_version' => '2026.10', 'status' => ConfigurationVersion::APPROVED])->count())->toBe(1)
        ->and(AuditEvent::query()->withoutTenancy()->where('action', AuditAction::StatutoryDatasetActivated)->where('metadata->dataset_version', '2026.10')->count())->toBe(1)   // verified once
        ->and(collect($results)->first(fn ($r) => $r !== 'ok') ?? 'Nothing')->toContain('Nothing');
});

it('16. numbers two selling-entity versions proposed at the same moment distinctly, and applies a version once when two checkers approve it', function () {
    $entity = 'ME-RACE-'.substr(uniqid(), -6);
    $data = fn (string $name) => ['legal_name' => $name, 'address_line1' => '1 Race Street', 'city' => 'Mumbai', 'country' => 'IN', 'subdivision' => 'IN-MH',
        'tax_id_type' => 'IN_GSTIN', 'tax_id_value' => fictionalGstin('27')];
    $propose = fn (string $name) => configurationCall(fn (User $u) => app(SupplierProfiles::class)->propose($entity, $data($name), now()->toDateString(), 'Race proposal', $u), $this->op);
    expect(race([$propose('Entity A'), $propose('Entity B')], slow: ['eloquent.creating: '.SupplierProfile::class]))->toBe(['ok', 'ok']);
    $versions = SupplierProfile::query()->where('entity_code', $entity)->orderBy('version')->pluck('version')->all();
    expect($versions)->toBe([1, 2]);

    $request = FinancialApproval::query()->where('action', 'supplier_profile_change')->where('subject_id', SupplierProfile::query()->where(['entity_code' => $entity, 'version' => 1])->value('id'))->sole();
    $results = race([
        configurationCall(fn (User $u) => app(ApprovalDesk::class)->approve(FinancialApproval::query()->findOrFail($request->id), 'Checker one', $u), $this->checker),
        configurationCall(fn (User $u) => app(ApprovalDesk::class)->approve(FinancialApproval::query()->findOrFail($request->id), 'Checker two', $u), $this->checker2),
    ], slow: ['eloquent.updating: '.SupplierProfile::class]);
    expect(collect($results)->filter(fn ($r) => $r === 'ok'))->toHaveCount(1)
        ->and(collect($results)->first(fn ($r) => $r !== 'ok'))->toContain('already approved')
        ->and(SupplierProfile::query()->where(['entity_code' => $entity, 'version' => 1])->value('status'))->toBe('approved')
        ->and(AuditEvent::query()->withoutTenancy()->where('action', AuditAction::SupplierProfileRecorded)->where('entity_label', "Supplier {$entity} v1")->count())->toBe(1);
});
