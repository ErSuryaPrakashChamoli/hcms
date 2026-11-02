<?php

namespace App\Domain\Attendance\Models;

use App\Domain\Employment\Models\Employee;
use App\Domain\Identity\Concerns\ScopedByEmployee;
use App\Domain\Identity\Models\User;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A raw, normalised punch. Never edited; corrections are regularisations.
 *
 * HROne parity TX-7 (D-TX5): never deleted either. HR excludes a wrong punch with a reason (processing_status `excluded`,
 * PunchCorrections): it is kept as evidence, with its device data untouched, and processing skips it; "Restore" puts the
 * status it had back. Rejected and excluded punches are the ones not counted (NOT_COUNTED).
 */
#[Fillable(['tenant_id', 'employee_id', 'punched_at', 'direction', 'source', 'source_type', 'source_timezone', 'attendance_device_id', 'external_id', 'fingerprint', 'latitude', 'longitude', 'payload', 'received_at', 'processing_status', 'processing_error', 'processed_at', 'correlation_id', 'recorded_by', 'note', 'geo_status', 'attendance_geo_fence_id', 'geo_distance_m', 'device_identifier', 'photos', 'face_verified', 'ip_address', 'wifi_ssid', 'wifi_bssid', 'excluded_at', 'excluded_by', 'exclusion_reason', 'status_before_exclusion'])]
class AttendancePunch extends Model
{
    use BelongsToTenant;
    use ScopedByEmployee;

    public const STATUSES = ['received', 'normalized', 'processed', 'failed', 'ignored', 'excluded'];

    /** HROne parity TX-7: punches kept as evidence but never counted (refused at check-in, or excluded by HR). */
    public const NOT_COUNTED = ['rejected', 'excluded'];

    protected function casts(): array
    {
        return ['punched_at' => 'datetime', 'received_at' => 'datetime', 'processed_at' => 'datetime', 'payload' => 'array', 'latitude' => 'decimal:7', 'longitude' => 'decimal:7', 'photos' => 'array', 'face_verified' => 'boolean', 'excluded_at' => 'datetime'];
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

    /** HROne parity TX-7: who excluded the punch. */
    public function excluder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'excluded_by');
    }

    public function isExcluded(): bool
    {
        return $this->processing_status === 'excluded';
    }
}
