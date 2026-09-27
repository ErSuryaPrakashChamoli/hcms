<?php

namespace App\Domain\Attendance\Models;

use App\Domain\Employment\Models\Employee;
use App\Domain\Identity\Concerns\ScopedByEmployee;
use App\Domain\Identity\Models\User;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A raw, normalised punch. Never edited; corrections are regularisations. */
#[Fillable(['tenant_id', 'employee_id', 'punched_at', 'direction', 'source', 'source_type', 'source_timezone', 'attendance_device_id', 'external_id', 'fingerprint', 'latitude', 'longitude', 'payload', 'received_at', 'processing_status', 'processing_error', 'processed_at', 'correlation_id', 'recorded_by', 'note'])]
class AttendancePunch extends Model
{
    use BelongsToTenant;
    use ScopedByEmployee;

    public const STATUSES = ['received', 'normalized', 'processed', 'failed', 'ignored'];

    protected function casts(): array
    {
        return ['punched_at' => 'datetime', 'received_at' => 'datetime', 'processed_at' => 'datetime', 'payload' => 'array', 'latitude' => 'decimal:7', 'longitude' => 'decimal:7'];
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
