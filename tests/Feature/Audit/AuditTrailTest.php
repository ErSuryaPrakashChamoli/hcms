<?php

use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Audit\Exceptions\ImmutableAuditRecordException;
use App\Domain\Audit\Models\AuditEvent;
use App\Domain\Audit\Services\AuditIntegrityVerifier;
use App\Domain\Audit\Services\AuditRecorder;
use App\Domain\Identity\Models\User;
use App\Domain\Organisation\Models\Company;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->tenant = provisionTenant();
    actAsTenant($this->tenant);
    $this->actor = tenantUser($this->tenant, ['company.*']);
    $this->actingAs($this->actor);
});

it('records a CREATE event with every non-ignored field', function () {
    $company = Company::factory()->create(['name' => 'Acme Tech', 'code' => 'ACME']);

    $event = $company->auditEvents()->first();

    expect($event)->not->toBeNull()
        ->and($event->action)->toBe(AuditAction::Create)
        ->and($event->module)->toBe('organisation')
        ->and($event->tenant_id)->toBe($this->tenant->id)
        ->and($event->actor_id)->toBe($this->actor->id)
        ->and($event->actor_name)->toBe($this->actor->name)
        ->and($event->entity_label)->toBe('Acme Tech (ACME)')
        ->and($event->source)->toBe('test')
        ->and($event->fieldChanges->pluck('after', 'field')['name'])->toBe('Acme Tech')
        ->and($event->fieldChanges->pluck('field')->all())->not->toContain('created_at', 'updated_at', 'id');
});

it('records UPDATE events as field-level before/after diffs with a reason', function () {
    $company = Company::factory()->create(['name' => 'Old Name', 'code' => 'OLD']);

    $company->withAuditReason('Rebrand', 'CR-42')->update(['name' => 'New Name', 'effective_from' => '2026-10-01']);

    $event = $company->auditEvents()->where('action', AuditAction::Update->value)->first();
    $changes = $event->fieldChanges->keyBy('field');

    expect($changes)->toHaveCount(2)
        ->and($changes['name']->before)->toBe('Old Name')
        ->and($changes['name']->after)->toBe('New Name')
        ->and($event->reason)->toBe('Rebrand')
        ->and($event->approval_reference)->toBe('CR-42')
        ->and($event->effective_date->toDateString())->toBe('2026-10-01');
});

it('does not record an UPDATE when nothing material changed', function () {
    $company = Company::factory()->create();
    $company->touch();

    expect($company->auditEvents()->where('action', AuditAction::Update->value)->count())->toBe(0);
});

it('records DELETE events', function () {
    $company = Company::factory()->create();
    $company->delete();

    expect(AuditEvent::query()->where('entity_type', Company::class)->where('entity_id', (string) $company->id)->where('action', 'DELETE')->exists())->toBeTrue();
});

it('masks sensitive attributes', function () {
    $user = tenantUser($this->tenant);
    $user->update(['password' => 'new-secret-password-123', 'name' => 'Renamed']);

    $event = $user->auditEvents()->where('action', 'UPDATE')->first();
    $fields = $event->fieldChanges->pluck('field')->all();

    // password is excluded outright; name is recorded in clear. password_changed_at changes only when
    // creation and update straddle a second boundary, so it is ignored here (Phase 6 de-flake).
    expect(array_values(array_diff($fields, ['password_changed_at'])))->toBe(['name']);

    $recorded = app(AuditRecorder::class)->record(AuditAction::View, 'people', $user, [
        ['field' => 'bank_account', 'before' => null, 'after' => '1234567890', 'sensitive' => true],
    ]);

    expect($recorded->fieldChanges->first()->after)->toBe(config('peopleos.audit.mask'))
        ->and($recorded->fieldChanges->first()->is_sensitive)->toBeTrue();
});

it('carries the request correlation id and audit source', function () {
    Context::add('request_id', 'req-123');
    Context::add('audit.source', 'admin_control_centre');

    $company = Company::factory()->create();
    $event = $company->auditEvents()->first();

    expect($event->request_id)->toBe('req-123')
        ->and($event->source)->toBe('admin_control_centre');
});

it('is append-only', function () {
    $company = Company::factory()->create();
    $event = $company->auditEvents()->first();

    expect(fn () => $event->update(['reason' => 'tampered']))->toThrow(ImmutableAuditRecordException::class)
        ->and(fn () => $event->delete())->toThrow(ImmutableAuditRecordException::class)
        ->and(fn () => $event->fieldChanges->first()->delete())->toThrow(ImmutableAuditRecordException::class);
});

it('chains hashes per tenant and detects tampering', function () {
    Company::factory()->count(3)->create();

    $verifier = app(AuditIntegrityVerifier::class);
    expect($verifier->verify($this->tenant->id)['valid'])->toBeTrue();

    $events = app(TenantContext::class)->bypass(fn () => AuditEvent::query()->where('tenant_id', $this->tenant->id)->orderBy('id')->get());
    expect($events->first()->previous_hash)->toBeNull();

    for ($i = 1; $i < $events->count(); $i++) {
        expect($events[$i]->previous_hash)->toBe($events[$i - 1]->hash);
    }

    // Tamper directly at the database level, bypassing the model guard.
    DB::table('audit_event_changes')->where('audit_event_id', $events->last()->id)->limit(1)->update(['after' => 'forged']);

    $result = $verifier->verify($this->tenant->id);
    expect($result['valid'])->toBeFalse()
        ->and($result['broken_event_id'])->toBe($events->last()->id);
});

it('is scoped per tenant when read', function () {
    Company::factory()->create();
    $other = provisionTenant('Other');

    actAsTenant($other);
    expect(AuditEvent::query()->where('entity_type', Company::class)->count())->toBe(0);

    actAsTenant($this->tenant);
    expect(AuditEvent::query()->where('entity_type', Company::class)->count())->toBe(1);
});

it('records login, failed login and logout security events', function () {
    $user = tenantUser($this->tenant, [], ['password' => 'correct-horse-battery']);
    auth()->logout();

    expect(auth()->attempt(['email' => $user->email, 'password' => 'wrong']))->toBeFalse();
    expect(AuditEvent::query()->where('action', 'LOGIN_FAILED')->where('entity_id', (string) $user->id)->exists())->toBeTrue();

    auth()->attempt(['email' => $user->email, 'password' => 'correct-horse-battery']);
    expect(AuditEvent::query()->where('action', 'LOGIN')->where('actor_id', $user->id)->exists())->toBeTrue()
        ->and($user->fresh()->last_login_at)->not->toBeNull();

    auth()->logout();
    expect(AuditEvent::query()->where('action', 'LOGOUT')->where('actor_id', $user->id)->exists())->toBeTrue();
});

it('records platform-level events without a tenant', function () {
    actAsTenant(null);
    $this->actingAs(platformAdmin());

    $before = app(AuditIntegrityVerifier::class)->verify(null);
    $event = app(AuditRecorder::class)->record(AuditAction::Export, 'platform', null, metadata: ['what' => 'blueprint']);
    $after = app(AuditIntegrityVerifier::class)->verify(null);

    expect($event->tenant_id)->toBeNull()
        ->and($event->actor_roles)->toBe(['platform-super-admin'])
        ->and($after['valid'])->toBeTrue()
        ->and($after['checked'])->toBe($before['checked'] + 1);
});

it('records the model type for users as identity module', function () {
    $user = tenantUser($this->tenant);
    expect($user->auditEvents()->first()->module)->toBe('identity');
    expect(User::class)->toBe($user->auditEvents()->first()->entity_type);
});
