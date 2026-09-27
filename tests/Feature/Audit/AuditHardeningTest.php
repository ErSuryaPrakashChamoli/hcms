<?php

use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Audit\Exceptions\ImmutableAuditRecordException;
use App\Domain\Audit\Models\AuditEvent;
use App\Domain\Audit\Models\AuditEventChange;
use App\Domain\Audit\Services\AuditIntegrityVerifier;
use App\Domain\Audit\Services\AuditRecorder;
use App\Domain\Organisation\Models\Company;
use App\Domain\Organisation\Models\Establishment;
use App\Domain\Organisation\Models\LegalEntity;

beforeEach(function () {
    $this->tenant = provisionTenant();
    actAsTenant($this->tenant);
    $this->admin = tenantUser($this->tenant, ['*']);
    $this->actingAs($this->admin);
});

it('groups the events of a bulk operation under one operation id and writes a summary that verifies in the chain', function () {
    $operationId = app(AuditRecorder::class)->operation('organisation', 'Bulk create companies', function () {
        Company::factory()->create(['name' => 'One']);
        Company::factory()->create(['name' => 'Two']);

        return ['succeeded' => 2, 'ids' => [1, 2]];
    }, reason: 'Import', entityType: Company::class);

    $events = AuditEvent::query()->where('operation_id', $operationId)->orderBy('id')->get();
    // Phase 5 (ADR-0001): each company also creates its legal entity and principal establishment,
    // audited inside the same operation.
    expect($events)->toHaveCount(7)
        ->and($events->where('action', AuditAction::Create)->where('entity_type', Company::class)->count())->toBe(2)
        ->and($events->where('action', AuditAction::Create)->where('entity_type', LegalEntity::class)->count())->toBe(2)
        ->and($events->where('action', AuditAction::Create)->where('entity_type', Establishment::class)->count())->toBe(2);

    $summary = $events->firstWhere('action', AuditAction::BulkOperation);
    expect($summary->metadata['entity_count'])->toBe(2)
        ->and($summary->metadata['success_count'])->toBe(2)
        ->and($summary->metadata['failure_count'])->toBe(0)
        ->and($summary->metadata['entity_type'])->toBe(Company::class)
        ->and($summary->reason)->toBe('Import')
        ->and($summary->actor_id)->toBe($this->admin->id)
        ->and($summary->tenant_id)->toBe($this->tenant->id);

    // Events recorded outside the operation carry no operation id, and older-format events still verify.
    $three = Company::factory()->create(['name' => 'Three']);
    expect(AuditEvent::query()->whereNull('operation_id')->where('entity_type', Company::class)->where('entity_id', (string) $three->id)->exists())->toBeTrue();
    expect(app(AuditIntegrityVerifier::class)->verify($this->tenant->id)['valid'])->toBeTrue();
});

it('records a failed bulk operation with a failure count and rethrows', function () {
    expect(fn () => app(AuditRecorder::class)->operation('organisation', 'Broken bulk', function () {
        Company::factory()->create(['name' => 'Partial']);
        throw new RuntimeException('boom');
    }))->toThrow(RuntimeException::class, 'boom');

    $summary = AuditEvent::query()->where('action', 'BULK_OPERATION')->latest('id')->first();
    expect($summary->metadata['failure_count'])->toBe(1)->and($summary->metadata['error'])->toBe('boom');
});

it('refuses mass updates and deletes of audit history through the query builder', function () {
    Company::factory()->create(['name' => 'Audited']);
    $event = AuditEvent::query()->latest('id')->first();

    expect(fn () => AuditEvent::query()->whereKey($event->id)->update(['reason' => 'rewritten']))->toThrow(ImmutableAuditRecordException::class);
    expect(fn () => AuditEvent::query()->whereKey($event->id)->delete())->toThrow(ImmutableAuditRecordException::class);
    expect(fn () => AuditEvent::query()->whereKey($event->id)->forceDelete())->toThrow(ImmutableAuditRecordException::class);
    expect(fn () => AuditEventChange::query()->where('audit_event_id', $event->id)->delete())->toThrow(ImmutableAuditRecordException::class);
    expect(fn () => AuditEventChange::query()->where('audit_event_id', $event->id)->update(['after' => 'x']))->toThrow(ImmutableAuditRecordException::class);
    expect(fn () => $event->forceFill(['actor_id' => null])->save())->toThrow(ImmutableAuditRecordException::class);
    expect(fn () => $event->delete())->toThrow(ImmutableAuditRecordException::class);

    expect(AuditEvent::query()->whereKey($event->id)->exists())->toBeTrue()
        ->and(app(AuditIntegrityVerifier::class)->verify($this->tenant->id)['valid'])->toBeTrue();
});

it('offers the hardening actions as first-class audit actions', function () {
    $company = Company::factory()->create(['name' => 'Acted on']);

    foreach ([AuditAction::Download, AuditAction::Archive, AuditAction::Assign, AuditAction::StatusChange] as $action) {
        $event = app(AuditRecorder::class)->record($action, 'organisation', $company, [['field' => 'status', 'before' => 'a', 'after' => 'b']], 'why');
        expect($event->action)->toBe($action)->and($event->fieldChanges)->toHaveCount(1);
    }

    expect(app(AuditIntegrityVerifier::class)->verify($this->tenant->id)['valid'])->toBeTrue();
});
