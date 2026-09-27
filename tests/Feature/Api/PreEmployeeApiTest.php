<?php

use App\Domain\Audit\Models\AuditEvent;
use App\Domain\Employment\Models\Employee;
use App\Domain\Integration\Services\ApiKeys;
use App\Domain\Lifecycle\Enums\LifecycleState;
use App\Domain\Organisation\Models\Company;
use App\Domain\Organisation\Models\Department;
use App\Domain\Organisation\Models\Designation;
use App\Support\Tenancy\TenantContext;

beforeEach(function () {
    $this->tenant = provisionTenant();
    actAsTenant($this->tenant);
    $this->actingAs(tenantUser($this->tenant, ['*']));
    $this->company = Company::factory()->create(['code' => 'ACME']);
    Department::factory()->create(['code' => 'ENG']);
    Designation::factory()->create(['code' => 'SE']);
    $this->manager = Employee::factory()->create(['employee_code' => 'EMP00001']);
    $this->key = app(ApiKeys::class)->issue('RMS', ['rms.write', 'rms.read']);
    auth()->logout();
    actAsTenant(null);

    $this->payload = [
        'person' => ['first_name' => 'Kiran', 'last_name' => 'Rao', 'personal_email' => 'kiran@example.test'],
        'offer' => ['external_reference' => 'OFFER-42', 'expected_joining_date' => now()->addDays(10)->toDateString(), 'accepted_at' => now()->toDateString()],
        'position' => ['company_code' => 'ACME', 'department_code' => 'ENG', 'designation_code' => 'SE'],
        'manager_code' => 'EMP00001',
        'work_email' => 'kiran@acme.test',
    ];
});

it('rejects missing, invalid and under-scoped keys', function () {
    $this->postJson('/api/v1/pre-employees', $this->payload)->assertStatus(401);
    $this->withHeader('X-Api-Key', 'pk_nope.secret')->postJson('/api/v1/pre-employees', $this->payload)->assertStatus(401);

    $readOnly = app(TenantContext::class)->runAs($this->tenant, fn () => app(ApiKeys::class)->issue('Read only', ['rms.read']));
    $this->withHeader('X-Api-Key', $readOnly['plaintext'])->postJson('/api/v1/pre-employees', $this->payload)->assertStatus(403);
});

it('creates a pre-employee in preboarding from an accepted offer, idempotently', function () {
    $response = $this->withHeader('X-Api-Key', $this->key['plaintext'])->postJson('/api/v1/pre-employees', $this->payload);

    $response->assertCreated()->assertJsonPath('data.lifecycle_state', 'preboarding')->assertJsonPath('data.external_reference', 'OFFER-42');

    actAsTenant($this->tenant);
    $employee = Employee::query()->where('external_reference', 'OFFER-42')->first();

    expect($employee->source)->toBe('rms')
        ->and($employee->lifecycle_state)->toBe(LifecycleState::Preboarding)
        ->and($employee->expected_joining_date->toDateString())->toBe(now()->addDays(10)->toDateString())
        ->and($employee->positions()->first()->company_id)->toBe($this->company->id)
        ->and($employee->positions()->first()->designation->code)->toBe('SE')
        ->and($employee->positions()->first()->effective_from->toDateString())->toBe(now()->addDays(10)->toDateString())
        ->and($employee->reportingRelationships()->first()->manager_id)->toBe($this->manager->id)
        ->and($employee->person->first_name)->toBe('Kiran')
        ->and(AuditEvent::query()->where('entity_type', Employee::class)->where('entity_id', (string) $employee->id)->where('action', 'CREATE')->value('source'))->toBe('api:RMS')
        ->and($this->key['key']->fresh()->last_used_at)->not->toBeNull();

    actAsTenant(null);
    $this->withHeader('X-Api-Key', $this->key['plaintext'])->postJson('/api/v1/pre-employees', $this->payload)->assertOk()->assertJsonPath('data.id', $employee->id);

    actAsTenant($this->tenant);
    expect(Employee::query()->where('external_reference', 'OFFER-42')->count())->toBe(1);
});

it('validates payloads and resolves codes', function () {
    $this->withHeader('X-Api-Key', $this->key['plaintext'])->postJson('/api/v1/pre-employees', ['person' => []])->assertStatus(422)->assertJsonValidationErrors(['person.first_name', 'offer.external_reference', 'position.company_code']);

    $bad = $this->payload;
    $bad['position']['company_code'] = 'NOPE';
    $this->withHeader('X-Api-Key', $this->key['plaintext'])->postJson('/api/v1/pre-employees', $bad)->assertStatus(422)->assertJsonPath('message', 'Unknown company_code [NOPE].');
});

it('reads status by reference and keeps tenants apart', function () {
    $this->withHeader('X-Api-Key', $this->key['plaintext'])->postJson('/api/v1/pre-employees', $this->payload)->assertCreated();
    $this->withHeader('X-Api-Key', $this->key['plaintext'])->getJson('/api/v1/pre-employees/OFFER-42')->assertOk()->assertJsonPath('data.name', 'Kiran Rao');

    $other = provisionTenant('Other');
    $otherKey = app(TenantContext::class)->runAs($other, fn () => app(ApiKeys::class)->issue('Other RMS', ['rms.read']));
    actAsTenant(null);

    $this->withHeader('X-Api-Key', $otherKey['plaintext'])->getJson('/api/v1/pre-employees/OFFER-42')->assertNotFound();
});

it('revokes and expires keys', function () {
    actAsTenant($this->tenant);
    app(ApiKeys::class)->revoke($this->key['key'], 'Rotated');
    actAsTenant(null);
    $this->withHeader('X-Api-Key', $this->key['plaintext'])->getJson('/api/v1/pre-employees/x')->assertStatus(401);

    $expired = app(TenantContext::class)->runAs($this->tenant, fn () => app(ApiKeys::class)->issue('Expired', ['rms.read'], now()->subDay()));
    $this->withHeader('X-Api-Key', $expired['plaintext'])->getJson('/api/v1/pre-employees/x')->assertStatus(401);
});
