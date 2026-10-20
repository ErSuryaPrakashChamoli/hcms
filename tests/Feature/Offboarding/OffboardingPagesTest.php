<?php

use App\Domain\Offboarding\Models\RetentionPolicy;
use App\Domain\Offboarding\Models\TenantDeletion;
use App\Domain\Offboarding\Models\TenantExport;
use App\Domain\Offboarding\Models\TenantRetentionTerm;
use App\Domain\Offboarding\Services\TenantExports;
use App\Domain\Platform\Enums\TenantStatus;
use App\Filament\Pages\DataExportPage;
use App\Filament\Pages\PlatformOffboardingPage;
use App\Filament\Pages\PlatformRetentionPoliciesPage;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

require_once __DIR__.'/OffboardingTestHelpers.php';
require_once __DIR__.'/../Workflow/WorkflowTestHelpers.php';

/*
| SaaS.9: the operators' pages (offboarding, retention policies) and the customer's own export page. Platform controls
| never reach a tenant user; a tenant user sees and downloads only their own organisation's exports.
*/

beforeEach(function () {
    Storage::fake('local');
    config(['peopleos.offboarding.deletion_enabled' => true]);
    [$this->maker, $this->checker] = exitOperators();
    $this->tenant = provisionTenant('Page Co');
    $this->other = provisionTenant('Neighbour Co');
    app(TenantContext::class)->runAs($this->tenant, function () {
        employeeWithUser();
        $this->owner = tenantUser($this->tenant, ['data.export'], ['email' => 'owner@page.test']);
        $this->hr = tenantUser($this->tenant, ['employee.view']);
    });
    app(TenantContext::class)->runAs($this->other, fn () => $this->neighbour = tenantUser($this->other, ['data.export'], ['email' => 'owner@neighbour.test']));
});

it('keeps the operators\' pages from every tenant user, and the export page from those without data.export', function () {
    foreach ([PlatformOffboardingPage::getUrl(), PlatformRetentionPoliciesPage::getUrl()] as $url) {
        $this->actingAs($this->owner)->get($url)->assertForbidden();
        $this->actingAs($this->maker)->get($url)->assertOk();
    }
    $this->actingAs($this->owner)->get(DataExportPage::getUrl())->assertOk();
    $this->actingAs($this->hr)->get(DataExportPage::getUrl())->assertForbidden();
    $this->actingAs($this->maker)->get(DataExportPage::getUrl())->assertForbidden();
});

it('runs an exit from the page: start, close, hold, plan; the planner cannot approve, the second operator can', function () {
    retentionFor(null, 0, $this->maker, $this->checker);
    $this->actingAs($this->maker);
    $page = Livewire::test(PlatformOffboardingPage::class)->set('tenant', $this->tenant->id)
        ->assertSee('No offboarding')
        ->callAction('requestOffboarding', data: ['reason' => 'Customer gave notice', 'customer_reference' => 'NOTICE-9'])->assertNotified('Offboarding started')
        ->callAction('closeTenant', data: ['reason' => 'End of service'])->assertNotified('Tenant closed')
        ->callAction('waiveExport', data: ['reason' => 'Customer took their copy'])->assertNotified('Export waived')
        ->callAction('placeHold', data: ['categories' => ['people'], 'reason' => 'Open grievance matter', 'matter_reference' => 'GRV-1'])->assertNotified('Legal hold placed')
        ->assertSee('LEGAL_HOLD')->assertSee('ELIGIBLE')
        ->callAction('planDeletion', data: ['reason' => 'Retention ended'])->assertNotified('Deletion planned: a second operator approves it');
    expect($this->tenant->fresh()->status)->toBe(TenantStatus::Closed);
    $deletion = TenantDeletion::query()->withoutTenancy()->where('tenant_id', $this->tenant->id)->sole();

    // The maker is never offered approval; the second operator is.
    $page->assertDontSee('Approve deletion');
    $this->actingAs($this->checker);
    Livewire::test(PlatformOffboardingPage::class)->set('tenant', $this->tenant->id)
        ->assertSee('Approve deletion')
        ->callAction('deletionApprove', data: ['reason' => 'Plan reviewed'], arguments: ['deletion' => $deletion->id])
        ->assertNotified('Deletion approved and scheduled');
    expect($deletion->fresh()->status->value)->toBe('approved');
});

it('proposes and approves retention policies from the page, with the legal minimum shown when not configured', function () {
    $this->actingAs($this->maker);
    Livewire::test(PlatformRetentionPoliciesPage::class)->assertSee('Not configured')
        ->callAction('propose', data: ['category' => 'leave', 'jurisdiction' => '*', 'policy_months' => 24, 'effective_from' => today()->toDateString(), 'reason' => 'Leave records two years'])
        ->assertNotified('Proposed: another operator approves it')
        ->assertSee('LEGAL_MINIMUM_NOT_CONFIGURED')->assertDontSee('>Approve<', false);
    $policy = RetentionPolicy::query()->sole();
    $this->actingAs($this->checker);
    Livewire::test(PlatformRetentionPoliciesPage::class)->callAction('approve', data: ['reason' => 'Agreed'], arguments: ['policy' => $policy->id])->assertNotified('Approved');
    expect($policy->fresh()->status->value)->toBe('approved');
});

it('shows a tenant user only their own organisation\'s exports, and never another\'s by id', function () {
    Queue::fake();
    $mine = app(TenantExports::class)->request($this->tenant, $this->owner, 'Our copy please');
    $theirs = app(TenantExports::class)->request($this->other, $this->neighbour, 'Their own copy');
    app(TenantExports::class)->generate($mine->id);
    app(TenantExports::class)->generate($theirs->id);
    $this->actingAs($this->owner);
    actAsTenant($this->tenant); // as the panel's tenant middleware would

    expect(Livewire::test(DataExportPage::class)->instance()->exports()->pluck('id')->all())->toBe([$mine->id]);
    Livewire::test(DataExportPage::class)->assertSee($mine->created_at->format('Y-m-d H:i'))
        ->assertDontSee($theirs->reference)
        ->callAction('passphrase', arguments: ['export' => $theirs->id])
        ->assertNotified('Export not found.')->assertSet('revealed', null);
    Livewire::test(DataExportPage::class)->callAction('passphrase', arguments: ['export' => $mine->id])
        ->assertSet('revealed', (string) app(TenantExports::class)->find($mine->id)->passphrase);
    expect(TenantExport::query()->count())->toBe(1); // the tenant scope: only this organisation's
});

it('offers no API route into offboarding, export, holds or deletion: an API key cannot reach any of it', function () {
    $routes = collect(Route::getRoutes()->getRoutes())
        ->filter(fn ($route) => str_starts_with($route->uri(), 'api/') && preg_match('/offboard|export|deletion|legal|retention|hold/i', $route->uri().' '.$route->getActionName()))
        ->map(fn ($route) => $route->uri())->values()->all();
    expect($routes)->toBe([]);
    // The one web route (the package download) needs a signed link, a session and the person it was made for.
    $download = Route::getRoutes()->getByName('offboarding.exports.download');
    expect($download->gatherMiddleware())->toContain('signed')->toContain('auth');
});

it('records a customer contract term and a verified legal minimum from the pages, each approved by the second operator', function () {
    $this->actingAs($this->maker);
    Livewire::test(PlatformOffboardingPage::class)->set('tenant', $this->tenant->id)
        ->assertSee('No contractual retention term recorded')
        ->callAction('proposeRetentionTerm', data: ['category' => '*', 'minimum_months' => 12, 'contract_reference' => 'MSA 2026 cl. 14 (fictional)',
            'effective_from' => today()->toDateString(), 'reason' => 'Customer keeps a year'])
        ->assertNotified('Contract term proposed: another operator approves it')
        ->assertSee('MSA 2026 cl. 14 (fictional)')->assertDontSee('>Approve<', false);
    $term = TenantRetentionTerm::query()->withoutTenancy()->sole();
    $this->actingAs($this->checker);
    Livewire::test(PlatformOffboardingPage::class)->set('tenant', $this->tenant->id)
        ->callAction('termApprove', data: ['reason' => 'Contract checked'], arguments: ['term' => $term->id])->assertNotified('Contract term approved');
    expect($term->fresh()->status->value)->toBe('approved');

    $this->actingAs($this->maker);
    Livewire::test(PlatformRetentionPoliciesPage::class)
        ->callAction('propose', data: ['category' => 'payroll', 'jurisdiction' => 'IN', 'legal_minimum_months' => 36, 'legal_basis' => 'Fictional statute (test)',
            'legal_reference' => 'TEST-ACT s.9', 'legal_source' => 'https://example.test/official-text', 'legal_verified_by' => 'Counsel (fictional)',
            'legal_verified_on' => today()->toDateString(), 'policy_months' => 48, 'start_event' => 'financial_year_end_after_closure', 'financial_year_end' => '03-31',
            'effective_from' => today()->toDateString(), 'reason' => 'Payroll kept four years'])
        ->assertNotified('Proposed: another operator approves it')
        ->assertSee('verified by Counsel (fictional)')->assertSee('from the financial year end (03-31) after closure');
    expect(RetentionPolicy::query()->sole()->legal_minimum_status)->toBe('verified');
});
