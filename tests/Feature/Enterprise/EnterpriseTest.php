<?php

use App\Domain\Ai\Models\AiInteraction;
use App\Domain\Compliance\Models\CompanyStatutoryProfile;
use App\Domain\Enterprise\Models\SsoConnection;
use App\Domain\Enterprise\Models\WebhookDelivery;
use App\Domain\Enterprise\Models\WebhookEndpoint;
use App\Domain\Enterprise\Services\CountryPacks;
use App\Domain\Enterprise\Services\CurrencyRates;
use App\Domain\Enterprise\Services\Retention;
use App\Domain\Enterprise\Services\Scim;
use App\Domain\Enterprise\Services\SecurityPolicy;
use App\Domain\Enterprise\Services\Sso;
use App\Domain\Enterprise\Services\WarehouseExport;
use App\Domain\Enterprise\Services\Webhooks;
use App\Domain\Identity\Models\Role;
use App\Domain\Identity\Models\User;
use App\Domain\Integration\Services\ApiKeys;
use App\Domain\Leave\Models\LeaveType;
use App\Domain\Leave\Services\LeaveAccrual;
use App\Domain\Leave\Services\Leaves;
use App\Domain\Payroll\Models\PayrollPeriod;
use App\Domain\Payroll\Services\PayrollCalculator;
use App\Domain\Platform\Services\SettingsRepository;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

require_once __DIR__.'/../Workflow/WorkflowTestHelpers.php';
require_once __DIR__.'/../Performance/PerformanceTestHelpers.php';
require_once __DIR__.'/../Payroll/PayrollTestHelpers.php';
require_once __DIR__.'/../Leave/LeaveTestHelpers.php';

beforeEach(function () {
    $this->travelTo('2026-09-21 09:00:00');
    syncComplianceRules();
    $this->tenant = provisionTenant();
    actAsTenant($this->tenant);
    $this->hr = tenantUser($this->tenant, ['*']);
    $this->actingAs($this->hr);
    $this->settings = app(SettingsRepository::class);
});

it('converts currencies with dated and inverse rates and formats per country pack', function () {
    $rates = app(CurrencyRates::class);
    expect($rates->baseCurrency())->toBe('INR');
    $rates->set('USD', 'INR', 83.5, '2026-09-01');
    $rates->set('USD', 'INR', 84.0, '2026-09-20');
    expect($rates->rate('USD', 'INR', '2026-09-10'))->toBe(83.5)
        ->and($rates->convert(100, 'USD', 'INR'))->toBe(8400.0)
        ->and($rates->convert(8400, 'INR', 'USD'))->toBe(100.0) // inverse derived
        ->and($rates->toBase(10, 'USD', '2026-09-05'))->toBe(835.0);
    expect(fn () => $rates->rate('AED', 'INR'))->toThrow(RuntimeException::class, 'No exchange rate');
    expect(fn () => $rates->set('AED', 'INR', 0))->toThrow(RuntimeException::class, 'positive');

    $packs = app(CountryPacks::class);
    expect(array_keys($packs->all()))->toContain('IN', 'AE', 'GB', 'US', 'SG', 'AU', 'CA')
        ->and($packs->statutoryEngine('IN'))->toBe('india')->and($packs->statutoryEngine('AE'))->toBe('generic')->and($packs->statutoryEngine(null))->toBe('generic')
        ->and($packs->formatNumber(1234567.891, 'IN'))->toBe('12,34,567.89')
        ->and($packs->formatNumber(1234567.891, 'US'))->toBe('1,234,567.89')
        ->and($packs->formatNumber(-999.5, 'IN'))->toBe('-999.50')
        ->and($packs->formatDate(now(), 'US'))->toBe('09/21/2026')
        ->and($packs->get('GB')['labour']['minimum_leave_days'])->toBe(28);
});

it('runs the generic statutory engine for a non-India company', function () {
    $company = payrollCompany(['jurisdiction' => 'AE', 'pf_applicable' => true, 'tds_applicable' => false]);
    $company->update(['country_code' => 'AE', 'currency' => 'AED']);
    $employee = salariedEmployee(240000);
    $c = app(PayrollCalculator::class)->calculate($employee, PayrollPeriod::for($company, 2026, 9));

    expect($c->has('PF_EE'))->toBeFalse()->and($c->has('PT'))->toBeFalse()->and($c->has('TDS'))->toBeFalse()
        ->and($c->amount('SS_EE'))->toBe(round(20000 * 0.11)) // GPSSA on gross 20,000 (no employer PF in the CTC balance)
        ->and($c->amount('SS_ER'))->toBe(round(20000 * 0.15))
        ->and(collect($c->lines)->firstWhere('code', 'SS_EE')['basis']['jurisdiction'])->toBe('AE');

    CompanyStatutoryProfile::query()->where('company_id', $company->id)->update(['pf_applicable' => false]);
    $c = app(PayrollCalculator::class)->calculate($employee, PayrollPeriod::for($company, 2026, 9));
    expect($c->has('SS_EE'))->toBeFalse()->and($c->gross())->toBe(20000.0);
});

it('enforces the tenant security policy', function () {
    $policy = app(SecurityPolicy::class);
    expect($policy->ipAllowed('203.0.113.9'))->toBeTrue();
    $this->settings->set('security.ip_allowlist', "10.0.0.0/8, 192.168.1.*\n203.0.113.9");
    expect($policy->ipAllowed('10.20.30.40'))->toBeTrue()->and($policy->ipAllowed('192.168.1.77'))->toBeTrue()->and($policy->ipAllowed('203.0.113.9'))->toBeTrue()
        ->and($policy->ipAllowed('203.0.113.10'))->toBeFalse()->and($policy->ipAllowed('11.0.0.1'))->toBeFalse();
    expect($policy->passwordProblems('short'))->toHaveCount(3)->and($policy->passwordProblems('LongerPassword9'))->toBe([]);
    expect($policy->passwordExpired(null))->toBeFalse();
    $this->settings->set('security.password_expiry_days', 30);
    expect($policy->passwordExpired(now()->subDays(31)))->toBeTrue()->and($policy->passwordExpired(now()->subDays(5)))->toBeFalse();
    expect($policy->mfaRequired())->toBeFalse();
    $this->settings->set('security.mfa_required', true);
    expect($policy->mfaRequired())->toBeTrue();

    // The middleware blocks tenant users from disallowed networks but never platform admins.
    $employee = activeEmployee(null, ['task.view']);
    actAsTenant(null);
    $this->actingAs($employee->user);
    $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.10'])->get('/admin')->assertForbidden();
    $this->withServerVariables(['REMOTE_ADDR' => '10.1.1.1'])->get('/admin/my-day')->assertOk();
    $this->actingAs(platformAdmin());
    $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.10'])->get('/admin')->assertOk();
});

it('provisions users through SCIM with a scoped key', function () {
    actAsTenant($this->tenant);
    $issued = app(ApiKeys::class)->issue('Okta SCIM', ['scim']);
    $token = $issued['plaintext'];
    $employee = activeEmployee(null, ['task.view']);
    $employee->update(['user_id' => null, 'work_email' => 'scim.person@example.test']);
    actAsTenant(null);
    $headers = ['Authorization' => 'Bearer '.$token, 'Accept' => 'application/scim+json'];

    $this->getJson('/api/scim/v2/ServiceProviderConfig', $headers)->assertOk()->assertJsonPath('patch.supported', true);
    $this->getJson('/api/scim/v2/Users', ['Accept' => 'application/scim+json'])->assertStatus(401);

    $created = $this->postJson('/api/scim/v2/Users', ['schemas' => [Scim::SCHEMA_USER], 'userName' => 'scim.person@example.test', 'externalId' => 'okta-1', 'name' => ['givenName' => 'Scim', 'familyName' => 'Person'], 'active' => true], $headers)
        ->assertCreated()->assertJsonPath('userName', 'scim.person@example.test')->assertJsonPath('active', true);
    $id = $created->json('id');
    expect(User::query()->find($id)->name)->toBe('Scim Person')->and($employee->refresh()->user_id)->toBe((int) $id)->and($created->json('employeeNumber'))->toBe($employee->employee_code);

    $this->postJson('/api/scim/v2/Users', ['userName' => 'scim.person@example.test'], $headers)->assertStatus(409);
    $this->getJson('/api/scim/v2/Users?filter=userName eq "scim.person@example.test"', $headers)->assertOk()->assertJsonPath('totalResults', 1)->assertJsonPath('Resources.0.id', (string) $id);

    $this->patchJson("/api/scim/v2/Users/{$id}", ['schemas' => [Scim::SCHEMA_PATCH], 'Operations' => [['op' => 'replace', 'path' => 'active', 'value' => false], ['op' => 'replace', 'value' => ['displayName' => 'S. Person']]]], $headers)
        ->assertOk()->assertJsonPath('active', false)->assertJsonPath('displayName', 'S. Person');
    $this->putJson("/api/scim/v2/Users/{$id}", ['userName' => 'scim.person@example.test', 'name' => ['givenName' => 'Scimmy', 'familyName' => 'Person'], 'active' => true], $headers)->assertOk()->assertJsonPath('active', true)->assertJsonPath('name.givenName', 'Scimmy');
    $this->deleteJson("/api/scim/v2/Users/{$id}", [], $headers)->assertNoContent();
    expect(User::query()->find($id)->isActive())->toBeFalse();
    $this->getJson('/api/scim/v2/Users/999999', $headers)->assertNotFound();
});

it('signs in through an OIDC connection and provisions the user', function () {
    actAsTenant($this->tenant);
    $role = Role::factory()->create();
    $connection = SsoConnection::create(['name' => 'Entra', 'provider' => 'entra', 'slug' => 'acme-entra', 'client_id' => 'cid', 'client_secret' => 'secret', 'authorization_url' => 'https://login.example.test/authorize', 'token_url' => 'https://login.example.test/token', 'userinfo_url' => 'https://login.example.test/userinfo', 'allowed_domains' => ['example.test'], 'auto_provision' => true, 'default_role_id' => $role->id]);
    expect($connection->refresh()->client_secret)->toBe('secret'); // encrypted at rest, transparent in code
    actAsTenant(null);

    $redirect = $this->get('/sso/acme-entra/redirect');
    $redirect->assertRedirect();
    $location = $redirect->headers->get('Location');
    parse_str(parse_url($location, PHP_URL_QUERY), $query);
    expect($location)->toStartWith('https://login.example.test/authorize?')->and($query['client_id'])->toBe('cid')->and($query['state'])->not->toBeEmpty();

    Http::fake([
        'https://login.example.test/token' => Http::response(['access_token' => 'at-1', 'token_type' => 'Bearer']),
        'https://login.example.test/userinfo' => Http::response(['sub' => 'entra-42', 'email' => 'new.person@example.test', 'name' => 'New Person']),
    ]);
    $this->get('/sso/acme-entra/callback?code=abc&state='.$query['state'])->assertRedirect('/admin');
    $user = User::query()->where('email', 'new.person@example.test')->first();
    actAsTenant($this->tenant);
    expect($user)->not->toBeNull()->and($user->sso_subject)->toBe('entra-42')->and($user->roles()->pluck('roles.id')->all())->toBe([$role->id])->and($user->tenant_id)->toBe($this->tenant->id);
    actAsTenant(null);
    $this->assertAuthenticatedAs($user);

    // A replayed or forged state is refused.
    auth()->logout();
    $this->get('/sso/acme-entra/callback?code=abc&state=wrong')->assertRedirect('/admin/login')->assertSessionHasErrors('email');

    // A domain outside the allowlist is refused even with a valid identity.
    Http::fake(['https://login.example.test/token' => Http::response(['access_token' => 'at-2']), 'https://login.example.test/userinfo' => Http::response(['sub' => 'x', 'email' => 'someone@other.test', 'name' => 'Other'])]);
    expect(fn () => app(Sso::class)->resolveUser($connection, ['sub' => 'x', 'email' => 'someone@other.test', 'name' => 'Other']))->toThrow(RuntimeException::class, 'not allowed');
});

it('publishes signed webhooks for domain events, retries and records deliveries', function () {
    actAsTenant($this->tenant);
    $endpoint = WebhookEndpoint::create(['name' => 'ERP', 'url' => 'https://erp.example.test/hooks', 'secret' => 'whsec', 'events' => ['leave.requested', 'employee.notice_period']]);
    $other = WebhookEndpoint::create(['name' => 'All', 'url' => 'https://all.example.test/hooks', 'secret' => 's2', 'events' => ['*'], 'status' => 'paused']);
    $employee = activeEmployee(null, ['leave.apply', 'task.view']);
    $employee->update(['joining_date' => '2025-01-01']);
    leavePolicy(['EL' => ['days' => 24, 'accrual_frequency' => 'annual']]);
    app(LeaveAccrual::class)->accrue($employee);

    Http::fake(['https://erp.example.test/*' => Http::sequence()->push('', 500)->push('ok', 200)]);
    app(Leaves::class)->request($employee, LeaveType::query()->where('code', 'EL')->first(), '2026-09-28', '2026-09-28', 'Trip');
    $delivery = WebhookDelivery::query()->where('event', 'leave.requested')->first();
    expect($delivery)->not->toBeNull()->and($delivery->status)->toBe('pending')->and($delivery->webhook_endpoint_id)->toBe($endpoint->id)
        ->and(WebhookDelivery::query()->where('webhook_endpoint_id', $other->id)->count())->toBe(0) // paused endpoints get nothing
        ->and($delivery->payload['data']['employee_code'])->toBe($employee->employee_code);

    $webhooks = app(Webhooks::class);
    expect($webhooks->deliverDue())->toBe(['delivered' => 0, 'failed' => 0, 'retrying' => 1]);
    expect($delivery->refresh()->attempts)->toBe(1)->and($delivery->response_code)->toBe(500)->and($delivery->next_attempt_at->isFuture())->toBeTrue();
    expect($webhooks->deliverDue())->toBe(['delivered' => 0, 'failed' => 0, 'retrying' => 0]); // not due yet
    $this->travelTo(now()->addMinutes(3));
    expect($webhooks->deliverDue())->toBe(['delivered' => 1, 'failed' => 0, 'retrying' => 0]);
    expect($delivery->refresh()->status)->toBe('delivered')->and($endpoint->refresh()->last_delivered_at)->not->toBeNull();

    Http::assertSent(function ($request) {
        $body = $request->body();
        $ts = $request->header('X-PeopleOS-Timestamp')[0];

        return $request->hasHeader('X-PeopleOS-Event', 'leave.requested') && Webhooks::verify('whsec', $ts, $body, $request->header('X-PeopleOS-Signature')[0]);
    });

    Http::fake(['https://erp.example.test/*' => Http::response('', 503)]);
    $dead = WebhookDelivery::create(['webhook_endpoint_id' => $endpoint->id, 'event' => 'leave.requested', 'event_id' => 'x', 'payload' => ['event' => 'leave.requested'], 'status' => 'pending', 'attempts' => 4, 'next_attempt_at' => now()]);
    expect($webhooks->deliverDue()['failed'])->toBe(1)->and($dead->refresh()->status)->toBe('failed')->and($endpoint->refresh()->failure_count)->toBe(1);
});

it('purges by retention windows and exports the warehouse feed', function () {
    Storage::fake('local');
    actAsTenant($this->tenant);
    activeEmployee();
    AiInteraction::create(['user_id' => $this->hr->id, 'assistant' => 'employee', 'question' => 'old', 'answer' => 'x'])->forceFill(['created_at' => now()->subDays(400)])->save();
    AiInteraction::create(['user_id' => $this->hr->id, 'assistant' => 'employee', 'question' => 'new', 'answer' => 'x']);
    $purged = app(Retention::class)->purge();
    expect($purged['ai_interactions'])->toBe(1)->and(AiInteraction::query()->count())->toBe(1);

    $out = app(WarehouseExport::class)->run($this->hr, ['employees', 'assets']);
    expect($out)->toBe(['employees' => 1, 'assets' => 0]);
    $folder = 'warehouse/'.$this->tenant->slug.'/'.now()->format('Y-m-d');
    Storage::disk('local')->assertExists("{$folder}/employees.jsonl");
    Storage::disk('local')->assertExists("{$folder}/manifest.json");
    $line = json_decode(trim(Storage::disk('local')->get("{$folder}/employees.jsonl")), true);
    expect($line)->toHaveKeys(['employee_code', 'headcount', '_exported_at']);
});
