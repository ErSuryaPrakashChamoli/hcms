<?php

namespace App\Domain\Integration\Services;

use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Audit\Services\AuditRecorder;
use App\Domain\Identity\Models\User;
use App\Domain\Integration\Exceptions\IntegrationRejected;
use App\Domain\Integration\Models\ApiKey;
use App\Domain\Integration\Models\IntegrationSystem;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Phase 14: registering external systems and their inbound signing secrets (shown once). */
final class IntegrationSystems
{
    public function __construct(private readonly AuditRecorder $audit) {}

    /**
     * @param  array<string, mixed>  $data
     * @return array{system: IntegrationSystem, secret: string} the plaintext secret is returned once
     */
    public function create(array $data, User $actor): array
    {
        $this->authorise($actor);
        $code = strtolower(trim((string) ($data['code'] ?? '')));
        if (! preg_match('/^[a-z0-9][a-z0-9_-]{1,39}$/', $code) || blank($data['name'] ?? null)) {
            throw new IntegrationRejected('An integration needs a short code (letters, digits, - or _) and a name.');
        }
        $kind = $data['kind'] ?? 'other';
        if (! array_key_exists($kind, config('peopleos.integration.kinds'))) {
            throw new IntegrationRejected('Unknown integration kind.');
        }
        $secret = $this->newSecret();

        try {
            return DB::transaction(function () use ($data, $code, $kind, $secret, $actor) {
                $system = IntegrationSystem::query()->create([
                    'code' => $code, 'name' => $data['name'], 'kind' => $kind, 'inbound_secret' => $secret, 'created_by' => $actor->id, 'secret_rotated_at' => now(),
                    'require_signature' => (bool) ($data['require_signature'] ?? true),
                    'signature_tolerance_seconds' => max(30, min(900, (int) ($data['signature_tolerance_seconds'] ?? 300))),
                    'api_key_id' => $this->apiKeyId($data['api_key_id'] ?? null),
                    'allowed_event_types' => $this->eventTypes($data['allowed_event_types'] ?? null),
                ]);

                return ['system' => $system, 'secret' => $secret];
            });
        } catch (UniqueConstraintViolationException) {
            throw new IntegrationRejected('An integration with that code already exists.', 'duplicate', 409);
        }
    }

    /** @param  array<string, mixed>  $data */
    public function update(IntegrationSystem $system, array $data, User $actor): IntegrationSystem
    {
        $this->authorise($actor);
        $changes = [];
        if (filled($data['name'] ?? null)) {
            $changes['name'] = $data['name'];
        }
        if (array_key_exists('require_signature', $data)) {
            $changes['require_signature'] = (bool) $data['require_signature'];
        }
        if (isset($data['signature_tolerance_seconds'])) {
            $changes['signature_tolerance_seconds'] = max(30, min(900, (int) $data['signature_tolerance_seconds']));
        }
        if (array_key_exists('api_key_id', $data)) {
            $changes['api_key_id'] = $this->apiKeyId($data['api_key_id']);
        }
        if (array_key_exists('allowed_event_types', $data)) {
            $changes['allowed_event_types'] = $this->eventTypes($data['allowed_event_types']);
        }
        if (isset($data['status'])) {
            if (! in_array($data['status'], ['active', 'paused', 'retired'], true)) {
                throw new IntegrationRejected('Unknown integration status.');
            }
            $changes['status'] = $data['status'];
        }
        $system->update($changes);

        return $system;
    }

    /** @return string the new plaintext secret (shown once; the old one stops working at once) */
    public function rotateSecret(IntegrationSystem $system, User $actor): string
    {
        $this->authorise($actor);
        $secret = $this->newSecret();
        $system->update(['inbound_secret' => $secret, 'secret_rotated_at' => now()]);
        $this->audit->record(AuditAction::IntegrationSecretRotated, 'integration', $system, [], null, actor: $actor);

        return $secret;
    }

    private function newSecret(): string
    {
        return 'whsec_'.Str::random(40);
    }

    private function apiKeyId(mixed $id): ?int
    {
        if (blank($id)) {
            return null;
        }

        return ApiKey::query()->whereKey((int) $id)->value('id') ?? throw new IntegrationRejected('That API key does not exist.');
    }

    /** @return list<string>|null */
    private function eventTypes(mixed $types): ?array
    {
        if (blank($types)) {
            return null;
        }
        $types = array_values(array_unique(array_filter(array_map(fn ($t) => strtolower(trim((string) $t)), (array) $types))));
        foreach ($types as $type) {
            if (! preg_match('/^[a-z0-9_.-]{1,64}$/', $type)) {
                throw new IntegrationRejected("Invalid event type [{$type}].");
            }
        }

        return $types;
    }

    private function authorise(User $actor): void
    {
        if (! $actor->hasPermission('integration.manage')) {
            throw new IntegrationRejected('This needs integration.manage.', 'forbidden', 403);
        }
    }
}
