<?php

namespace App\Domain\Integration\Contracts;

use App\Domain\Integration\Models\InboundEvent;
use App\Domain\Integration\Models\IntegrationSystem;

/**
 * Phase 14: applies one inbound event type through the owning domain's own actions and services,
 * never by writing another domain's tables. It runs inside the processing transaction, so the
 * business effect and the "succeeded" state commit together. A permanent refusal throws
 * IntegrationRejected; anything else is retried.
 */
interface InboundEventHandler
{
    /**
     * @param  array<string, mixed>  $data  the event's data block
     * @return array<string, mixed> a reference to the result (entity type / id), stored on the event
     */
    public function handle(InboundEvent $event, IntegrationSystem $system, array $data): array;
}
