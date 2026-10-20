<?php

namespace App\Domain\Workflow\Services;

use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Audit\Services\AuditRecorder;
use App\Domain\Identity\Models\User;
use App\Domain\Integration\Support\Signature;
use App\Domain\Workflow\Models\Workflow;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;

/**
 * SaaS.2: workflow webhook nodes sign every request, with the same scheme as PeopleOS outbound webhooks
 * (Integration\Support\Signature):
 *
 *   X-PeopleOS-Event: workflow.webhook
 *   X-PeopleOS-Delivery: <ULID, the same on every retry of one delivery, so receivers can drop duplicates>
 *   X-PeopleOS-Timestamp: <unix seconds>
 *   X-PeopleOS-Signature: sha256=<hex HMAC-SHA256(secret, "<timestamp>.<raw body>")>
 *
 * Receivers verify in constant time, refuse timestamps older than 5 minutes and remember delivery ids.
 * Each workflow has its own secret (encrypted at rest), created when its first webhook is sent, shown only
 * to people who can edit the workflow (audited) and rotated on demand (audited). Configured headers cannot
 * override the signature headers.
 */
final class WorkflowWebhookSigning
{
    public function __construct(private readonly AuditRecorder $audit) {}

    /** The workflow's secret, created once (first writer wins) if it does not exist yet. */
    public function secretFor(Workflow $workflow): string
    {
        $stored = Workflow::query()->whereKey($workflow->getKey())->value('webhook_signing_secret');
        if (filled($stored)) {
            return $stored;
        }
        $secret = Str::random(48);
        $claimed = Workflow::query()->whereKey($workflow->getKey())->whereNull('webhook_signing_secret')
            ->update(['webhook_signing_secret' => Crypt::encryptString($secret)]);
        if ($claimed === 0) {
            return (string) Workflow::query()->whereKey($workflow->getKey())->value('webhook_signing_secret');
        }
        $this->audit->record(AuditAction::SigningSecretRotated, 'workflow', $workflow, [], null, metadata: ['secret' => 'workflow_webhook', 'event' => 'generated']);

        return $secret;
    }

    /** Shows the secret to someone configuring a receiver; the viewing is audited. */
    public function reveal(Workflow $workflow, User $by): string
    {
        $secret = $this->secretFor($workflow);
        $this->audit->record(AuditAction::View, 'workflow', $workflow, [], null, actor: $by, metadata: ['secret' => 'workflow_webhook', 'event' => 'revealed']);

        return $secret;
    }

    /** Replaces the secret at once; receivers must switch to the new one. */
    public function rotate(Workflow $workflow, User $by, string $reason): string
    {
        $secret = Str::random(48);
        Workflow::query()->whereKey($workflow->getKey())->update(['webhook_signing_secret' => Crypt::encryptString($secret)]);
        $this->audit->record(AuditAction::SigningSecretRotated, 'workflow', $workflow, [], $reason, actor: $by, metadata: ['secret' => 'workflow_webhook', 'event' => 'rotated']);

        return $secret;
    }

    /**
     * @param  array<string, string>  $configured  headers from the node configuration
     * @return array<string, string>
     */
    public function headers(array $configured, string $secret, string $deliveryId, string $body, ?int $now = null): array
    {
        $timestamp = (string) ($now ?? now()->getTimestamp());
        $own = collect($configured)->reject(fn ($value, $name) => str_starts_with(strtolower((string) $name), 'x-peopleos-'))->all();

        return $own + [
            'X-PeopleOS-Event' => 'workflow.webhook',
            'X-PeopleOS-Delivery' => $deliveryId,
            'X-PeopleOS-Timestamp' => $timestamp,
            'X-PeopleOS-Signature' => Signature::sign($secret, $timestamp, $body),
        ];
    }
}
