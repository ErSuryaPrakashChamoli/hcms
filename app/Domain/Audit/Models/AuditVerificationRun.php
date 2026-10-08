<?php

namespace App\Domain\Audit\Models;

use App\Domain\Platform\Models\Tenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** SaaS.8: one audit-chain verification result (platform-level; append-only; written by AuditVerifications). */
#[Fillable(['subject_tenant_id', 'chain', 'valid', 'checked', 'broken_event_id', 'reason', 'trigger', 'started_at', 'finished_at', 'duration_ms'])]
class AuditVerificationRun extends Model
{
    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return ['valid' => 'boolean', 'checked' => 'integer', 'duration_ms' => 'integer', 'started_at' => 'datetime', 'finished_at' => 'datetime'];
    }

    public function subjectTenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'subject_tenant_id');
    }
}
