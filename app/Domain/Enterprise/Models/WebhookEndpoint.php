<?php

namespace App\Domain\Enterprise\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Support\Http\OutboundUrlGuard;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * An outbound webhook subscription (§87, §88): URL, HMAC secret, subscribed events.
 *
 * HROne parity SV-26: Webhooks::rotateSecret() keeps the previous secret (encrypted, never audited or exported) for a
 * grace period, during which deliveries carry a second signature made with it. Replacing the secret by editing the
 * endpoint is an immediate replacement: no previous secret is kept.
 */
#[Fillable(['tenant_id', 'name', 'url', 'secret', 'previous_secret', 'previous_secret_expires_at', 'secret_rotated_at', 'events', 'status', 'last_delivered_at', 'failure_count'])]
#[Hidden(['previous_secret'])]
class WebhookEndpoint extends Model
{
    use Auditable, BelongsToTenant;

    protected $attributes = ['status' => 'active'];

    /** Production readiness closure: a destination that can never be called is refused when saved (the full check runs per request). */
    protected static function booted(): void
    {
        static::saving(function (self $endpoint) {
            if ($endpoint->isDirty('url')) {
                app(OutboundUrlGuard::class)->staticParts((string) $endpoint->url);
            }
            // SV-26: a secret replaced outside a rotation (the edit form) replaces it at once; no grace period remains.
            if ($endpoint->exists && $endpoint->isDirty('secret') && ! $endpoint->isDirty('previous_secret')) {
                $endpoint->previous_secret = null;
                $endpoint->previous_secret_expires_at = null;
            }
        });
    }

    protected function casts(): array
    {
        return ['secret' => 'encrypted', 'previous_secret' => 'encrypted', 'previous_secret_expires_at' => 'datetime', 'secret_rotated_at' => 'datetime',
            'events' => 'array', 'last_delivered_at' => 'datetime', 'failure_count' => 'integer'];
    }

    public function auditModule(): string
    {
        return 'enterprise';
    }

    public function auditLabel(): string
    {
        return "Webhook {$this->name}";
    }

    public function auditSensitiveAttributes(): array
    {
        return ['secret'];
    }

    /** SV-26: the previous secret's ciphertext never enters the audit trail (its expiry does). */
    public function auditExcludedAttributes(): array
    {
        return [...config('peopleos.audit.ignored_attributes', []), 'previous_secret'];
    }

    public function deliveries(): HasMany
    {
        return $this->hasMany(WebhookDelivery::class)->latest('id');
    }

    public function subscribedTo(string $event): bool
    {
        return $this->status === 'active' && (in_array($event, $this->events ?? [], true) || in_array('*', $this->events ?? [], true));
    }

    /** SV-26: the previous secret while its grace period runs, else null (an expired one never signs). */
    public function previousSecretInGrace(): ?string
    {
        $attributes = $this->getAttributes();
        if (($attributes['previous_secret'] ?? null) === null || ($attributes['previous_secret_expires_at'] ?? null) === null) {
            return null;
        }

        return $this->previous_secret_expires_at->gt(now()) ? (string) $this->previous_secret : null;
    }
}
