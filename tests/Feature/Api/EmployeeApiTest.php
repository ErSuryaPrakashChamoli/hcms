<?php

use App\Domain\Audit\Models\AuditEvent;
use App\Domain\Employment\Actions\HireEmployeeAction;
use App\Domain\Employment\Events\EmploymentEvent;
use App\Domain\Employment\Models\Employee;
use App\Domain\Employment\Models\EmployeeBankAccount;
use App\Domain\Integration\Services\ApiKeys;
use App\Domain\Organisation\Models\Company;
use App\Domain\Organisation\Models\Department;
use App\Domain\Organisation\Models\Location;
use Illuminate\Support\Facades\Event;

/* Phase 1 §42–§44, §61: the API uses the same actions as the UI, binds the tenant from the key, and never leaks sensitive fields. */

beforeEach(function () {
    $this->travelTo('2026-09-21 09:00:00');
    $this->tenant = provisionTenant();
    $this->tenantB = provisionTenant('B');
    actAsTenant($this->tenant);
    $this->hr = tenantUser($this->tenant, ['*']);
    $this->actingAs($this->hr);
    $this->company = Company::factory()->create(['code' => 'ALPHA']);
    Location::factory()->create(['company_id' => $this->company->id, 'code' => 'DEL', 'name' => 'Delhi']);
    Department::factory()->create(['company_id' => $this->company->id, 'code' => 'ENG', 'name' => 'Engineering']);
    $this->boss = app(HireEmployeeAction::class)->handle(['first_name' => 'Bhavna', 'last_name' => 'Boss', 'personal_email' => 'bhavna@home.test'], ['joining_date' => '2024-01-01', 'employee_code' => 'BOSS1'], ['company_id' => $this->company->id]);
    EmployeeBankAccount::create(['employee_id' => $this->boss->id, 'account_holder_name' => 'Bhavna', 'bank_name' => 'Bank', 'account_number' => '123456789', 'ifsc' => 'BANK0001', 'account_type' => 'savings', 'is_primary' => true]);
    $this->read = app(ApiKeys::class)->issue('reader', ['employees.read', 'organisation.read'])['plaintext'];
    $this->write = app(ApiKeys::class)->issue('writer', ['employees.read', 'employees.write'])['plaintext'];
    $this->sensitive = app(ApiKeys::class)->issue('hr-sync', ['employees.read', 'employees.sensitive.read'])['plaintext'];
    actAsTenant($this->tenantB);
    $this->keyB = app(ApiKeys::class)->issue('B', ['employees.read', 'employees.write'])['plaintext'];
    actAsTenant(null);
    auth()->logout();
});

it('creates an employee through the shared hire action with events, audit and position by codes', function () {
    Event::fake([EmploymentEvent::class]);
    $response = $this->withHeader('X-Api-Key', $this->write)->postJson('/api/v1/employees', [
        'person' => ['first_name' => 'Asha', 'last_name' => 'Rao', 'personal_email' => 'asha@home.test', 'gender' => 'female'],
        'employee' => ['joining_date' => '2026-01-05', 'work_email' => 'asha@alpha.test', 'external_reference' => 'ATS-77'],
        'position' => ['company_code' => 'alpha', 'location_code' => 'DEL', 'department_code' => 'ENG'],
        'manager_code' => 'BOSS1',
    ]);

    $response->assertCreated()->assertJsonPath('data.name', 'Asha Rao')->assertJsonPath('data.position.department.code', 'ENG')->assertJsonPath('data.manager.employee_code', 'BOSS1')->assertJsonPath('data.source', 'api')->assertJsonPath('data.external_reference', 'ATS-77')->assertJsonMissingPath('data.sensitive');
    Event::assertDispatched(EmploymentEvent::class, fn ($e) => $e->name === 'employee.created');

    actAsTenant($this->tenant);
    $asha = Employee::query()->where('work_email', 'asha@alpha.test')->first();
    expect($asha->lifecycle_state->value)->toBe('probation')->and($asha->tenant_id)->toBe($this->tenant->id);
    expect(AuditEvent::query()->where('entity_type', Employee::class)->where('entity_id', (string) $asha->id)->where('source', 'api:writer')->exists())->toBeTrue();
});

it('validates payloads, refuses duplicates with candidates, and rejects unknown codes and unscoped keys', function () {
    $this->withHeader('X-Api-Key', $this->write)->postJson('/api/v1/employees', ['person' => ['first_name' => 'X']])->assertStatus(422)->assertJsonValidationErrors(['employee.joining_date', 'position.company_code']);

    $this->withHeader('X-Api-Key', $this->write)->postJson('/api/v1/employees', [
        'person' => ['first_name' => 'Twin', 'last_name' => 'Boss', 'personal_email' => 'BHAVNA@home.test'], 'employee' => ['joining_date' => '2026-01-01'], 'position' => ['company_code' => 'ALPHA'],
    ])->assertStatus(422)->assertJsonPath('candidates.0.employee_code', 'BOSS1');

    $this->withHeader('X-Api-Key', $this->write)->postJson('/api/v1/employees', [
        'person' => ['first_name' => 'New', 'last_name' => 'Hire'], 'employee' => ['joining_date' => '2026-01-01'], 'position' => ['company_code' => 'NOPE'],
    ])->assertStatus(422)->assertJsonPath('message', "Company code 'NOPE' does not exist.");

    $this->withHeader('X-Api-Key', $this->read)->postJson('/api/v1/employees', [])->assertForbidden();
    actAsTenant($this->tenant);
    expect(Employee::query()->count())->toBe(1);
});

it('changes lifecycle through the engine and rejects invalid transitions', function () {
    $this->withHeader('X-Api-Key', $this->write)->postJson("/api/v1/employees/{$this->boss->id}/lifecycle", ['state' => 'confirmed', 'effective_date' => '2026-09-01', 'reason' => 'Review done'])
        ->assertOk()->assertJsonPath('data.lifecycle_state', 'confirmed')->assertJsonPath('data.confirmation_date', '2026-09-01');
    $this->withHeader('X-Api-Key', $this->write)->postJson("/api/v1/employees/{$this->boss->id}/lifecycle", ['state' => 'alumni', 'reason' => 'nope'])->assertStatus(422);
    $this->withHeader('X-Api-Key', $this->write)->postJson("/api/v1/employees/{$this->boss->id}/lifecycle", ['state' => 'bogus', 'reason' => 'x'])->assertStatus(422);
    $this->withHeader('X-Api-Key', $this->keyB)->postJson("/api/v1/employees/{$this->boss->id}/lifecycle", ['state' => 'active', 'reason' => 'cross tenant'])->assertNotFound();

    actAsTenant($this->tenant);
    expect(AuditEvent::query()->where('action', 'CONFIRMED')->where('entity_id', (string) $this->boss->id)->exists())->toBeTrue();
});

it('never serialises sensitive fields unless the key holds the sensitive scope, and audits such reads', function () {
    $this->withHeader('X-Api-Key', $this->read)->getJson("/api/v1/employees/{$this->boss->id}")->assertOk()
        ->assertJsonMissingPath('data.sensitive')->assertJsonMissing(['account_number' => '123456789'])->assertJsonMissing(['personal_email' => 'bhavna@home.test']);
    $this->withHeader('X-Api-Key', $this->read)->getJson("/api/v1/employees/{$this->boss->id}?include=sensitive")->assertForbidden();
    $this->withHeader('X-Api-Key', $this->read)->getJson('/api/v1/employees')->assertOk()->assertJsonMissing(['account_number' => '123456789']);

    $this->withHeader('X-Api-Key', $this->sensitive)->getJson("/api/v1/employees/{$this->boss->id}?include=sensitive")->assertOk()
        ->assertJsonPath('data.sensitive.bank_accounts.0.account_number', '123456789')->assertJsonPath('data.sensitive.personal_email', 'bhavna@home.test');

    actAsTenant($this->tenant);
    expect(AuditEvent::query()->where('action', 'VIEW')->where('entity_id', (string) $this->boss->id)->where('metadata->scope', 'api')->exists())->toBeTrue();
});

it('lists organisation reference data by code, scoped to the key tenant', function () {
    $this->withHeader('X-Api-Key', $this->read)->getJson('/api/v1/organisation/departments')->assertOk()->assertJsonPath('data.0.code', 'ENG')->assertJsonPath('meta.total', 1);
    $this->withHeader('X-Api-Key', $this->read)->getJson('/api/v1/organisation/locations?company_code=ALPHA')->assertOk()->assertJsonPath('data.0.code', 'DEL');
    $this->withHeader('X-Api-Key', $this->read)->getJson('/api/v1/organisation/nope')->assertNotFound();
    $this->withHeader('X-Api-Key', $this->write)->getJson('/api/v1/organisation/departments')->assertForbidden();
    $this->withHeader('X-Api-Key', $this->keyB)->getJson('/api/v1/employees')->assertOk()->assertJsonPath('meta.total', 0);
});
