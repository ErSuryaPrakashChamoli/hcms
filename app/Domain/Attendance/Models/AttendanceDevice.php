<?php

namespace App\Domain\Attendance\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Organisation\Enums\ActiveStatus;
use App\Domain\Organisation\Models\Location;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['tenant_id', 'name', 'code', 'adapter', 'location_id', 'settings', 'status', 'last_seen_at'])]
class AttendanceDevice extends Model
{
    use Auditable, BelongsToTenant;

    protected function casts(): array
    {
        return ['settings' => 'array', 'status' => ActiveStatus::class, 'last_seen_at' => 'datetime'];
    }

    public function auditModule(): string
    {
        return 'attendance';
    }

    public function auditLabel(): string
    {
        return "{$this->name} ({$this->code})";
    }

    public function auditExcludedAttributes(): array
    {
        return [...config('peopleos.audit.ignored_attributes', []), 'last_seen_at'];
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }
}
