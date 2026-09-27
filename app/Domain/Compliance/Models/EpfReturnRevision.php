<?php

namespace App\Domain\Compliance\Models;

use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

/**
 * Part H revision record: which return a revised ECR replaces, why, whether it moves amounts up or
 * down, and the attestation that no payment was initiated (EPFO allows downward revision only
 * before payment). Append-only.
 */
#[Fillable(['tenant_id', 'original_return_id', 'revised_return_id', 'reason', 'direction', 'payment_not_initiated_attested', 'attested_by', 'changes', 'created_at'])]
class EpfReturnRevision extends Model
{
    use BelongsToTenant;

    public const UPDATED_AT = null;

    protected static function booted(): void
    {
        static::updating(fn () => throw new RuntimeException('EPF revision records are append-only.'));
        static::deleting(fn () => throw new RuntimeException('EPF revision records are append-only.'));
    }

    protected function casts(): array
    {
        return ['changes' => 'array', 'payment_not_initiated_attested' => 'boolean', 'created_at' => 'datetime'];
    }

    public function original(): BelongsTo
    {
        return $this->belongsTo(StatutoryReturn::class, 'original_return_id');
    }

    public function revised(): BelongsTo
    {
        return $this->belongsTo(StatutoryReturn::class, 'revised_return_id');
    }
}
