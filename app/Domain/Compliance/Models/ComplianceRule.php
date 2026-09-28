<?php

namespace App\Domain\Compliance\Models;

use App\Domain\Identity\Models\User;
use App\Support\EffectiveDating\HasEffectiveDates;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use RuntimeException;

/**
 * A versioned statutory rule (§32, Phase 5 Part E). Platform-owned: no tenant_id, read-only for
 * tenants. A version is immutable once written: identity, dates and payload never change (a
 * correction is a new version) and the checksum proves it. Only the verification workflow
 * (RuleVerifications) moves `verification_status` through DRAFT → REVIEW → VERIFIED, and to
 * SUPERSEDED or REJECTED. `parameters` is the rule payload.
 */
#[Fillable(['jurisdiction', 'country', 'code', 'state', 'authority', 'name', 'version', 'effective_from', 'effective_to', 'parameters', 'checksum', 'source', 'source_url', 'source_title', 'source_published_date', 'status', 'verification_status', 'verified_at', 'verified_by', 'verification_notes', 'superseded_by_id', 'corrects_rule_id', 'correction_reason'])]
class ComplianceRule extends Model
{
    use HasEffectiveDates;

    public const DRAFT = 'draft';

    public const REVIEW = 'review';

    public const VERIFIED = 'verified';

    public const SUPERSEDED = 'superseded';

    public const REJECTED = 'rejected';

    public const STATUSES = [self::DRAFT, self::REVIEW, self::VERIFIED, self::SUPERSEDED, self::REJECTED];

    /** Never changed after creation. */
    public const IMMUTABLE = ['jurisdiction', 'country', 'code', 'state', 'version', 'effective_from', 'effective_to', 'parameters', 'checksum', 'name', 'corrects_rule_id', 'correction_reason'];

    protected static function booted(): void
    {
        static::creating(function (ComplianceRule $rule) {
            $rule->country ??= strtoupper((string) $rule->jurisdiction);
            $rule->verification_status ??= self::DRAFT;
            $rule->checksum = $rule->computeChecksum();
        });

        static::updating(function (ComplianceRule $rule) {
            $changed = array_intersect(array_keys($rule->getDirty()), self::IMMUTABLE);

            if ($changed !== []) {
                throw new RuntimeException('Statutory rule versions are immutable ('.implode(', ', $changed).'); publish a new version instead.');
            }
            if ($rule->getOriginal('verification_status') === self::VERIFIED && $rule->isDirty(['source_url', 'source_title', 'source_published_date', 'verified_by', 'verified_at'])) {
                throw new RuntimeException('The evidence of a verified rule cannot be changed.');
            }
        });

        static::deleting(fn () => throw new RuntimeException('Statutory rule versions are never deleted.'));
    }

    protected function casts(): array
    {
        return [
            'verified_at' => 'datetime',
            'version' => 'integer',
            'effective_from' => 'date',
            'effective_to' => 'date',
            'source_published_date' => 'date',
            'parameters' => 'array',
        ];
    }

    /**
     * Canonical checksum of a rule version's identity, dates and payload. Pure function, shared by
     * the model, the pack sync and the Phase 5 migration.
     */
    public static function checksumFor(string $jurisdiction, string $code, ?string $state, int $version, string $effectiveFrom, ?string $effectiveTo, ?array $payload): string
    {
        $canonical = static function (mixed $value) use (&$canonical): mixed {
            if (! is_array($value)) {
                return $value;
            }
            if (! array_is_list($value)) {
                ksort($value);
            }

            return array_map($canonical, $value);
        };

        $normalisedPayload = json_decode((string) json_encode($payload ?? []), true);

        return hash('sha256', (string) json_encode([
            'jurisdiction' => strtoupper($jurisdiction),
            'code' => $code,
            'state' => $state,
            'version' => $version,
            'effective_from' => Carbon::parse($effectiveFrom)->toDateString(),
            'effective_to' => $effectiveTo ? Carbon::parse($effectiveTo)->toDateString() : null,
            'payload' => $canonical($normalisedPayload),
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION));
    }

    public function computeChecksum(): string
    {
        return self::checksumFor((string) $this->jurisdiction, (string) $this->code, $this->state, (int) $this->version, (string) $this->effective_from?->toDateString(), $this->effective_to?->toDateString(), $this->parameters);
    }

    public function checksumIntact(): bool
    {
        return $this->checksum !== null && hash_equals($this->checksum, $this->computeChecksum());
    }

    public function isVerified(): bool
    {
        return $this->verification_status === self::VERIFIED;
    }

    /** Usable for calculation at all (verified or not yet decided). */
    public function isResolvable(): bool
    {
        return ! in_array($this->verification_status, [self::SUPERSEDED, self::REJECTED], true);
    }

    public function param(string $key, mixed $default = null): mixed
    {
        return data_get($this->parameters, $key, $default);
    }

    /** @return array<string, mixed> */
    public function payload(): array
    {
        return (array) $this->parameters;
    }

    public function label(): string
    {
        return $this->name.' v'.$this->version;
    }

    public function verifier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by');
    }

    public function verifications(): HasMany
    {
        return $this->hasMany(ComplianceRuleVerification::class, 'compliance_rule_id')->orderBy('id');
    }

    public function evidenceDocuments(): HasMany
    {
        return $this->hasMany(ComplianceEvidenceDocument::class, 'compliance_rule_id')->orderBy('id');
    }

    /** Coverage rows of the latest evidence submission. */
    public function coverage(): HasMany
    {
        return $this->hasMany(ComplianceRuleParameter::class, 'compliance_rule_id')->orderBy('parameter');
    }

    public function corrects(): BelongsTo
    {
        return $this->belongsTo(self::class, 'corrects_rule_id');
    }

    public function supersededBy(): BelongsTo
    {
        return $this->belongsTo(self::class, 'superseded_by_id');
    }
}
