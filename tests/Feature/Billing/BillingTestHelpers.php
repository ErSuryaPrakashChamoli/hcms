<?php

use App\Domain\Billing\Models\BillingMarket;
use App\Domain\Billing\Models\BillingPeriod;
use App\Domain\Billing\Models\FinancialApproval;
use App\Domain\Billing\Models\Invoice;
use App\Domain\Billing\Models\InvoiceNumberSeries;
use App\Domain\Billing\Models\PlanPrice;
use App\Domain\Billing\Models\PlanPriceVersion;
use App\Domain\Billing\Models\SupplierProfile;
use App\Domain\Billing\Models\TenantBillingProfile;
use App\Domain\Billing\Services\BillingCatalog;
use App\Domain\Billing\Services\BillingProfiles;
use App\Domain\Billing\Services\Invoices;
use App\Domain\Billing\Services\InvoiceSeries;
use App\Domain\Billing\Services\SupplierProfiles;
use App\Domain\Billing\Support\InvoiceLineInput;
use App\Domain\Employment\Models\Employee;
use App\Domain\Entitlements\Models\PlanVersion;
use App\Domain\Identity\Models\User;
use App\Domain\Lifecycle\Enums\LifecycleState;
use App\Domain\Lifecycle\Services\LifecycleEngine;
use App\Domain\Payments\Providers\SandboxProvider;
use App\Domain\Payments\Services\ApprovalDesk;
use App\Domain\Platform\Models\Tenant;
use App\Domain\Tax\Enums\TaxRegime;
use App\Domain\Tax\Jurisdictions\India\GstinValidator;
use App\Domain\Tax\Jurisdictions\India\GstStates;
use App\Domain\Tax\Models\TaxRule;
use App\Domain\Tax\Services\TaxRules;
use App\Support\Money\Money;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;

/*
 | SaaS.7 test helpers. Every value is fictional: GSTINs are built on the pseudo-PAN ZZZZZ9999Z with a computed
 | check character, rates are odd test values (never a real business decision), entities and names are invented.
 */

function fictionalGstin(string $stateCode, string $entity = '1'): string
{
    $first14 = $stateCode.'ZZZZZ9999Z'.$entity.'Z';

    return $first14.GstinValidator::checkCharacter($first14);
}

/** Two operators: rules are verified by someone other than their author. @return array{0: User, 1: User} */
function billingOperators(): array
{
    return [platformAdmin(), platformAdmin()];
}

function indiaSupplier(User $operator, string $entity = 'MARKEDGE-IN-TEST', string $subdivision = 'IN-MH', ?string $from = null): SupplierProfile
{
    $code = substr(fictionalGstin(GstStates::code($subdivision)), 0, 2);

    return supplierVersion($entity, ['legal_name' => 'Markedge Test Supplier Pvt Ltd', 'address_line1' => '1 Test Street', 'city' => 'Testpur',
        'postal_code' => '400001', 'country' => 'IN', 'subdivision' => $subdivision, 'tax_id_type' => 'IN_GSTIN', 'tax_id_value' => fictionalGstin($code)],
        $from ?? now()->toDateString(), $operator);
}

/** A selling-entity version proposed by $maker and approved by a second operator (it applies only once approved). */
function supplierVersion(string $entity, array $data, string $from, User $maker, ?User $checker = null): SupplierProfile
{
    $approval = app(SupplierProfiles::class)->propose($entity, $data, $from, 'Fictional supplier for tests', $maker);
    approveAs($approval, $checker);

    return SupplierProfile::query()->findOrFail($approval->subject_id);
}

function billingMarket(User $operator, string $code = 'IN-TEST', string $currency = 'INR', string $entity = 'MARKEDGE-IN-TEST', string $locale = 'en_IN', array $countries = ['IN']): BillingMarket
{
    return app(BillingCatalog::class)->createMarket($code, "Test market {$code}", $currency, $countries, $entity, $locale, 'Fictional market for tests', $operator);
}

function invoiceSeries(User $operator, string $entity = 'MARKEDGE-IN-TEST', string $prefix = 'TST/', ?string $from = null, ?string $to = null): InvoiceNumberSeries
{
    return app(InvoiceSeries::class)->create($entity, $prefix, $from ?? now()->toDateString(), $to ?? now()->addYear()->toDateString(), 6, 'Fictional series for tests', $operator);
}

/** A verified India GST rule with fictional rates (7.5 % split 3.75 + 3.75), category peopleos.subscription. */
function verifiedIndiaRule(User $author, User $verifier, ?string $from = null, array $outcomes = []): TaxRule
{
    $rules = app(TaxRules::class);
    $rule = $rules->draft(TaxRegime::InGst, 'IN', null, 'peopleos.subscription', $from ?? now()->toDateString(), $outcomes ?: [
        'intra_state' => [['type' => 'CGST', 'rate' => '3.75'], ['type' => 'SGST', 'rate' => '3.75']],
        'intra_union_territory' => [['type' => 'CGST', 'rate' => '3.75'], ['type' => 'UTGST', 'rate' => '3.75']],
        'inter_state' => [['type' => 'IGST', 'rate' => '7.5']],
    ], 'half_up', ['sac' => '000000'], 'Fictional test rule', $author);
    $rules->submit($rule, 'Submitted for test review', $author);

    return $rules->verify($rule, 'TEST-REVIEW-1', 'Fictional verification', $verifier);
}

/** @param  array<string, mixed>  $overrides */
function billingProfile(Tenant $tenant, BillingMarket $market, User $operator, array $overrides = [], ?string $from = null): TenantBillingProfile
{
    $subdivision = $overrides['subdivision'] ?? 'IN-KA';
    $data = array_merge(['customer_type' => 'business', 'legal_name' => "{$tenant->name} Test Customer Ltd", 'billing_email' => 'billing@example.test',
        'address_line1' => '2 Customer Road', 'city' => 'Custombad', 'postal_code' => '560001', 'country' => 'IN', 'subdivision' => $subdivision,
        'tax_registration' => 'registered', 'tax_id_type' => 'IN_GSTIN',
        'tax_id_value' => fictionalGstin(GstStates::code($subdivision) ?? '29', '2')], $overrides);

    return app(BillingProfiles::class)->record($tenant, $market, $data, $from ?? now()->toDateString(), 'Fictional billing profile', $operator);
}

/** A draft with $amounts (major units of the market currency) as lines of quantity 1. */
function draftInvoice(Tenant $tenant, BillingMarket $market, User $operator, array $amounts = ['1000.00'], ?string $key = null): Invoice
{
    $lines = array_map(fn (string $a, int $i) => new InvoiceLineInput('Test service line '.($i + 1), 1, Money::parse($a, $market->currency)), $amounts, array_keys($amounts));

    return app(Invoices::class)->draft($tenant, $market, $lines, 'Fictional draft for tests', $operator, idempotencyKey: $key);
}

/** B-13: approves (and so carries out) a maker-checker request as a second operator. */
function approveAs(FinancialApproval $approval, ?User $checker = null, string $reason = 'Checked and approved'): FinancialApproval
{
    return app(ApprovalDesk::class)->approve($approval, $reason, $checker ?? platformAdmin());
}

/** Publishes a draft price version through maker-checker: $maker requests, a second operator approves. */
function publishVersion(PlanPriceVersion $version, string $from, User $maker, ?User $checker = null): PlanPriceVersion
{
    approveAs(app(BillingCatalog::class)->requestPublication($version, $from, 'Fictional publication', $maker), $checker);

    return $version->fresh();
}

/** The whole India setup: supplier, market, series, verified rule. @return array{market: BillingMarket, supplier: SupplierProfile, rule: TaxRule, operator: User, verifier: User} */
function indiaBilling(): array
{
    [$operator, $verifier] = billingOperators();
    $supplier = indiaSupplier($operator);
    $market = billingMarket($operator);
    invoiceSeries($operator);
    $rule = verifiedIndiaRule($operator, $verifier);

    return compact('market', 'supplier', 'rule', 'operator', 'verifier');
}

/*
 | SaaS.7 completion helpers: employees with an effective-dated lifecycle history (recorded through the engine,
 | possibly back-dated), and the billing run's periods.
 */

/** A pre-employee who joins (joined → probation) on $joined, when given. */
function staff(Tenant $tenant, ?string $joined): Employee
{
    return app(TenantContext::class)->runAs($tenant, function () use ($joined) {
        $employee = LifecycleEngine::unguarded(fn () => Employee::factory()
            ->create(['lifecycle_state' => LifecycleState::PreEmployee, 'joining_date' => null]));
        if ($joined !== null) {
            $engine = app(LifecycleEngine::class);
            $engine->transition($employee, LifecycleState::Joined, $joined, 'Joined');
            $engine->transition($employee, LifecycleState::Probation, $joined, 'Probation');
        }

        return $employee->fresh();
    });
}

function staffMove(Tenant $tenant, Employee $employee, LifecycleState $to, string $on): Employee
{
    return app(TenantContext::class)->runAs($tenant,
        fn () => app(LifecycleEngine::class)->transition($employee->fresh(), $to, $on, 'Lifecycle change')->fresh());
}

/** Notice → exited (last day $lastDay) → alumni. */
function staffExit(Tenant $tenant, Employee $employee, string $lastDay): Employee
{
    staffMove($tenant, $employee, LifecycleState::NoticePeriod, $lastDay);
    staffMove($tenant, $employee, LifecycleState::Exited, $lastDay);

    return staffMove($tenant, $employee, LifecycleState::Alumni, $lastDay);
}

/** @return Collection<int, BillingPeriod> keyed "kind start" */
function billingPeriodsOf(Tenant $tenant): Collection
{
    return app(TenantContext::class)->runAs($tenant, fn () => BillingPeriod::query()->with('invoice')->orderBy('period_start')->orderBy('kind')->get()
        ->keyBy(fn ($p) => "{$p->kind->value} {$p->period_start->toDateString()}"));
}

/** A per-employee price of $planVersion in $market, its first version published (maker-checker) from $from. */
function pepmPrice(PlanVersion $planVersion, BillingMarket $market, string $interval, string $amount, string $from, User $maker, User $checker, int $minimum = 0): PlanPriceVersion
{
    $catalog = app(BillingCatalog::class);
    $price = PlanPrice::query()->where(['plan_version_id' => $planVersion->id, 'market_id' => $market->id, 'interval' => $interval])->first()
        ?? $catalog->createPrice($planVersion, $market, $interval, 'per_active_employee', 'Fictional PEPM price', $maker);

    return publishVersion($catalog->draftPriceVersion($price, $amount, 'Fictional amount', $maker, $minimum), $from, $maker, $checker);
}

/** Razorpay in TEST MODE with fictional keys (never a real key; no request leaves the test: see fakeRazorpay()). */
function razorpayTestMode(array $overrides = []): void
{
    config(['peopleos.billing.razorpay' => array_merge(config('peopleos.billing.razorpay'), ['enabled' => true, 'key_id' => 'rzp_test_FICTIONAL0001',
        'key_secret' => 'fictional-key-secret', 'webhook_secret' => 'fictional-razorpay-webhook-secret'], $overrides)]);
}

/**
 * A fake Razorpay API: orders (create, list by receipt, an order's payments) and refunds, kept in memory.
 *
 * @return ArrayObject<string, mixed> the fake's state: orders, payments (by order id), refunds, requests
 */
function fakeRazorpay(): ArrayObject
{
    $state = new ArrayObject(['orders' => [], 'payments' => [], 'refunds' => [], 'requests' => []]);
    Http::fake(function (Request $request) use ($state) {
        $path = parse_url($request->url(), PHP_URL_PATH);
        parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);
        $state['requests'] = [...$state['requests'], [$request->method(), $path, $request->hasHeader('Authorization') ? 'auth' : 'none']];
        if ($request->method() === 'GET' && $path === '/v1/orders') {
            $items = array_values(array_filter($state['orders'], fn ($o) => ! isset($query['receipt']) || $o['receipt'] === $query['receipt']));

            return Http::response(['entity' => 'collection', 'count' => count($items), 'items' => $items]);
        }
        if ($request->method() === 'POST' && $path === '/v1/orders') {
            $order = ['id' => 'order_FAKE'.str_pad((string) (count($state['orders']) + 1), 6, '0', STR_PAD_LEFT), 'entity' => 'order', 'amount' => $request['amount'],
                'currency' => $request['currency'], 'receipt' => $request['receipt'], 'status' => 'created', 'notes' => $request['notes']];
            $state['orders'] = [...$state['orders'], $order];

            return Http::response($order);
        }
        if ($request->method() === 'GET' && preg_match('#^/v1/orders/([^/]+)/payments$#', $path, $m)) {
            $items = $state['payments'][$m[1]] ?? [];

            return Http::response(['entity' => 'collection', 'count' => count($items), 'items' => $items]);
        }
        if ($request->method() === 'GET' && preg_match('#^/v1/payments/([^/]+)/refunds$#', $path, $m)) {
            $items = array_values(array_filter($state['refunds'], fn ($r) => $r['payment_id'] === $m[1]));

            return Http::response(['entity' => 'collection', 'count' => count($items), 'items' => $items]);
        }
        if ($request->method() === 'GET' && preg_match('#^/v1/payments/([^/]+)/refunds/([^/]+)$#', $path, $m)) {
            $refund = collect($state['refunds'])->firstWhere('id', $m[2]);

            return $refund === null ? Http::response(['error' => ['description' => 'not found']], 404) : Http::response($refund);
        }
        if ($request->method() === 'POST' && preg_match('#^/v1/payments/([^/]+)/refund$#', $path, $m)) {
            $refund = ['id' => 'rfnd_FAKE'.(count($state['refunds']) + 1), 'entity' => 'refund', 'amount' => $request['amount'], 'payment_id' => $m[1],
                'receipt' => $request['receipt'], 'notes' => $request['notes'], 'status' => 'processed'];
            $state['refunds'] = [...$state['refunds'], $refund];

            return Http::response($refund);
        }

        return Http::response(['error' => ['description' => 'unexpected request']], 400);
    });

    return $state;
}

/** A Razorpay payment entity captured on an order (fictional ids). @return array<string, mixed> */
function razorpayPayment(string $orderId, int $amount, string $currency, ?int $baseAmount = null, string $id = 'pay_FAKE000001', string $status = 'captured'): array
{
    return array_filter(['id' => $id, 'entity' => 'payment', 'amount' => $amount, 'currency' => $currency, 'status' => $status, 'order_id' => $orderId, 'method' => 'card',
        'international' => $currency !== 'INR', 'base_amount' => $baseAmount, 'base_currency' => $baseAmount === null ? null : 'INR'], fn ($v) => $v !== null);
}

/** A signed Razorpay webhook body and headers. @return array{0: string, 1: array<string, string>} */
function razorpayWebhook(string $eventId, string $event, array $payment, ?string $secret = null): array
{
    $body = json_encode(['entity' => 'event', 'account_id' => 'acc_FAKE', 'event' => $event, 'contains' => ['payment'], 'payload' => ['payment' => ['entity' => $payment]],
        'created_at' => now()->getTimestamp()]);

    return [$body, ['X-Razorpay-Signature' => hash_hmac('sha256', $body, $secret ?? (string) config('peopleos.billing.razorpay.webhook_secret')), 'X-Razorpay-Event-Id' => $eventId]];
}

/** Posts a raw provider webhook (signed sandbox headers unless given). */
function postWebhook($test, string $body, ?array $headers = null, string $provider = 'sandbox')
{
    $headers ??= SandboxProvider::signedHeaders($body);
    $server = ['CONTENT_TYPE' => 'application/json'];
    foreach ($headers as $name => $value) {
        $server['HTTP_'.strtoupper(str_replace('-', '_', $name))] = $value;
    }

    return $test->call('POST', "/webhooks/billing/{$provider}", [], [], [], $server, $body);
}

/** Overrides a shipped Markedge policy default for one test (an approved configuration version is the production path). */
function policyDefault(string $key, mixed $value): void
{
    config(['peopleos.commercial.policy_defaults' => array_merge((array) config('peopleos.commercial.policy_defaults', []), [$key => $value])]);
}
