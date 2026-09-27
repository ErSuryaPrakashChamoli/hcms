<?php

namespace App\Domain\Compliance\Models;

use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

/** Part N: one reconciliation of a return against payroll (pre-approval) or the filing (post-filing). Append-only. */
#[Fillable(['tenant_id', 'statutory_return_id', 'stage', 'status', 'checks', 'blocking_count', 'performed_by', 'performed_at'])]
class StatutoryReconciliation extends Model
{
    use BelongsToTenant;

    public $timestamps = false;

    protected static function booted(): void
    {
        static::updating(fn () => throw new RuntimeException('Reconciliations are append-only.'));
        static::deleting(fn () => throw new RuntimeException('Reconciliations are append-only.'));
    }

    protected function casts(): array
    {
        return ['checks' => 'array', 'performed_at' => 'datetime'];
    }

    public function statutoryReturn(): BelongsTo
    {
        return $this->belongsTo(StatutoryReturn::class);
    }

    public function balanced(): bool
    {
        return $this->status === 'balanced';
    }
}
