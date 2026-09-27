<?php

use App\Domain\Audit\Models\AuditEvent;
use App\Domain\Compliance\Models\CompanyStatutoryProfile;
use App\Domain\Identity\Services\AccessScopes;
use App\Domain\Organisation\Models\Company;
use App\Domain\Organisation\Models\Establishment;
use App\Domain\Organisation\Models\LegalEntity;
use App\Domain\Organisation\Models\Location;
use App\Domain\Organisation\Services\LegalEntities;

beforeEach(function () {
    $this->tenant = provisionTenant();
    actAsTenant($this->tenant);
    $this->admin = tenantUser($this->tenant, ['*']);
    $this->actingAs($this->admin);
});

it('gives every new company an explicit primary legal entity and principal establishment', function () {
    $company = Company::factory()->create(['code' => 'ACME-IN', 'legal_name' => 'Acme India Private Limited', 'country_code' => 'IN']);

    $entity = LegalEntity::query()->where('company_id', $company->id)->sole();
    $establishment = Establishment::query()->where('legal_entity_id', $entity->id)->sole();

    expect($entity->legal_name)->toBe('Acme India Private Limited')
        ->and($entity->is_primary)->toBeTrue()
        ->and($entity->country)->toBe('IN')
        ->and($establishment->code)->toBe('MAIN')
        ->and($establishment->company_id)->toBe($company->id)
        ->and($establishment->is_primary)->toBeTrue();

    // Idempotent: a second pass creates nothing.
    expect(app(LegalEntities::class)->ensureFor($company)['created'])->toBeFalse()
        ->and(LegalEntity::query()->where('company_id', $company->id)->count())->toBe(1);
});

it('backfills existing companies from the legacy statutory profile, attaches locations and audits it', function () {
    $company = Company::factory()->create();
    // Simulate a pre-Phase-5 company: no legal structure yet.
    Establishment::query()->where('company_id', $company->id)->delete();
    LegalEntity::query()->where('company_id', $company->id)->delete();
    CompanyStatutoryProfile::query()->create(['company_id' => $company->id, 'pt_state' => 'MH'] + CompanyStatutoryProfile::defaults());
    $location = Location::factory()->create(['company_id' => $company->id]);

    $this->artisan('peopleos:legal-entities:backfill', ['--tenant' => $this->tenant->slug])->assertSuccessful();
    $this->artisan('peopleos:legal-entities:backfill', ['--tenant' => $this->tenant->slug])->assertSuccessful();

    $establishment = Establishment::query()->where('company_id', $company->id)->sole();
    expect($establishment->state)->toBe('MH')
        ->and($location->fresh()->establishment_id)->toBe($establishment->id)
        ->and(LegalEntity::query()->where('company_id', $company->id)->count())->toBe(1)
        ->and(AuditEvent::query()->where('entity_type', LegalEntity::class)->where('action', 'CREATE')->whereNotNull('operation_id')->exists())->toBeTrue();
});

it('derives the establishment company from its legal entity, never from input', function () {
    $a = Company::factory()->create();
    $b = Company::factory()->create();
    $entity = LegalEntity::query()->where('company_id', $a->id)->sole();

    $establishment = Establishment::query()->create(['legal_entity_id' => $entity->id, 'company_id' => $b->id, 'code' => 'PUNE', 'name' => 'Pune plant', 'state' => 'MH', 'effective_from' => '2026-01-01']);

    expect($establishment->company_id)->toBe($a->id)->and($establishment->country)->toBe($entity->country);
});

it('supports many legal entities and establishments per company', function () {
    $company = Company::factory()->create();
    $second = LegalEntity::query()->create(['company_id' => $company->id, 'code' => 'ACME-SVC', 'legal_name' => 'Acme Services LLP', 'country' => 'IN', 'effective_from' => '2026-01-01']);
    Establishment::query()->create(['legal_entity_id' => $second->id, 'code' => 'BLR', 'name' => 'Bengaluru', 'state' => 'KA', 'effective_from' => '2026-01-01']);
    Establishment::query()->create(['legal_entity_id' => $second->id, 'code' => 'MUM', 'name' => 'Mumbai', 'state' => 'MH', 'effective_from' => '2026-01-01']);

    expect($company->legalEntities()->count())->toBe(2)
        ->and($company->establishments()->count())->toBe(3)
        ->and(app(LegalEntities::class)->primaryEstablishment($company->id)->code)->toBe('MAIN');
});

it('hides other companies\' legal entities and establishments from a company-scoped user and never allows deletion', function () {
    $a = Company::factory()->create();
    $b = Company::factory()->create();
    $scoped = tenantUser($this->tenant, ['legal_entity.view', 'legal_entity.update', 'establishment.view', 'establishment.update', 'establishment.create']);
    app(AccessScopes::class)->assign($scoped, ['company' => [$a->id]]);
    $this->actingAs($scoped);

    $foreignEntity = LegalEntity::query()->withoutGlobalScopes()->where('company_id', $b->id)->sole();
    $foreignEstablishment = Establishment::query()->withoutGlobalScopes()->where('company_id', $b->id)->sole();

    expect(LegalEntity::query()->pluck('company_id')->unique()->all())->toBe([$a->id])
        ->and(Establishment::query()->pluck('company_id')->unique()->all())->toBe([$a->id])
        ->and($scoped->can('view', $foreignEntity))->toBeFalse()
        ->and($scoped->can('update', $foreignEstablishment))->toBeFalse()
        ->and($scoped->can('delete', LegalEntity::query()->where('company_id', $a->id)->sole()))->toBeFalse();

    // Creating an establishment under another company's legal entity is refused.
    expect(fn () => Establishment::query()->create(['legal_entity_id' => $foreignEntity->id, 'code' => 'X', 'name' => 'X', 'effective_from' => '2026-01-01']))
        ->toThrow(RuntimeException::class, 'legal entity');
});
