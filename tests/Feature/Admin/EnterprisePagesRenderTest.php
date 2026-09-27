<?php

use App\Domain\Enterprise\Models\SsoConnection;
use App\Domain\Enterprise\Models\WebhookEndpoint;
use App\Domain\Platform\Services\SettingsRepository;
use App\Filament\Pages\CountryPacksPage;
use App\Filament\Pages\SecurityPolicyPage;
use App\Filament\Resources\ExchangeRates\ExchangeRateResource;
use App\Filament\Resources\SsoConnections\SsoConnectionResource;
use App\Filament\Resources\WebhookEndpoints\WebhookEndpointResource;
use Livewire\Livewire;

require_once __DIR__.'/../Workflow/WorkflowTestHelpers.php';
require_once __DIR__.'/../Performance/PerformanceTestHelpers.php';

beforeEach(function () {
    $this->travelTo('2026-09-21 09:00:00');
    $this->tenant = provisionTenant();
    actAsTenant($this->tenant);
    $this->admin = tenantUser($this->tenant, ['*']);
    $this->actingAs($this->admin);
    $this->sso = SsoConnection::create(['name' => 'Google', 'provider' => 'google', 'client_id' => 'x', 'client_secret' => 'y', 'authorization_url' => 'https://a', 'token_url' => 'https://t', 'userinfo_url' => 'https://u']);
    $this->hook = WebhookEndpoint::create(['name' => 'ERP', 'url' => 'https://erp.example.test', 'secret' => 's', 'events' => ['payroll.finalized']]);
    $this->employee = activeEmployee(null, ['task.view']);
    actAsTenant(null);
});

it('renders the enterprise pages and saves the security policy', function () {
    $this->get(SsoConnectionResource::getUrl('index'))->assertOk()->assertSee('Google')->assertSee('/sso/google/redirect');
    $this->get(SsoConnectionResource::getUrl('edit', ['record' => $this->sso]))->assertOk();
    $this->get(WebhookEndpointResource::getUrl('index'))->assertOk()->assertSee('ERP');
    $this->get(WebhookEndpointResource::getUrl('edit', ['record' => $this->hook]))->assertOk();
    $this->get(ExchangeRateResource::getUrl('index'))->assertOk()->assertSee('Base currency: INR');
    $this->get(SecurityPolicyPage::getUrl())->assertOk()->assertSee('IP allowlist');
    $this->get(CountryPacksPage::getUrl())->assertOk()->assertSee('United Arab Emirates');

    actAsTenant($this->tenant);
    Livewire::test(SecurityPolicyPage::class)->fillForm(['security__session_idle_minutes' => 45, 'security__mfa_required' => true, 'retention__report_runs_days' => 30])->call('save')->assertNotified('Security policy saved');
    expect((int) app(SettingsRepository::class)->get('security.session_idle_minutes'))->toBe(45)->and((bool) app(SettingsRepository::class)->get('security.mfa_required'))->toBeTrue();
    actAsTenant(null);

    $this->actingAs($this->employee->user);
    $this->get(SsoConnectionResource::getUrl('index'))->assertForbidden();
    $this->get(SecurityPolicyPage::getUrl())->assertForbidden();
});
