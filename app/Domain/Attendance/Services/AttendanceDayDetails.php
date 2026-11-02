<?php

namespace App\Domain\Attendance\Services;

use App\Domain\Attendance\Contracts\LeaveOnDay;
use App\Domain\Attendance\Models\AttendanceOverride;
use App\Domain\Attendance\Models\AttendancePunch;
use App\Domain\Attendance\Models\AttendanceRecord;
use App\Domain\Attendance\Models\AttendanceRegularisation;
use App\Domain\Attendance\Models\GeoFence;
use App\Domain\Employment\Models\Employee;
use App\Domain\Identity\Models\User;
use Illuminate\Support\Carbon;

/**
 * HROne parity TX-7 (matrix 24 §24.5.2 #3; shared with matrix 20 RQ-3): everything the day drawer shows about one
 * employee's day — the processed record, the punches of the day with their source, device, check-in evidence and excluded
 * mark, HR's override, the day's leave (through the LeaveOnDay contract, never leave tables), approved regularisations, the
 * shift and holiday, and whether the day is locked or in a closed attendance month. Read only, through the models' own
 * access scope; what a viewer may see of the check-in evidence (coordinates, photos, network) is decided by the screen.
 */
final class AttendanceDayDetails
{
    public function __construct(
        private readonly AttendanceProcessor $processor,
        private readonly ShiftResolver $shifts,
        private readonly HolidayResolver $holidays,
        private readonly AttendanceMonthCloses $closes,
        private readonly LeaveOnDay $leave,
    ) {}

    /** @return array<string, mixed> */
    public function day(Employee $employee, Carbon|string $date, ?User $viewer = null): array
    {
        $date = Carbon::parse($date)->startOfDay();
        $record = AttendanceRecord::query()->with('shift')->where('employee_id', $employee->id)->whereDate('date', $date->toDateString())->first();
        $resolved = $this->shifts->resolve($employee, $date);
        [$windowStart, $windowEnd] = $this->processor->windowFor($employee, $date);
        $dayStart = Carbon::parse($date->toDateString().' 00:00:00', config('app.timezone'));
        $dayEnd = $dayStart->copy()->endOfDay();
        $from = $windowStart->copy()->min($dayStart);
        $to = $windowEnd->copy()->max($dayEnd);

        $punches = AttendancePunch::query()->with(['device', 'recorder', 'excluder'])
            ->where('employee_id', $employee->id)->whereBetween('punched_at', [$from, $to])
            ->orderBy('punched_at')->orderBy('id')->get();
        $fences = ($ids = $punches->pluck('attendance_geo_fence_id')->filter()->unique()->all()) === [] ? collect() : GeoFence::query()->whereKey($ids)->pluck('name', 'id');
        $timezone = $record?->timezone ?: config('app.timezone');

        $override = AttendanceOverride::query()->with('creator')->where('employee_id', $employee->id)->whereDate('date', $date->toDateString())->first();
        $holiday = $this->holidays->holidayOn($employee, $date);
        $regularisations = AttendanceRegularisation::query()->where('employee_id', $employee->id)->whereDate('date', $date->toDateString())
            ->whereIn('status', ['pending', 'approved'])->orderBy('id')->get();

        return [
            'employee' => $employee,
            'date' => $date,
            'record' => $record,
            'status' => $record?->status,
            'status_label' => $record?->statusLabel() ?? ($date->gt(today()) ? 'Upcoming' : 'Not processed yet'),
            'timezone' => $timezone,
            'shift' => $record?->shift ?? $resolved['shift'],
            'weekly_off' => (bool) ($resolved['weekly_off'] ?? false),
            'holiday' => $record?->holiday_name ?? $holiday?->name,
            'locked' => (bool) ($record?->is_locked ?? false),
            'closed' => $this->closes->isClosedDay($employee, $date),
            'window' => [$windowStart, $windowEnd],
            'punches' => $punches->map(fn (AttendancePunch $p) => [
                'punch' => $p,
                'id' => (int) $p->id,
                'time' => $p->punched_at->copy()->setTimezone($timezone),
                'other_day' => $p->punched_at->copy()->setTimezone($timezone)->toDateString() !== $date->toDateString(),
                'in_window' => $p->punched_at->betweenIncluded($windowStart, $windowEnd),
                'direction' => (string) $p->direction,
                'source' => (string) config('peopleos.attendance.source_types.'.$p->source_type, (string) $p->source_type),
                'device' => $p->device !== null ? trim($p->device->name.' ('.$p->device->code.')') : ($p->device_identifier ?: null),
                'status' => (string) $p->processing_status,
                'counted' => ! in_array($p->processing_status, AttendancePunch::NOT_COUNTED, true),
                'excluded' => $p->isExcluded(),
                'excluded_by' => $p->excluder?->name,
                'excluded_at' => $p->excluded_at,
                'exclusion_reason' => $p->exclusion_reason,
                'rejected' => $p->processing_status === 'rejected' ? (string) $p->processing_error : null,
                'recorded_by' => $p->recorder?->name,
                'note' => $p->note,
                'geo_status' => $p->geo_status,
                'geo_location' => $p->attendance_geo_fence_id !== null ? ($fences[$p->attendance_geo_fence_id] ?? null) : null,
                'distance_m' => $p->geo_distance_m,
                'has_location' => $p->latitude !== null && $p->longitude !== null,
                'latitude' => $p->latitude !== null ? round((float) $p->latitude, 5) : null,
                'longitude' => $p->longitude !== null ? round((float) $p->longitude, 5) : null,
                'photo_count' => count(array_filter((array) ($p->photos ?? []))),
                'face_verified' => $p->face_verified,
                'ip_address' => $p->ip_address,
                'wifi' => $p->wifi_ssid,
            ])->values()->all(),
            'override' => $override,
            'leave' => $this->leave->onDay($employee, $date, $viewer),
            'regularisations' => $regularisations,
        ];
    }
}
