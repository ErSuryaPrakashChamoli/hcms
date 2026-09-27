<?php

namespace App\Domain\Assets\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Employment\Models\Employee;
use App\Domain\Identity\Concerns\ScopedByEmployee;
use App\Domain\Identity\Models\User;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Custody of an asset by an employee: active until returned. The exit clearance (Phase 13) reads active rows. */
#[Fillable(['tenant_id', 'asset_id', 'employee_id', 'assigned_on', 'expected_return_on', 'returned_on', 'condition_out', 'condition_in', 'acknowledged_at', 'status', 'note', 'return_note', 'assigned_by'])]
class AssetAssignment extends Model
{
    use Auditable, BelongsToTenant;
    use ScopedByEmployee;

    protected $attributes = ['status' => 'active'];

    protected function casts(): array
    {
        return ['assigned_on' => 'date', 'expected_return_on' => 'date', 'returned_on' => 'date', 'acknowledged_at' => 'datetime'];
    }

    public function auditModule(): string
    {
        return 'assets';
    }

    public function auditLabel(): string
    {
        return 'Assignment from '.$this->assigned_on?->toDateString();
    }

    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function assigner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_by');
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }
}
