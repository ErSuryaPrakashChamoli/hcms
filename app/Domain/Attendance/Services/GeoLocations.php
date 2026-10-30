<?php

namespace App\Domain\Attendance\Services;

use App\Domain\Attendance\Events\AttendanceEvent;
use App\Domain\Attendance\Models\EmployeeDevice;
use App\Domain\Attendance\Models\GeoFence;
use App\Domain\Attendance\Models\LocationRequest;
use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Audit\Services\AuditRecorder;
use App\Domain\Configuration\Services\PolicyResolver;
use App\Domain\Employment\Models\Employee;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Scopes\AccessScope;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Geo work locations and the requests for them (HROne parity HP-1), and the review of check-in devices.
 * An approved location request becomes a personal geo location of the employee.
 */
final class GeoLocations
{
    public function __construct(private readonly AuditRecorder $audit, private readonly PolicyResolver $policies) {}

    /** @param  array{name: string, latitude: float|string, longitude: float|string, radius_m?: int, address?: ?string}  $place */
    public function request(Employee $employee, array $place, string $reason, ?User $requester = null): LocationRequest
    {
        $this->assertPlace($place);
        if (trim($reason) === '') {
            throw new RuntimeException('Give a reason for the location.');
        }
        if (LocationRequest::query()->where('employee_id', $employee->id)->where('status', 'pending')->count() >= 5) {
            throw new RuntimeException('You already have five location requests waiting.');
        }
        [$from, $until] = $this->validity($employee, $place);

        $request = new LocationRequest([
            'employee_id' => $employee->id, 'name' => $place['name'], 'latitude' => (float) $place['latitude'], 'longitude' => (float) $place['longitude'],
            'radius_m' => (int) ($place['radius_m'] ?? 200), 'valid_from' => $from, 'valid_until' => $until, 'address' => $place['address'] ?? null, 'reason' => $reason, 'status' => 'pending',
            'requested_by' => ($requester ?? auth()->user())?->id,
        ]);
        $request->withAuditReason($reason)->save();
        AttendanceEvent::dispatch('attendance.location_requested', $employee, $request, ['name' => $request->name, 'reason' => $reason]);

        return $request;
    }

    public function approve(LocationRequest $request, ?string $note = null, ?User $actor = null): LocationRequest
    {
        $actor ??= auth()->user();

        return DB::transaction(function () use ($request, $note, $actor) {
            $locked = $this->decidable($request, $actor);
            $fence = GeoFence::create([
                'name' => $locked->name, 'employee_id' => $locked->employee_id, 'latitude' => $locked->latitude, 'longitude' => $locked->longitude,
                'radius_m' => $locked->radius_m, 'valid_from' => $locked->valid_from, 'valid_until' => $locked->valid_until,
                'address' => $locked->address, 'source' => 'request', 'status' => 'active', 'created_by' => $actor?->id,
            ]);
            $locked->withAuditReason($note)->update(['status' => 'approved', 'reviewed_by' => $actor?->id, 'reviewed_at' => now(), 'review_note' => $note, 'attendance_geo_fence_id' => $fence->id]);
            $this->audit->record(AuditAction::Approved, 'attendance', $locked, reason: $note, actor: $actor);
            AttendanceEvent::dispatch('attendance.location_approved', $locked->employee, $locked, ['name' => $locked->name]);

            return $locked;
        });
    }

    public function reject(LocationRequest $request, string $note, ?User $actor = null): LocationRequest
    {
        if (trim($note) === '') {
            throw new RuntimeException('Give the reason for rejecting.');
        }
        $actor ??= auth()->user();

        return DB::transaction(function () use ($request, $note, $actor) {
            $locked = $this->decidable($request, $actor);
            $locked->withAuditReason($note)->update(['status' => 'rejected', 'reviewed_by' => $actor?->id, 'reviewed_at' => now(), 'review_note' => $note]);
            $this->audit->record(AuditAction::Rejected, 'attendance', $locked, reason: $note, actor: $actor);
            AttendanceEvent::dispatch('attendance.location_rejected', $locked->employee, $locked, ['name' => $locked->name, 'note' => $note]);

            return $locked;
        });
    }

    public function cancel(LocationRequest $request, string $reason): LocationRequest
    {
        if ($request->status !== 'pending') {
            throw new RuntimeException('Only pending requests can be withdrawn.');
        }
        $request->withAuditReason($reason)->update(['status' => 'cancelled']);

        return $request;
    }

    /** Decide a check-in device: approved, blocked, or back to pending. */
    public function reviewDevice(EmployeeDevice $device, string $status, ?string $reason = null, ?User $actor = null): EmployeeDevice
    {
        if (! array_key_exists($status, EmployeeDevice::STATUSES)) {
            throw new RuntimeException("Unknown device status [{$status}].");
        }
        $actor ??= auth()->user();

        return DB::transaction(function () use ($device, $status, $reason, $actor) {
            $locked = EmployeeDevice::query()->whereKey($device->getKey())->lockForUpdate()->firstOrFail();
            if ($status === 'approved' && EmployeeDevice::query()->withoutGlobalScope(AccessScope::class)->where('device_identifier', $locked->device_identifier)->where('employee_id', '!=', $locked->employee_id)->where('status', 'approved')->exists()) {
                throw new RuntimeException('This device is already approved for another employee; block it there first.');
            }
            $locked->withAuditReason($reason)->update(['status' => $status, 'reviewed_by' => $actor?->id, 'reviewed_at' => now()]);

            return $locked;
        });
    }

    /**
     * HROne setup parity: the dates a requested location is approved for. Optional dates are kept as given; when the
     * employee's check-in policy approves locations only for a period, an end date is required within its maximum.
     *
     * @param  array<string, mixed>  $place
     * @return array{0: ?string, 1: ?string}
     */
    private function validity(Employee $employee, array $place): array
    {
        $from = filled($place['valid_from'] ?? null) ? Carbon::parse($place['valid_from'])->toDateString() : null;
        $until = filled($place['valid_until'] ?? null) ? Carbon::parse($place['valid_until'])->toDateString() : null;
        $policy = $this->policies->resolve('checkin', $employee, now());
        if (in_array($policy?->setting('temporary_locations'), [true, 1, '1'], true)) {
            $from ??= now()->toDateString();
            $max = max(1, (int) $policy->setting('temporary_location_max_days', 30));
            if ($until === null) {
                throw new RuntimeException("Give the last day you need this location (up to {$max} days).");
            }
            if (Carbon::parse($from)->diffInDays(Carbon::parse($until)) + 1 > $max) {
                throw new RuntimeException("A location can be approved for at most {$max} days.");
            }
        }
        if ($from !== null && $until !== null && $until < $from) {
            throw new RuntimeException('The last day cannot be before the first day.');
        }

        return [$from, $until];
    }

    /** @param  array<string, mixed>  $place */
    public function assertPlace(array $place): void
    {
        if (blank($place['name'] ?? null)) {
            throw new RuntimeException('Name the location.');
        }
        $lat = $place['latitude'] ?? null;
        $lng = $place['longitude'] ?? null;
        if (! is_numeric($lat) || ! is_numeric($lng) || abs((float) $lat) > 90 || abs((float) $lng) > 180) {
            throw new RuntimeException('Give a valid latitude (−90 to 90) and longitude (−180 to 180).');
        }
        if (($place['shape'] ?? 'circle') === 'polygon') {
            $points = array_values((array) ($place['polygon'] ?? []));
            if (count($points) < 3) {
                throw new RuntimeException('A polygon needs at least three boundary points.');
            }
            foreach ($points as $p) {
                [$pLat, $pLng] = isset($p['latitude']) ? [$p['latitude'], $p['longitude'] ?? null] : [$p[0] ?? null, $p[1] ?? null];
                if (! is_numeric($pLat) || ! is_numeric($pLng) || abs((float) $pLat) > 90 || abs((float) $pLng) > 180) {
                    throw new RuntimeException('Every boundary point needs a valid latitude and longitude.');
                }
            }

            return;
        }
        $radius = (int) ($place['radius_m'] ?? 200);
        if ($radius < 20 || $radius > 20000) {
            throw new RuntimeException('The radius must be between 20 m and 20 km.');
        }
    }

    private function decidable(LocationRequest $request, ?User $actor): LocationRequest
    {
        if ($actor === null) {
            throw new RuntimeException('A location request is decided by a person.');
        }
        $locked = LocationRequest::query()->whereKey($request->getKey())->lockForUpdate()->firstOrFail();
        if ($locked->status !== 'pending') {
            throw new RuntimeException('This request has already been decided.');
        }
        if (Employee::query()->withoutGlobalScope(AccessScope::class)->whereKey($locked->employee_id)->value('user_id') === $actor->id) {
            throw new RuntimeException('You cannot decide your own request.');
        }
        $locked->load('employee');

        return $locked;
    }
}
