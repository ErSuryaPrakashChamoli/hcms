<?php

namespace App\Domain\Integration\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Identity\Models\User;
use App\Domain\Integration\Support\IpAllowlist;
use App\Domain\Organisation\Enums\ActiveStatus;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Integration credential. The secret is shown once at creation; only its hash is kept.
 *
 * HROne parity CA-11: an optional per-key IP allowlist (empty = any address) and rate limit (null = the API-wide limit);
 * SV-26: a regenerated key names the key it replaced (regenerated_from_id).
 */
#[Fillable(['tenant_id', 'name', 'description', 'prefix', 'secret_hash', 'scopes', 'allowed_ips', 'rate_limit_per_minute', 'last_used_at', 'expires_at', 'status', 'created_by', 'regenerated_from_id'])]
#[Hidden(['secret_hash'])]
class ApiKey extends Model
{
    use Auditable, BelongsToTenant;

    protected function casts(): array
    {
        return [
            'scopes' => 'array',
            'allowed_ips' => 'array',
            'rate_limit_per_minute' => 'integer',
            'last_used_at' => 'datetime',
            'expires_at' => 'datetime',
            'status' => ActiveStatus::class,
        ];
    }

    public function auditModule(): string
    {
        return 'integration';
    }

    public function auditLabel(): string
    {
        return "{$this->name} ({$this->prefix})";
    }

    public function auditExcludedAttributes(): array
    {
        return [...config('peopleos.audit.ignored_attributes', []), 'secret_hash', 'last_used_at'];
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** SV-26: the key this one replaced when it was regenerated. */
    public function regeneratedFrom(): BelongsTo
    {
        return $this->belongsTo(self::class, 'regenerated_from_id');
    }

    public function requestLogs(): HasMany
    {
        return $this->hasMany(ApiRequestLog::class);
    }

    public function hasScope(string $scope): bool
    {
        return in_array($scope, $this->scopes ?? [], true) || in_array('*', $this->scopes ?? [], true);
    }

    public function isUsable(): bool
    {
        return $this->status === ActiveStatus::Active && ($this->expires_at === null || $this->expires_at->isFuture());
    }

    /** @return list<string> CA-11: the allowlist entries (empty = any address). */
    public function ipAllowlist(): array
    {
        $raw = $this->getAttributes()['allowed_ips'] ?? null;

        return $raw === null ? [] : IpAllowlist::normalise((array) $this->allowed_ips);
    }

    /** CA-11: no list (or an empty one) = any address; otherwise the address must match an entry (fails closed). */
    public function allowsIp(?string $ip): bool
    {
        return IpAllowlist::allows($this->ipAllowlist(), $ip);
    }

    /** CA-11: this key's requests per minute (its own limit, or the API-wide one). */
    public function rateLimitPerMinute(): int
    {
        $own = $this->getAttributes()['rate_limit_per_minute'] ?? null;

        return $own !== null && (int) $own > 0 ? (int) $own : (int) config('peopleos.api.rate_limit_per_minute');
    }
}
