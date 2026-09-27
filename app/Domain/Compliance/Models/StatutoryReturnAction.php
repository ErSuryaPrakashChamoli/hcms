<?php

namespace App\Domain\Compliance\Models;

use App\Domain\Identity\Models\User;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

/**
 * Part P approval / action record: who did what to a statutory return, when, for which company and
 * establishment, why, from which source (ui, api, job, cli) and against which content version.
 * Append-only; also mirrored as STATUTORY_OUTPUT_* audit events.
 */
#[Fillable(['tenant_id', 'statutory_return_id', 'company_id', 'establishment_id', 'action', 'from_status', 'to_status', 'user_id', 'reason', 'source', 'version', 'metadata', 'created_at'])]
class StatutoryReturnAction extends Model
{
    use BelongsToTenant;

    public const UPDATED_AT = null;

    protected static function booted(): void
    {
        static::updating(fn () => throw new RuntimeException('Statutory return actions are append-only.'));
        static::deleting(fn () => throw new RuntimeException('Statutory return actions are append-only.'));
    }

    protected function casts(): array
    {
        return ['metadata' => 'array', 'created_at' => 'datetime'];
    }

    public function statutoryReturn(): BelongsTo
    {
        return $this->belongsTo(StatutoryReturn::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
