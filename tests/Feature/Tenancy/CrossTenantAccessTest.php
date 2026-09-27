<?php

use App\Domain\Analytics\Services\DatasetRegistry;
use App\Domain\Documents\Models\DocumentType;
use App\Domain\Documents\Services\Documents;
use App\Domain\Employment\Actions\HireEmployeeAction;
use App\Domain\Integration\Services\ApiKeys;
use App\Domain\Organisation\Models\Company;
use App\Filament\Resources\Employees\EmployeeResource;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/* Phase 0.2: tenant isolation proven on the API, the export layer, file downloads and the UI, not only on the ORM. */

beforeEach(function () {
    Storage::fake('local');
    config(['peopleos.documents.disk' => 'local']);

    $this->tenantA = provisionTenant('Tenant A');
    $this->tenantB = provisionTenant('Tenant B');

    actAsTenant($this->tenantA);
    $this->hrA = tenantUser($this->tenantA, ['*']);
    $this->actingAs($this->hrA);
    $this->employeeA = app(HireEmployeeAction::class)->handle(['first_name' => 'Alice', 'last_name' => 'A'], ['joining_date' => '2025-01-01'], ['company_id' => Company::factory()->create()->id]);
    $this->keyA = app(ApiKeys::class)->issue('A reader', ['employees.read'])['plaintext'];
    $type = DocumentType::query()->first() ?? DocumentType::create(['name' => 'PAN', 'code' => 'PAN', 'category' => 'identity', 'requires_expiry' => false, 'mandatory_for_onboarding' => false, 'status' => 'active']);
    $this->docA = app(Documents::class)->store($this->employeeA, UploadedFile::fake()->create('a.pdf', 5, 'application/pdf'), $type);
    $this->docUrlA = app(Documents::class)->downloadUrl($this->docA);

    actAsTenant($this->tenantB);
    $this->hrB = tenantUser($this->tenantB, ['*']);
    $this->actingAs($this->hrB);
    $this->employeeB = app(HireEmployeeAction::class)->handle(['first_name' => 'Bob', 'last_name' => 'B'], ['joining_date' => '2025-01-01'], ['company_id' => Company::factory()->create()->id]);
    $this->keyB = app(ApiKeys::class)->issue('B reader', ['employees.read'])['plaintext'];
    actAsTenant(null);
});

it('keeps the read API inside the key\'s tenant, including direct ids', function () {
    $this->withHeader('X-Api-Key', $this->keyA)->getJson('/api/v1/employees')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.name', 'Alice A');
    $this->withHeader('X-Api-Key', $this->keyA)->getJson("/api/v1/employees/{$this->employeeB->id}")->assertNotFound();
    $this->withHeader('X-Api-Key', $this->keyB)->getJson("/api/v1/employees/{$this->employeeA->id}")->assertNotFound();
    $this->flushHeaders()->getJson("/api/v1/employees/{$this->employeeA->id}")->assertUnauthorized();
});

it('keeps exports and datasets inside the user\'s tenant', function () {
    actAsTenant($this->tenantA);
    $this->actingAs($this->hrA);
    expect(app(DatasetRegistry::class)->get('employees')->query()->pluck('id')->all())->toBe([$this->employeeA->id]);

    actAsTenant($this->tenantB);
    $this->actingAs($this->hrB);
    expect(app(DatasetRegistry::class)->get('employees')->query()->pluck('id')->all())->toBe([$this->employeeB->id]);
});

it('refuses a valid signed document link when the requester belongs to another tenant, and binds the tenant before resolving the document', function () {
    // No tenant bound before the request: ResolveTenant must run before the lookup.
    actAsTenant(null);
    $this->actingAs($this->hrA)->get($this->docUrlA)->assertOk()->assertDownload('a.pdf');

    actAsTenant(null);
    $this->actingAs($this->hrB)->get($this->docUrlA)->assertNotFound();
});

it('does not resolve another tenant\'s employee in the admin panel by id', function () {
    actAsTenant($this->tenantA);
    $this->actingAs($this->hrA)->get(EmployeeResource::getUrl('view', ['record' => $this->employeeB]))->assertNotFound();
    $this->actingAs($this->hrA)->get(EmployeeResource::getUrl('view', ['record' => $this->employeeA]))->assertOk();
});
