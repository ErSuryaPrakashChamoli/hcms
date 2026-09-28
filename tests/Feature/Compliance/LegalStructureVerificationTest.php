<?php

use App\Domain\Audit\Models\AuditEvent;
use App\Domain\Compliance\Events\ComplianceEvent;
use App\Domain\Compliance\Models\StatutoryRegistration;
use App\Domain\Compliance\Services\RecordVerifications;
use App\Domain\Compliance\Services\StatutoryRegistrations;
use App\Domain\Identity\Services\AccessScopes;
use App\Domain\Organisation\Models\Company;
use App\Domain\Organisation\Models\Establishment;
use App\Domain\Organisation\Models\LegalEntity;
use Illuminate\Database\Eloquent\MassAssignmentException;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;

/* Phase 6.3: configured vs verified legal entities, establishments and registrations. */

beforeEach(function () {
    Storage::fake('local');
    $this->tenant = provisionTenant();
    actAsTenant($this->tenant);
    $this->actingAs(tenantUser($this->tenant, ['*']));
    $this->company = Company::factory()->create();
    $this->entity = LegalEntity::query()->where('company_id', $this->company->id)->sole();
    $this->establishment = Establishment::query()->where('company_id', $this->company->id)->sole();
    $this->establishment->update(['state' => 'KA']);
    $this->maker = tenantUser($this->tenant, ['establishment.view', 'establishment.update', 'legal_entity.view', 'legal_entity.update']);
    $this->checker = tenantUser($this->tenant, ['establishment.view', 'establishment.verify', 'legal_entity.view', 'legal_entity.verify']);
    $this->verifications = app(RecordVerifications::class);
});

it('starts every legal entity and establishment as configured, not verified', function () {
    expect($this->entity->fresh()->verification_status)->toBe('unverified')
        ->and($this->establishment->fresh()->verification_status)->toBe('unverified');
    // The verification columns are not mass-assignable: only RecordVerifications sets them.
    expect(fn () => $this->establishment->fill(['verification_status' => 'verified'])->save())->toThrow(MassAssignmentException::class);
    expect($this->establishment->fresh()->verification_status)->toBe('unverified');
});

it('verifies an establishment with maker-checker against its certificate, and resets on identity changes', function () {
    Event::fake([ComplianceEvent::class]);

    expect(fn () => $this->verifications->submit($this->establishment, $this->checker, 'S&E cert 123'))->toThrow(RuntimeException::class, 'establishment.update');
    expect(fn () => $this->verifications->submit($this->establishment, $this->maker, ' '))->toThrow(RuntimeException::class, 'certificate');

    $this->verifications->submit($this->establishment, $this->maker, 'Shops & Establishments certificate KA/BLR/2020/001', '%PDF certificate', 'cert.pdf');
    $establishment = $this->establishment->fresh();
    expect($establishment->verification_status)->toBe('review')
        ->and(Storage::disk('local')->exists($establishment->verification_evidence_path))->toBeTrue();

    $both = tenantUser($this->tenant, ['establishment.view', 'establishment.update', 'establishment.verify']);
    $this->verifications->submit(Establishment::query()->create(['legal_entity_id' => $this->entity->id, 'code' => 'MYS', 'name' => 'Mysuru', 'state' => 'KA', 'effective_from' => '2020-01-01']), $both, 'cert 9');
    expect(fn () => $this->verifications->verify(Establishment::query()->where('code', 'MYS')->sole(), $both, 'self'))->toThrow(RuntimeException::class, 'submitted the details');
    expect(fn () => $this->verifications->verify($establishment, $this->maker, 'x'))->toThrow(RuntimeException::class, 'establishment.verify');

    $this->verifications->verify($establishment, $this->checker, 'Name, state and address match the certificate');
    expect($establishment->fresh()->verification_status)->toBe('verified')
        ->and(AuditEvent::query()->where('action', 'ESTABLISHMENT_VERIFIED')->where('entity_id', (string) $establishment->id)->exists())->toBeTrue();
    Event::assertDispatched(ComplianceEvent::class, fn ($e) => $e->name === 'compliance.establishment_verified');

    // A non-identity change keeps the verification; an identity change resets it.
    $establishment->fresh()->update(['effective_to' => '2030-12-31']);
    expect($establishment->fresh()->verification_status)->toBe('verified');
    $establishment->fresh()->withAuditReason('Moved to Maharashtra')->update(['state' => 'MH']);
    expect($establishment->fresh()->verification_status)->toBe('unverified')
        ->and($establishment->fresh()->verified_by)->toBeNull();
});

it('verifies legal entities the same way and never outside the user\'s company scope', function () {
    $other = Company::factory()->create();
    app(AccessScopes::class)->assign($this->maker, ['company' => [$other->id]]);
    expect(fn () => $this->verifications->submit($this->entity, $this->maker, 'CIN U12345KA2020PTC000001'))->toThrow(RuntimeException::class, 'for this record');

    app(AccessScopes::class)->assign($this->maker, []);
    $this->verifications->submit($this->entity, $this->maker, 'CIN U12345KA2020PTC000001');
    $this->verifications->verify($this->entity->fresh(), $this->checker, 'Matches the certificate of incorporation');
    expect($this->entity->fresh()->verification_status)->toBe('verified')
        ->and(AuditEvent::query()->where('action', 'LEGAL_ENTITY_VERIFIED')->exists())->toBeTrue();

    $this->entity->fresh()->update(['legal_name' => 'Renamed Pvt Ltd']);
    expect($this->entity->fresh()->verification_status)->toBe('unverified');
});

it('distinguishes configured and verified registrations and resets a verification on material edits', function () {
    $registrations = app(StatutoryRegistrations::class);
    $registration = $registrations->register(['legal_entity_id' => $this->entity->id, 'establishment_id' => $this->establishment->id, 'registration_type' => 'epf_establishment_code', 'registration_number' => 'KABNG0012345000', 'effective_from' => '2020-01-01'], 'Configured', $this->maker);
    expect($registration->verification_status)->toBe('unverified');

    expect(fn () => $registrations->verify($registration, tenantUser($this->tenant, ['compliance.registrations.view']), 'EPFO allotment letter'))->toThrow(RuntimeException::class, 'compliance.registrations.manage');
    $registrations->verify($registration, tenantUser($this->tenant, ['compliance.registrations.manage']), 'EPFO code allotment letter dated 01-01-2020');
    expect($registration->fresh()->verification_status)->toBe('verified')
        ->and(AuditEvent::query()->where('action', 'REGISTRATION_VERIFIED')->exists())->toBeTrue();

    $registrations->update($registration->fresh(), ['notes' => 'Scanned copy filed'], 'Housekeeping');
    expect($registration->fresh()->verification_status)->toBe('verified');
    $registrations->update($registration->fresh(), ['jurisdiction' => 'RO Bengaluru (KR Puram)'], 'Office changed');
    expect(StatutoryRegistration::query()->find($registration->id)->verification_status)->toBe('unverified');
});
