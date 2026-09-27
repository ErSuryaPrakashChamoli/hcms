<?php

namespace App\Domain\Attendance\Services;

use App\Domain\Attendance\Adapters\BiometricAdapter;
use App\Domain\Attendance\Models\AttendanceDevice;
use App\Domain\Attendance\Models\AttendancePunch;
use App\Domain\Employment\Models\Employee;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use RuntimeException;

/** Device -> Adapter -> Raw punch -> Normalisation -> stored punch (§24). */
final class PunchIngestion
{
    /** Record one punch; duplicates (same device + external id, or same minute + direction) are ignored. */
    public function record(Employee $employee, Carbon|string $punchedAt, string $direction = 'auto', string $source = 'manual', ?AttendanceDevice $device = null, ?string $externalId = null, array $meta = [], ?string $note = null): ?AttendancePunch
    {
        $at = Carbon::parse($punchedAt);

        if ($device && $externalId && AttendancePunch::query()->where('attendance_device_id', $device->id)->where('external_id', $externalId)->exists()) {
            return null;
        }

        if (AttendancePunch::query()->where('employee_id', $employee->id)->where('direction', $direction)->whereBetween('punched_at', [$at->copy()->startOfMinute(), $at->copy()->endOfMinute()])->exists()) {
            return null;
        }

        return AttendancePunch::create([
            'employee_id' => $employee->id,
            'punched_at' => $at,
            'direction' => $direction,
            'source' => $source,
            'attendance_device_id' => $device?->id,
            'external_id' => $externalId,
            'latitude' => $meta['latitude'] ?? null,
            'longitude' => $meta['longitude'] ?? null,
            'payload' => $meta['payload'] ?? null,
            'recorded_by' => auth()->id(),
            'note' => $note,
        ]);
    }

    /**
     * Ingest a device payload through its adapter.
     *
     * @return array{accepted: int, duplicates: int, unknown: list<string>, dates: list<string>}
     */
    public function ingest(AttendanceDevice $device, array $payload): array
    {
        $adapter = $this->adapter($device->adapter);
        $result = ['accepted' => 0, 'duplicates' => 0, 'unknown' => [], 'dates' => []];
        $employees = Employee::query()->pluck('id', 'employee_code');

        foreach ($adapter->normalise($payload) as $punch) {
            $employeeId = $employees[$punch['employee_code']] ?? null;

            if ($employeeId === null || $punch['punched_at'] === '') {
                $result['unknown'][] = $punch['employee_code'] ?: '(blank)';

                continue;
            }

            $stored = $this->record(
                Employee::query()->find($employeeId),
                $punch['punched_at'],
                $punch['direction'],
                'biometric',
                $device,
                $punch['external_id'] ?? null,
                ['latitude' => $punch['latitude'] ?? null, 'longitude' => $punch['longitude'] ?? null, 'payload' => $punch],
            );

            $stored ? $result['accepted']++ : $result['duplicates']++;

            if ($stored) {
                $result['dates'][] = $stored->punched_at->toDateString();
            }
        }

        $result['unknown'] = array_values(array_unique($result['unknown']));
        $result['dates'] = array_values(array_unique($result['dates']));
        $device->forceFill(['last_seen_at' => now()])->saveQuietly();

        return $result;
    }

    /** @return Collection<int, AttendancePunch> */
    public function punchesBetween(Employee $employee, Carbon $from, Carbon $to): Collection
    {
        return AttendancePunch::query()
            ->where('employee_id', $employee->id)
            ->whereBetween('punched_at', [$from, $to])
            ->orderBy('punched_at')
            ->get();
    }

    private function adapter(string $key): BiometricAdapter
    {
        $driver = config("peopleos.attendance.adapters.{$key}.driver") ?? throw new RuntimeException("Unknown attendance adapter [{$key}].");

        return app($driver);
    }
}
