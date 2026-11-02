<?php

namespace App\Domain\Compensation\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Identity\Models\User;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * HROne parity PO-17 (matrix 25 §25.9.2 #9): an increment plan (compensation cycle) shared with a manager. The share
 * shows the manager only the plan's lines of the people they manage (CompensationAccess::teamOf) and lets them propose
 * amounts for them while the plan is a draft; it grants nothing else. Revoked, never deleted.
 */
#[Fillable(['tenant_id', 'compensation_cycle_id', 'user_id', 'shared_by', 'shared_at', 'revoked_by', 'revoked_at'])]
class CompensationCycleShare extends Model
{
    use Auditable, BelongsToTenant;

    protected function casts(): array
    {
        return ['shared_at' => 'datetime', 'revoked_at' => 'datetime'];
    }

    public function auditModule(): string
    {
        return 'compensation';
    }

    public function auditLabel(): string
    {
        return 'Increment plan share';
    }

    public function isActive(): bool
    {
        return $this->revoked_at === null;
    }

    public function cycle(): BelongsTo
    {
        return $this->belongsTo(CompensationCycle::class, 'compensation_cycle_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
