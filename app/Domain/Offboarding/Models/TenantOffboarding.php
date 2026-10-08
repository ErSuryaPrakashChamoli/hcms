<?php

namespace App\Domain\Offboarding\Models;

use App\Domain\Offboarding\Enums\OffboardingStatus;
use App\Domain\Platform\Models\Tenant;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** SaaS.9: one exit of a tenant from PeopleOS (TenantOffboardings). Audited explicitly on both chains. */
#[Fillable(['tenant_id', 'reference', 'status', 'open_key', 'reason', 'customer_reference', 'service_end_date', 'requested_by', 'requested_at',
    'closed_by', 'closed_at', 'closed_from_status', 'warehouse_was_enabled', 'cancelled_by', 'cancelled_at', 'cancel_reason',
    'export_waived_by', 'export_waived_at', 'export_waiver_reason', 'deletion_started_at', 'completed_at'])]
class TenantOffboarding extends Model
{
    use BelongsToTenant;

    protected function casts(): array
    {
        return ['status' => OffboardingStatus::class, 'service_end_date' => 'date', 'requested_at' => 'datetime', 'closed_at' => 'datetime',
            'cancelled_at' => 'datetime', 'export_waived_at' => 'datetime', 'deletion_started_at' => 'datetime', 'completed_at' => 'datetime',
            'warehouse_was_enabled' => 'boolean'];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function exports(): HasMany
    {
        return $this->hasMany(TenantExport::class, 'offboarding_id');
    }

    public function deletions(): HasMany
    {
        return $this->hasMany(TenantDeletion::class, 'offboarding_id');
    }
}
