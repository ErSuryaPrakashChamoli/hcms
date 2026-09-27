<?php

use App\Domain\Compliance\Models\ComplianceRule;
use App\Domain\Compliance\Models\EpfReturnEntry;
use App\Domain\Compliance\Models\StatutoryRegistration;
use App\Domain\Compliance\Models\StatutoryReturn;
use App\Domain\Compliance\Models\StatutorySnapshot;
use App\Domain\Compliance\Services\Returns\EpfReturns;
use App\Domain\Compliance\Services\Returns\StatutoryReturns;
use App\Domain\Identity\Services\AccessScopes;
use App\Domain\Integration\Services\ApiKeys;
use App\Domain\Organisation\Models\Company;
use App\Domain\Organisation\Models\Establishment;
use App\Domain\Organisation\Models\LegalEntity;
use App\Filament\Pages\ComplianceControlRoom;
use Illuminate\Support\Facades\Storage;

require_once __DIR__.'/ComplianceTestHelpers.php';

/* Phase 5 Part U: statutory data stays inside tenant, company and role boundaries. */

beforeEach(function () {
    Storage::fake('local');
    $this->travelTo('2026-10-05 09:00:00');
    syncComplianceRules();
    $this->tenant = provisionTenant();
    actAsTenant($this->tenant);
    $this->admin = tenantUser($this->tenant, ['*']);
    $this->actingAs($this->admin);
    ['company' => $this->company, 'entity' => $this->entity, 'establishment' => $this->establishment] = complianceCompany();
    ['generator' => $this->generator, 'approver' => $this->approver] = complianceUsers($this->tenant);
    $this->employee = statutoryEmployee(600000, $this->establishment, '100200300400');
    finalizedPayroll($this->company, 2026, 9, $this->admin, tenantUser($this->tenant, ['payroll.*', 'employee.*']));
    $this->return = app(EpfReturns::class)->generate($this->establishment, 2026, 9, $this->generator);
    $this->manager = employeeWithUser();

    $this->tenantB = provisionTenant('Beta');
});

it('keeps legal entities, establishments, registrations and returns inside the user\'s company scope', function () {
    $other = Company::factory()->create();
    $scoped = tenantUser($this->tenant, ['legal_entity.view', 'establishment.view', 'compliance.registrations.view', 'compliance.returns.view']);
    app(AccessScopes::class)->assign($scoped, ['company' => [$other->id]]);
    $this->actingAs($scoped);

    expect(LegalEntity::query()->pluck('company_id')->unique()->all())->toBe([$other->id])
        ->and(Establishment::query()->where('company_id', $this->company->id)->exists())->toBeFalse()
        ->and(StatutoryRegistration::query()->count())->toBe(0)
        ->and(StatutoryReturn::query()->count())->toBe(0)
        ->and($scoped->can('view', StatutoryRegistration::query()->withoutGlobalScopes()->first()))->toBeFalse()
        ->and($scoped->can('view', $this->return))->toBeFalse();
});

it('gives managers, employees and payroll-only users no statutory output, even through a reporting line', function () {
    $payrollOnly = tenantUser($this->tenant, ['payroll.*']);
    $employeeUser = employeeWithUser($this->manager)->user;

    foreach ([$this->manager->user, $employeeUser, $payrollOnly] as $user) {
        $this->actingAs($user);
        expect($user->can('viewAny', StatutoryReturn::class))->toBeFalse()
            ->and($user->can('view', $this->return))->toBeFalse()
            ->and($user->can('viewAny', EpfReturnEntry::class))->toBeFalse()
            ->and($user->can('viewAny', StatutorySnapshot::class))->toBeFalse()
            ->and(ComplianceControlRoom::canAccess())->toBeFalse();
        expect(fn () => app(StatutoryReturns::class)->validate($this->return, $user))->toThrow(RuntimeException::class, 'permission');
    }

    // A read-only compliance viewer can look but cannot generate, approve or file.
    $viewer = tenantUser($this->tenant, ['compliance.returns.view']);
    expect($viewer->can('view', $this->return))->toBeTrue();
    expect(fn () => app(EpfReturns::class)->generate($this->establishment, 2026, 9, $viewer))->toThrow(RuntimeException::class, 'permission');
    expect(fn () => app(StatutoryReturns::class)->approve($this->return, $viewer))->toThrow(RuntimeException::class, 'permission');
});

it('never lets tenants edit rules or approved returns', function () {
    expect($this->admin->can('update', ComplianceRule::query()->first()))->toBeFalse()
        ->and($this->admin->can('delete', ComplianceRule::query()->first()))->toBeFalse()
        ->and($this->admin->can('update', $this->return))->toBeFalse()
        ->and($this->admin->can('delete', $this->return))->toBeFalse();

    $approved = app(StatutoryReturns::class)->approve(app(StatutoryReturns::class)->validate($this->return, $this->generator), $this->approver);
    expect(fn () => $approved->delete())->toThrow(RuntimeException::class, 'never deleted')
        ->and(fn () => $approved->fresh()->update(['rule_versions' => []]))->toThrow(RuntimeException::class, 'immutable');
});

it('serves the read-only compliance API inside the key tenant, masked, audited and without IDOR', function () {
    $key = app(ApiKeys::class)->issue('compliance', ['compliance.read'])['plaintext'];
    $payrollKey = app(ApiKeys::class)->issue('payroll', ['payroll.read'])['plaintext'];
    actAsTenant($this->tenantB);
    $keyB = app(ApiKeys::class)->issue('B', ['compliance.read'])['plaintext'];
    actAsTenant(null);
    auth()->logout();

    $this->withHeader('X-Api-Key', $key)->getJson('/api/v1/compliance/establishments')->assertOk()->assertJsonPath('data.0.state', 'KA');
    $registrations = $this->withHeader('X-Api-Key', $key)->getJson('/api/v1/compliance/registrations')->assertOk();
    expect($registrations->json('data.*.number'))->each->toStartWith('••••')
        ->and($registrations->getContent())->not->toContain('KABNG0012345000')->not->toContain('BLRA12345B');

    $rules = $this->withHeader('X-Api-Key', $key)->getJson('/api/v1/compliance/rules?code=EPF')->assertOk()->assertJsonPath('data.0.verification_status', 'draft');
    $this->withHeader('X-Api-Key', $key)->getJson('/api/v1/compliance/rules/'.$rules->json('data.0.id'))->assertOk()->assertJsonPath('data.payload.wage_ceiling', 15000);

    $this->withHeader('X-Api-Key', $key)->getJson('/api/v1/compliance/returns?type=epf')->assertOk()->assertJsonPath('data.0.form', 'ECR')->assertJsonPath('data.0.status', 'calculated');
    $this->withHeader('X-Api-Key', $key)->getJson("/api/v1/compliance/returns/{$this->return->id}")->assertOk()->assertJsonPath('data.readiness.ready', false);
    $entries = $this->withHeader('X-Api-Key', $key)->getJson("/api/v1/compliance/returns/{$this->return->id}/entries")->assertOk();
    expect($entries->json('data.0.uan'))->toBe('••••••••0400')
        ->and($entries->getContent())->not->toContain('100200300400');
    $this->withHeader('X-Api-Key', $key)->getJson("/api/v1/compliance/reconciliation/{$this->return->id}")->assertOk();

    actAsTenant($this->tenant);
    expect($this->return->actions()->where('action', 'accessed')->where('source', 'api')->exists())->toBeTrue();
    actAsTenant(null);

    // Another tenant's key, a key without the scope, and write verbs all fail.
    $this->flushHeaders()->withHeader('X-Api-Key', $keyB)->getJson("/api/v1/compliance/returns/{$this->return->id}")->assertNotFound();
    $this->flushHeaders()->withHeader('X-Api-Key', $keyB)->getJson("/api/v1/compliance/returns/{$this->return->id}/entries")->assertNotFound();
    expect($this->flushHeaders()->withHeader('X-Api-Key', $keyB)->getJson('/api/v1/compliance/registrations')->json('meta.total'))->toBe(0);
    $this->flushHeaders()->withHeader('X-Api-Key', $payrollKey)->getJson('/api/v1/compliance/returns')->assertForbidden();
    $this->flushHeaders()->withHeader('X-Api-Key', $key)->postJson("/api/v1/compliance/returns/{$this->return->id}", [])->assertStatus(405);
});
