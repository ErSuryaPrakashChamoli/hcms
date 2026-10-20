<?php

namespace App\Domain\Integration\Handlers;

use App\Domain\Integration\Contracts\InboundEventHandler;
use App\Domain\Integration\Exceptions\IntegrationRejected;
use App\Domain\Integration\Models\InboundEvent;
use App\Domain\Integration\Models\IntegrationSystem;
use App\Domain\Integration\Services\ExternalReferences;
use App\Domain\Integration\Support\LinkableEntities;

/**
 * Phase 14 built-in, vendor-neutral handler for `reference.link`: the external system tells PeopleOS
 * its id for a PeopleOS record identified by PeopleOS code (e.g. its employee id for employee
 * EMP-00042). Writes only the Integration Hub's own reference table.
 *
 * data: {entity_type, peopleos_code, external_entity_type, external_entity_id, external_reference?}
 */
final class LinkReferenceHandler implements InboundEventHandler
{
    public function __construct(private readonly ExternalReferences $references, private readonly LinkableEntities $entities) {}

    public function handle(InboundEvent $event, IntegrationSystem $system, array $data): array
    {
        foreach (['entity_type', 'peopleos_code', 'external_entity_type', 'external_entity_id'] as $field) {
            if (! is_scalar($data[$field] ?? null) || trim((string) $data[$field]) === '') {
                throw new IntegrationRejected("reference.link needs data.{$field}.", 'invalid_payload');
            }
        }
        $entity = $this->entities->findByCodeOrId((string) $data['entity_type'], (string) $data['peopleos_code'])
            ?? throw new IntegrationRejected('No PeopleOS record has that code.', 'not_found');
        $reference = $this->references->link($system, $entity, (string) $data['external_entity_type'], (string) $data['external_entity_id'],
            is_scalar($data['external_reference'] ?? null) ? (string) $data['external_reference'] : null, ['inbound_event_id' => $event->id]);

        return ['external_reference_id' => $reference->id, 'entity_type' => $reference->entity_type, 'entity_id' => $reference->entity_id];
    }
}
