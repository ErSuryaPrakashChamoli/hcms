<?php

namespace App\Domain\Offboarding\Models;

use App\Domain\Offboarding\Enums\DeletionStatus;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** SaaS.9: a deletion of a tenant's eligible data: the reviewed plan, its maker-checker approval, its execution and certificate. */
#[Fillable(['tenant_id', 'offboarding_id', 'reference', 'status', 'in_flight_key', 'plan', 'plan_sha256', 'requested_by', 'requested_at', 'request_reason',
    'approved_by', 'approved_at', 'approval_reason', 'rejected_by', 'rejected_at', 'rejection_reason', 'cancelled_by', 'cancelled_at', 'cancel_reason',
    'scheduled_for', 'started_at', 'completed_at', 'failed_at', 'failure', 'attempts', 'heartbeat_at', 'certificate', 'certificate_sha256',
    'certificate_signature', 'certified_at'])]
class TenantDeletion extends Model
{
    use BelongsToTenant;

    protected function casts(): array
    {
        return ['status' => DeletionStatus::class, 'plan' => 'array', 'certificate' => 'array', 'requested_at' => 'datetime', 'approved_at' => 'datetime',
            'rejected_at' => 'datetime', 'cancelled_at' => 'datetime', 'scheduled_for' => 'datetime', 'started_at' => 'datetime', 'completed_at' => 'datetime',
            'failed_at' => 'datetime', 'heartbeat_at' => 'datetime', 'certified_at' => 'datetime', 'attempts' => 'integer'];
    }

    public function offboarding(): BelongsTo
    {
        return $this->belongsTo(TenantOffboarding::class, 'offboarding_id');
    }

    public function steps(): HasMany
    {
        return $this->hasMany(TenantDeletionStep::class)->orderBy('position');
    }
}
