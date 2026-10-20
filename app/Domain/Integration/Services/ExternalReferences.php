<?php

namespace App\Domain\Integration\Services;

use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Audit\Services\AuditRecorder;
use App\Domain\Identity\Models\User;
use App\Domain\Integration\Exceptions\IntegrationRejected;
use App\Domain\Integration\Models\ExternalReference;
use App\Domain\Integration\Models\IntegrationSystem;
use App\Domain\Integration\Support\LinkableEntities;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Phase 14 (ADR-0011): links between PeopleOS records and external systems' identifiers.
 *
 * PeopleOS ids are never replaced, derived from or keyed by external ids. One external id points to
 * exactly one PeopleOS record per system and external type. Linking the same pair again is a no-op;
 * linking it to a different record is refused (never re-pointed); concurrent links are settled by the
 * unique key.
 */
final class ExternalReferences
{
    public function __construct(private readonly LinkableEntities $entities, private readonly AuditRecorder $audit) {}

    public function link(IntegrationSystem $system, Model $entity, string $externalType, string $externalId, ?string $reference = null, array $metadata = [], ?User $actor = null): ExternalReference
    {
        $alias = $this->entities->aliasFor($entity);
        [$externalType, $externalId] = $this->clean($externalType, $externalId);
        if ((int) $entity->getAttribute('tenant_id') !== (int) $system->tenant_id) {
            throw new IntegrationRejected('The record does not belong to this integration\'s tenant.', 'not_found', 404);
        }

        try {
            return DB::transaction(function () use ($system, $entity, $alias, $externalType, $externalId, $reference, $metadata, $actor) {
                $existing = $this->existing($system, $externalType, $externalId, lock: true);
                if ($existing !== null) {
                    return $this->sameTarget($existing, $alias, $entity);
                }
                $ref = ExternalReference::query()->create([
                    'integration_system_id' => $system->id, 'entity_type' => $alias, 'entity_id' => $entity->getKey(), 'external_entity_type' => $externalType,
                    'external_entity_id' => $externalId, 'external_reference' => $reference !== null ? mb_substr($reference, 0, 191) : null, 'metadata' => $metadata ?: null, 'linked_by' => $actor?->id,
                ]);
                $this->audit->record(AuditAction::ExternalReferenceLinked, 'integration', $ref, [], null, actor: $actor, metadata: ['system' => $system->code, 'entity_type' => $alias, 'external_entity_type' => $externalType]);

                return $ref;
            });
        } catch (UniqueConstraintViolationException) {
            // A concurrent link won the unique key: same target → idempotent; another target → refused.
            return $this->sameTarget($this->existing($system, $externalType, $externalId) ?? throw new IntegrationRejected('The reference could not be linked.'), $alias, $entity);
        }
    }

    /** The PeopleOS record an external id refers to (active references only). */
    public function resolve(IntegrationSystem $system, string $externalType, string $externalId): ?Model
    {
        [$externalType, $externalId] = $this->clean($externalType, $externalId);
        $ref = ExternalReference::query()->where('integration_system_id', $system->id)->where('external_entity_type', $externalType)
            ->where('external_entity_id', $externalId)->where('status', 'active')->first();

        return $ref?->entityModel();
    }

    /** @return Collection<int, ExternalReference> every system's references for one PeopleOS record */
    public function for(Model $entity): Collection
    {
        return ExternalReference::query()->with('system')->where('entity_type', $this->entities->aliasFor($entity))->where('entity_id', $entity->getKey())->orderBy('id')->get();
    }

    public function retire(ExternalReference $reference, string $reason, ?User $actor = null): ExternalReference
    {
        if (trim($reason) === '') {
            throw new IntegrationRejected('Retiring a reference needs a reason.');
        }
        if ($reference->status !== 'retired') {
            $reference->update(['status' => 'retired']);
            $this->audit->record(AuditAction::ExternalReferenceRetired, 'integration', $reference, [['field' => 'status', 'before' => 'active', 'after' => 'retired']], $reason, actor: $actor);
        }

        return $reference;
    }

    private function existing(IntegrationSystem $system, string $externalType, string $externalId, bool $lock = false): ?ExternalReference
    {
        $query = ExternalReference::query()->where('integration_system_id', $system->id)->where('external_entity_type', $externalType)->where('external_entity_id', $externalId);

        return ($lock ? $query->lockForUpdate() : $query)->first();
    }

    private function sameTarget(ExternalReference $existing, string $alias, Model $entity): ExternalReference
    {
        if ($existing->entity_type === $alias && (int) $existing->entity_id === (int) $entity->getKey()) {
            if ($existing->status !== 'active') {
                throw new IntegrationRejected('That external id was retired for this record; it is not reused.', 'reference_retired', 409);
            }

            return $existing;
        }

        throw new IntegrationRejected('That external id is already linked to another PeopleOS record.', 'external_id_taken', 409);
    }

    /** @return array{0: string, 1: string} */
    private function clean(string $externalType, string $externalId): array
    {
        $externalType = strtolower(trim($externalType));
        $externalId = trim($externalId);
        if (! preg_match('/^[a-z0-9_.-]{1,64}$/', $externalType) || $externalId === '' || mb_strlen($externalId) > 191) {
            throw new IntegrationRejected('An external reference needs an external type (letters, digits, _ . -) and an id of at most 191 characters.');
        }

        return [$externalType, $externalId];
    }
}
