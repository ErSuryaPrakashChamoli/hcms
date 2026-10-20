<?php

namespace App\Domain\Integration\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Identity\Models\User;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Phase 14: an external system a tenant integrates with (recruitment, payroll provider, devices,
 * finance, identity, benefits, learning, BGV, another HR system…). Generic: no vendor adapter lives
 * here. Inbound events are signed with the system's own secret (HMAC-SHA256 over "timestamp.body")
 * and may be restricted to one API key and a list of event types.
 */
#[Fillable(['tenant_id', 'code', 'name', 'kind', 'status', 'inbound_secret', 'require_signature', 'signature_tolerance_seconds', 'api_key_id', 'allowed_event_types', 'settings', 'created_by', 'secret_rotated_at'])]
#[Hidden(['inbound_secret'])]
class IntegrationSystem extends Model
{
    use Auditable, BelongsToTenant;

    protected $attributes = ['status' => 'active', 'kind' => 'other', 'require_signature' => true, 'signature_tolerance_seconds' => 300];

    protected static function booted(): void
    {
        static::saving(fn (self $s) => $s->code = strtolower(trim((string) $s->code)));
        static::deleting(fn () => throw new \RuntimeException('Integration systems are retired, never deleted (their events and references are history).'));
    }

    protected function casts(): array
    {
        return [
            'inbound_secret' => 'encrypted', 'require_signature' => 'boolean', 'signature_tolerance_seconds' => 'integer',
            'allowed_event_types' => 'array', 'settings' => 'array', 'secret_rotated_at' => 'datetime',
        ];
    }

    public function auditModule(): string
    {
        return 'integration';
    }

    public function auditLabel(): string
    {
        return "Integration {$this->name} ({$this->code})";
    }

    public function auditExcludedAttributes(): array
    {
        return [...config('peopleos.audit.ignored_attributes', []), 'inbound_secret'];
    }

    public function apiKey(): BelongsTo
    {
        return $this->belongsTo(ApiKey::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function references(): HasMany
    {
        return $this->hasMany(ExternalReference::class);
    }

    public function mappings(): HasMany
    {
        return $this->hasMany(IntegrationMapping::class);
    }

    public function inboundEvents(): HasMany
    {
        return $this->hasMany(InboundEvent::class);
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    public function accepts(string $eventType): bool
    {
        return empty($this->allowed_event_types) || in_array($eventType, $this->allowed_event_types, true);
    }
}
