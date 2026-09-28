<?php

use App\Domain\Compliance\Models\ComplianceRule;
use App\Domain\Compliance\Models\EpfReturnEntry;
use App\Domain\Compliance\Models\StatutoryExportLayout;
use App\Domain\Compliance\Models\StatutoryRegistration;
use App\Domain\Compliance\Models\StatutoryReturn;
use App\Domain\Compliance\Services\ExportLayouts;
use App\Domain\Compliance\Services\Returns\EpfReturns;
use App\Domain\Compliance\Services\Returns\StatutoryReturns;
use App\Domain\Compliance\Services\RuleVerifications;
use App\Domain\Compliance\Services\StatutoryRegistrations;
use App\Domain\Identity\Services\AccessScopes;
use App\Domain\Integration\Services\ApiKeys;
use App\Domain\Organisation\Models\Establishment;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\Storage;

require_once __DIR__.'/ComplianceTestHelpers.php';

/* Phase 6 §30: establishment isolation, maker-checker bypass attempts, filing authority, IDOR. */

beforeEach(function () {
    Storage::fake('local');
    $this->travelTo('2026-10-05 09:00:00');
    syncComplianceRules();
    $this->tenant = provisionTenant();
    actAsTenant($this->tenant);
    $this->actingAs(tenantUser($this->tenant, ['*']));
    ['company' => $this->company, 'entity' => $this->entity, 'establishment' => $this->blr] = complianceCompany();
    $this->pune = Establishment::query()->create(['legal_entity_id' => $this->entity->id, 'code' => 'PUNE', 'name' => 'Pune', 'state' => 'MH', 'effective_from' => '2020-01-01']);
    app(StatutoryRegistrations::class)->register(['legal_entity_id' => $this->entity->id, 'establishment_id' => $this->pune->id, 'registration_type' => 'epf_establishment_code', 'registration_number' => 'MHPUN0099999000', 'effective_from' => '2020-01-01'], 'Pune');
    $this->blrEmployee = statutoryEmployee(600000, $this->blr, '100200300400');
    $this->puneEmployee = statutoryEmployee(600000, $this->pune, '100200300401');
    finalizedPayroll($this->company, 2026, 9, tenantUser($this->tenant, ['payroll.*', 'employee.*']), tenantUser($this->tenant, ['payroll.*', 'employee.*']));
    ['generator' => $this->generator, 'approver' => $this->approver, 'filer' => $this->filer] = complianceUsers($this->tenant);
    $this->blrReturn = app(EpfReturns::class)->generate($this->blr, 2026, 9, $this->generator);
    $this->puneReturn = app(EpfReturns::class)->generate($this->pune, 2026, 9, $this->generator);
});

it('limits an establishment-scoped user to that establishment\'s statutory data', function () {
    $scoped = tenantUser($this->tenant, ['compliance.returns.view', 'compliance.registrations.view', 'establishment.view', 'legal_entity.view']);
    app(AccessScopes::class)->assign($scoped, ['establishment' => [$this->pune->id]]);
    $this->actingAs($scoped);

    expect(StatutoryReturn::query()->pluck('id')->all())->toBe([$this->puneReturn->id])
        ->and(StatutoryRegistration::query()->pluck('establishment_id')->unique()->values()->all())->toBe([$this->pune->id])
        ->and(Establishment::query()->pluck('id')->all())->toBe([$this->pune->id])
        ->and(EpfReturnEntry::query()->pluck('employee_id')->unique()->values()->all())->toBe([$this->puneEmployee->id])
        ->and($scoped->can('view', $this->blrReturn))->toBeFalse()
        ->and($scoped->can('view', $this->puneReturn))->toBeTrue()
        ->and($scoped->can('view', $this->blr))->toBeFalse();
});

it('does not let anyone bypass maker-checker for rules, layouts or returns', function () {
    $tenantAdmin = tenantUser($this->tenant, ['*']);
    $rule = ComplianceRule::query()->where('code', 'PT')->where('state', 'KA')->sole();
    $layout = StatutoryExportLayout::query()->where('code', 'EPF_ECR')->sole();
    $platform = platformAdmin();

    // Tenant administrators never verify platform rules or layouts.
    app(TenantContext::class)->bypass(fn () => app(RuleVerifications::class)->submit($rule, ['source_url' => 'https://www.example.gov.in/x', 'source_title' => 'x', 'effective_date' => '2023-04-01', 'requirement_text' => 'x', 'mapping' => array_fill_keys(array_keys($rule->payload()), 'clause')], $platform));
    expect(fn () => app(RuleVerifications::class)->verify($rule->refresh(), $tenantAdmin, 'x'))->toThrow(RuntimeException::class, 'platform administrators');
    expect(fn () => app(ExportLayouts::class)->verify($layout, $tenantAdmin, 'x'))->toThrow(RuntimeException::class, 'platform administrators');

    // Generator cannot approve; approver cannot file; only the filing permission records filing.
    $returns = app(StatutoryReturns::class);
    $all = tenantUser($this->tenant, ['compliance.returns.view', 'compliance.returns.generate', 'compliance.returns.approve', 'compliance.returns.export', 'compliance.returns.file']);
    $own = $returns->validate(app(EpfReturns::class)->generate($this->blr, 2026, 9, $all), $all);
    expect(fn () => $returns->approve($own, $all))->toThrow(RuntimeException::class, 'Separation of duties');
    $approved = $returns->export($returns->approve($own, $this->approver), $all);
    expect(fn () => $returns->recordSubmission($approved, $all, 'TRRN-1', now()))->toThrow(RuntimeException::class, 'Separation of duties');
    expect(fn () => $returns->recordSubmission($approved, $this->generator, 'TRRN-1', now()))->toThrow(RuntimeException::class, 'compliance.returns.file');
    expect($returns->recordSubmission($approved, $this->filer, 'TRRN-1', now()->subMinute())->status)->toBe('submitted');
});

it('keeps compliance API reads inside the key tenant for rules, returns and entries (IDOR)', function () {
    $key = app(ApiKeys::class)->issue('compliance', ['compliance.read'])['plaintext'];
    $tenantB = provisionTenant('Beta');
    actAsTenant($tenantB);
    $keyB = app(ApiKeys::class)->issue('B', ['compliance.read'])['plaintext'];
    actAsTenant(null);
    auth()->logout();

    foreach (["/api/v1/compliance/returns/{$this->puneReturn->id}", "/api/v1/compliance/returns/{$this->puneReturn->id}/entries", "/api/v1/compliance/reconciliation/{$this->puneReturn->id}"] as $url) {
        $this->flushHeaders()->withHeader('X-Api-Key', $keyB)->getJson($url)->assertNotFound();
    }
    $detail = $this->flushHeaders()->withHeader('X-Api-Key', $key)->getJson("/api/v1/compliance/returns/{$this->puneReturn->id}")->assertOk();
    expect(collect($detail->json('data.readiness.checks'))->pluck('check'))->toContain('establishment_verified', 'export_layout_verified')
        ->and($detail->getContent())->not->toContain('MHPUN0099999000')->not->toContain('100200300401');
    $this->flushHeaders()->withHeader('X-Api-Key', $keyB)->getJson('/api/v1/compliance/rules/'.ComplianceRule::query()->value('id'))->assertOk(); // platform rules are shared, not tenant data
});
