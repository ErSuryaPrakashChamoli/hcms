<?php

namespace App\Domain\Billing\Models;

use App\Domain\Billing\Enums\ConfigurationKey;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use RuntimeException;

/**
 * SaaS.7 configuration: one version of a Markedge policy or a statutory parameter (platform-level). It is proposed
 * (pending), then approved by a second operator or rejected/withdrawn; once decided it never changes. A later
 * change is a new version from a new date, so what applied on any past day is always answerable.
 */
#[Fillable(['reference', 'domain', 'key', 'scope', 'value', 'effective_from', 'effective_to', 'version', 'status', 'reason', 'source', 'source_reference',
    'source_url', 'source_date', 'origin', 'dataset_version', 'created_by', 'approval_id', 'approved_by', 'approved_at'])]
class ConfigurationVersion extends Model
{
    use HasUlids;

    public const PENDING = 'pending';

    public const APPROVED = 'approved';

    public const REJECTED = 'rejected';

    public const WITHDRAWN = 'withdrawn';

    protected static function booted(): void
    {
        static::updating(function (self $version): void {
            $decision = $version->getRawOriginal('status') === self::PENDING && in_array($version->status, [self::APPROVED, self::REJECTED, self::WITHDRAWN], true)
                && array_diff(array_keys($version->getDirty()), ['status', 'approval_id', 'approved_by', 'approved_at', 'updated_at']) === []
                && ($version->status !== self::APPROVED || ($version->approved_by !== null && (int) $version->approved_by !== (int) $version->created_by));
            $link = $version->getRawOriginal('status') === self::PENDING && array_diff(array_keys($version->getDirty()), ['approval_id', 'updated_at']) === [];
            if (! $decision && ! $link) {
                throw new RuntimeException('A configuration version is decided once, by another operator; its value and dates never change.');
            }
        });
        static::deleting(function (): void {
            throw new RuntimeException('Configuration versions are never deleted.');
        });
    }

    public function uniqueIds(): array
    {
        return ['reference'];
    }

    protected function casts(): array
    {
        return ['value' => 'array', 'effective_from' => 'date', 'effective_to' => 'date', 'source_date' => 'date', 'approved_at' => 'datetime', 'version' => 'integer'];
    }

    public function configurationKey(): ?ConfigurationKey
    {
        return ConfigurationKey::tryFrom($this->key);
    }

    /** The stored value ({"v": …} wrapper keeps scalars JSON-safe). */
    public function typedValue(): mixed
    {
        return $this->value['v'] ?? null;
    }
}
