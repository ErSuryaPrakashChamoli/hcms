<?php

namespace App\Domain\Attendance\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Employment\Models\Employee;
use App\Domain\Identity\Concerns\ScopedByEmployee;
use App\Domain\Identity\Models\User;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** An employee asks to check in from a place (HP-1 location request); approval creates their geofence. */
#[Fillable(['tenant_id', 'employee_id', 'name', 'latitude', 'longitude', 'radius_m', 'valid_from', 'valid_until', 'address', 'reason', 'status', 'requested_by', 'reviewed_by', 'reviewed_at', 'review_note', 'attendance_geo_fence_id'])]
class LocationRequest extends Model
{
    use Auditable, BelongsToTenant;
    use ScopedByEmployee;

    protected $table = 'attendance_location_requests';

    public const STATUSES = ['pending' => 'Pending', 'approved' => 'Approved', 'rejected' => 'Rejected', 'cancelled' => 'Cancelled'];

    protected $attributes = ['status' => 'pending', 'radius_m' => 200];

    protected function casts(): array
    {
        return ['latitude' => 'float', 'longitude' => 'float', 'radius_m' => 'integer', 'reviewed_at' => 'datetime', 'valid_from' => 'date', 'valid_until' => 'date'];
    }

    public function auditModule(): string
    {
        return 'attendance';
    }

    public function auditLabel(): string
    {
        return 'Location request '.$this->name;
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function fence(): BelongsTo
    {
        return $this->belongsTo(GeoFence::class, 'attendance_geo_fence_id');
    }
}
