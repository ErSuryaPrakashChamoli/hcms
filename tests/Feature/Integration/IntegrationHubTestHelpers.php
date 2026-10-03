<?php

use App\Domain\Integration\Models\IntegrationSystem;
use App\Domain\Integration\Services\ApiKeys;
use App\Domain\Integration\Services\IntegrationSystems;
use App\Domain\Integration\Support\Signature;
use App\Support\Tenancy\TenantContext;

/**
 * Phase 14: an integration registered by an integration manager, with its signing secret and an API
 * key holding the Integration Hub scopes.
 *
 * @return array{system: IntegrationSystem, secret: string, key: string}
 */
function hubIntegration(string $code = 'erp', array $data = []): array
{
    $tenant = app(TenantContext::class)->current();
    $manager = tenantUser($tenant, ['integration.manage', 'integration.view']);
    $created = app(IntegrationSystems::class)->create(['code' => $code, 'name' => strtoupper($code)] + $data, $manager);
    $key = app(ApiKeys::class)->issue('Hub '.$code, ['integrations.write', 'integrations.read']);

    return ['system' => $created['system'], 'secret' => $created['secret'], 'key' => $key['plaintext']];
}

/** Signed headers for a raw JSON body (timestamp = the application clock unless given). */
function hubHeaders(string $secret, string $body, string $apiKey, ?int $timestamp = null, array $extra = []): array
{
    $ts = (string) ($timestamp ?? now()->getTimestamp());

    return ['X-Api-Key' => $apiKey, 'X-PeopleOS-Timestamp' => $ts, 'X-PeopleOS-Signature' => Signature::sign($secret, $ts, $body), 'Content-Type' => 'application/json'] + $extra;
}

function hubEvent(string $eventId, string $type, array $data, array $extra = []): string
{
    return json_encode(['event_id' => $eventId, 'event_type' => $type, 'data' => $data] + $extra);
}
