<?php

namespace App\Domain\Attendance\Models;

use App\Domain\Employment\Models\Employee;
use App\Domain\Identity\Models\User;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A raw, normalised punch. Never edited; corrections are regularisations. */
#[Fillable(['tenant_id', 'employee_id', 'punched_at', 'direction', 'source', 'attendance_device_id', 'external_id', 'latitude', 'longitude', 'payload', 'recorded_by', 'note'])]
class AttendancePunch extends Model
{
    use BelongsToTenant;

    protected function casts(): array
    {
        return ['punched_at' => 'datetime', 'payload' => 'array', 'latitude' => 'decimal:7', 'longitude' => 'decimal:7'];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function device(): BelongsTo
    {
        return $this->belongsTo(AttendanceDevice::class, 'attendance_device_id');
    }

    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }
}
