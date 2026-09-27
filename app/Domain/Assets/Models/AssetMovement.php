<?php

namespace App\Domain\Assets\Models;

use App\Domain\Employment\Models\Employee;
use App\Domain\Identity\Models\User;
use App\Domain\Organisation\Models\Location;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Append-only history of what happened to an asset (§38 lifecycle). */
#[Fillable(['tenant_id', 'asset_id', 'type', 'from_employee_id', 'to_employee_id', 'from_location_id', 'to_location_id', 'status_after', 'note', 'occurred_at', 'created_by'])]
class AssetMovement extends Model
{
    use BelongsToTenant;

    protected function casts(): array
    {
        return ['occurred_at' => 'datetime'];
    }

    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }

    public function fromEmployee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'from_employee_id');
    }

    public function toEmployee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'to_employee_id');
    }

    public function fromLocation(): BelongsTo
    {
        return $this->belongsTo(Location::class, 'from_location_id');
    }

    public function toLocation(): BelongsTo
    {
        return $this->belongsTo(Location::class, 'to_location_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
