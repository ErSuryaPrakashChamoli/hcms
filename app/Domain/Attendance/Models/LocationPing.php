<?php

namespace App\Domain\Attendance\Models;

use App\Domain\Employment\Models\Employee;
use App\Domain\Identity\Concerns\ScopedByEmployee;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A location reported by the mobile app during the working day (check-in policy: continuous location tracking). */
#[Fillable(['tenant_id', 'employee_id', 'recorded_at', 'latitude', 'longitude', 'accuracy_m', 'device_identifier'])]
class LocationPing extends Model
{
    use BelongsToTenant, ScopedByEmployee;

    protected $table = 'attendance_location_pings';

    protected function casts(): array
    {
        return ['recorded_at' => 'datetime', 'latitude' => 'float', 'longitude' => 'float', 'accuracy_m' => 'integer'];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }
}
