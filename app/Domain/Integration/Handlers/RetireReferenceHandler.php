<?php

namespace App\Domain\Integration\Handlers;

use App\Domain\Integration\Contracts\InboundEventHandler;
use App\Domain\Integration\Exceptions\IntegrationRejected;
use App\Domain\Integration\Models\ExternalReference;
use App\Domain\Integration\Models\InboundEvent;
use App\Domain\Integration\Models\IntegrationSystem;
use App\Domain\Integration\Services\ExternalReferences;

/**
 * Phase 14 built-in handler for `reference.retire`: the external system retires its own id (the
 * PeopleOS record is untouched). data: {external_entity_type, external_entity_id, reason}
 */
final class RetireReferenceHandler implements InboundEventHandler
{
    public function __construct(private readonly ExternalReferences $references) {}

    public function handle(InboundEvent $event, IntegrationSystem $system, array $data): array
    {
        $reference = ExternalReference::query()->where('integration_system_id', $system->id)
            ->where('external_entity_type', strtolower(trim((string) ($data['external_entity_type'] ?? ''))))
            ->where('external_entity_id', trim((string) ($data['external_entity_id'] ?? '')))->first()
            ?? throw new IntegrationRejected('No reference with that external id.', 'not_found');
        $this->references->retire($reference, (string) ($data['reason'] ?? 'Retired by '.$system->code));

        return ['external_reference_id' => $reference->id, 'status' => 'retired'];
    }
}
